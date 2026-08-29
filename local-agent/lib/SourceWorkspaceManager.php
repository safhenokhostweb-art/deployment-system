<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Reconciles Hub-managed source workspaces independently of Publish Jobs.
 *
 * A healthy checkout is reused without any network operation. Missing
 * checkouts are cloned into a sibling temporary directory and atomically
 * renamed into place. A genuinely invalid checkout (not a Git repository,
 * or pointed at the wrong repository) is quarantined (never deleted)
 * before the same atomic clone is attempted; if cloning fails, the original
 * path is restored.
 *
 * A checkout that IS a correct, correctly-remoted Git repository but simply
 * sits on a branch other than the one the Hub expects is a third, distinct
 * case — these are real developer-owned workspaces (source_workspace_path
 * points directly at the developer's own working directory, e.g. under
 * C:\laragon\www\), and a developer legitimately checking out a different
 * branch to review/audit is not corruption. That case is never quarantined,
 * recloned, checked out, reset, or otherwise touched here — it is preserved
 * exactly as found and reported as 'preserved' so the caller can skip any
 * operation that requires the expected branch. This does NOT relax what an
 * Artifact build is allowed to build from: WorkspaceSourcePreparer::
 * prepareRemoteCommit() (the only path agent.php ever builds an artifact
 * through) independently re-verifies the live checked-out branch via its
 * own assertTrustedPersistentWorkspace() immediately before every build,
 * regardless of what this class classified the workspace as at sync time.
 */
final class SourceWorkspaceManager
{
    public function __construct(
        private readonly GitManager $git,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Reconcile every project independently so one unavailable repository
     * cannot prevent other mappings from becoming usable.
     *
     * @param array<string, array<string, mixed>> $projects
     * @return array<string, string> project key => created|reused|repaired|preserved|failed|skipped
     */
    public function reconcile(array $projects): array
    {
        $results = [];

        foreach ($projects as $projectKey => $project) {
            $repositoryUrl = $project['repository_url'] ?? null;
            if (!is_string($repositoryUrl) || trim($repositoryUrl) === '') {
                // Legacy config.php/cache entries predate repository_url and
                // cannot be bootstrapped without inventing a remote source.
                $results[(string) $projectKey] = 'skipped';
                continue;
            }

            try {
                $results[(string) $projectKey] = $this->reconcileProject((string) $projectKey, $project);
            } catch (\Throwable $e) {
                $results[(string) $projectKey] = 'failed';
                $this->logger->error("Source workspace reconciliation failed for '{$projectKey}': " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $project
     * @return string created|reused|repaired|preserved
     */
    public function reconcileProject(string $projectKey, array $project): string
    {
        $path = $project['source_workspace_path'] ?? $project['local_path'] ?? null;
        $repositoryUrl = $project['repository_url'] ?? null;
        $remoteName = $project['remote_name'] ?? 'origin';
        $branch = $project['branch'] ?? null;

        if (!is_string($path) || !$this->isAbsolutePath($path)) {
            throw new \RuntimeException('source_workspace_path must be a non-empty absolute path.');
        }
        if (!is_string($repositoryUrl) || trim($repositoryUrl) === '' || str_starts_with($repositoryUrl, '-')) {
            throw new \RuntimeException('repository_url must be a non-empty Git repository URL.');
        }
        if (!is_string($remoteName) || preg_match('/^[A-Za-z0-9._-]+$/', $remoteName) !== 1) {
            throw new \RuntimeException('remote_name contains unsupported characters.');
        }
        if (!is_string($branch) || !$this->validBranch($branch)) {
            throw new \RuntimeException('branch is empty or contains unsupported characters.');
        }

        $path = $this->normalizePath($path);
        if ($path === '/' || preg_match('/^[A-Za-z]:\/$/', $path) === 1) {
            throw new \RuntimeException('source_workspace_path cannot be a filesystem root.');
        }
        $this->assertNonProductionPath($path);

        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new \RuntimeException("Could not create source workspace parent '{$parent}'.");
        }
        if (!is_writable($parent)) {
            throw new \RuntimeException("Source workspace parent '{$parent}' is not writable by the Local Agent user; correct its ownership before retrying.");
        }

        $existed = $this->pathExists($path);
        if ($existed) {
            $resolved = realpath($path);
            if ($resolved !== false) {
                $this->assertNonProductionPath($resolved);
            }

            $this->configureSafeDirectory($path, $parent);
            if ($this->isExpectedCheckout($path, $repositoryUrl, $remoteName, $branch)) {
                $sha = $this->git->lastCommitHash($path);
                $this->logger->info("Source workspace reused for '{$projectKey}' at {$path} ({$branch} @ {$sha}).");

                return 'reused';
            }

            // A correct, correctly-remoted repository on an unexpected
            // branch is a real developer workspace mid-work, not a broken
            // checkout — never quarantine/reclone/switch it merely for
            // that. See the class docblock for why this cannot be used to
            // build the wrong source: WorkspaceSourcePreparer re-checks the
            // live branch independently at build time.
            if ($this->isValidRepositoryCheckout($path, $repositoryUrl, $remoteName)) {
                $actualBranch = $this->git->currentBranch($path);
                $this->logger->warn("Source workspace for '{$projectKey}' at {$path} is on branch '{$actualBranch}', expected '{$branch}' — preserving it unchanged. Operations that require the expected branch are unavailable for this project until it matches.");

                return 'preserved';
            }
        }

        $suffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path)) ?: 'project';
        $temporaryPath = $parent . DIRECTORY_SEPARATOR . '.workspace-clone-' . $baseName . '-' . $suffix;
        $quarantinePath = $parent . DIRECTORY_SEPARATOR . '.workspace-repair-' . $baseName . '-' . $suffix;
        $quarantined = false;

        if ($existed) {
            if (!rename($path, $quarantinePath)) {
                throw new \RuntimeException("Workspace '{$path}' is invalid and could not be moved to a safe repair quarantine.");
            }
            $quarantined = true;
            $this->logger->warn("Invalid source workspace for '{$projectKey}' moved to recoverable quarantine {$quarantinePath}.");
        }

        try {
            $cloneErrors = [];
            $cloned = false;
            $cloneUrls = [$repositoryUrl];
            $sshFallback = $this->githubSshFallback($repositoryUrl);
            if ($sshFallback !== null) {
                $cloneUrls[] = $sshFallback;
            }

            foreach ($cloneUrls as $cloneUrl) {
                if ($this->pathExists($temporaryPath)) {
                    $this->removeGeneratedClone($temporaryPath, $parent);
                }
                $clone = $this->git->run([
                    'git', 'clone', '--origin', $remoteName, '--branch', $branch,
                    '--single-branch', '--', $cloneUrl, $temporaryPath,
                ], $parent, $this->git->timeoutFor('pull'));
                if ($clone['exitCode'] === 0) {
                    // Git URL rewrite rules may otherwise persist their local
                    // replacement in remote.origin.url. Record the transport
                    // that actually succeeded so subsequent syncs can compare
                    // the canonical repository identity deterministically.
                    $remoteSet = $this->git->run(
                        ['git', 'remote', 'set-url', $remoteName, $cloneUrl],
                        $temporaryPath,
                        $this->git->timeoutFor('status'),
                    );
                    if ($remoteSet['exitCode'] !== 0) {
                        throw new \RuntimeException('could not record the successful clone remote: ' . trim($remoteSet['output']));
                    }
                    $cloned = true;
                    break;
                }
                $cloneErrors[] = trim($clone['output']);
            }

            if (!$cloned) {
                throw new \RuntimeException('git clone failed: ' . implode("\nFallback clone failed: ", $cloneErrors));
            }

            if (!$this->isExpectedCheckout($temporaryPath, $repositoryUrl, $remoteName, $branch)) {
                throw new \RuntimeException('cloned repository failed remote, branch, or HEAD verification.');
            }
            if (!is_writable($temporaryPath)) {
                throw new \RuntimeException('cloned workspace is not writable by the Local Agent user.');
            }
            if (!rename($temporaryPath, $path)) {
                throw new \RuntimeException("Could not atomically activate cloned workspace at '{$path}'.");
            }

            // Register the authoritative final path only; the temporary
            // clone is owned by this process and never needs a persistent
            // safe.directory entry.
            $this->configureSafeDirectory($path, $parent);
            $sha = $this->git->lastCommitHash($path);
            $state = $quarantined ? 'repaired' : 'created';
            $this->logger->info("Source workspace {$state} for '{$projectKey}' at {$path} ({$branch} @ {$sha}).");

            return $state;
        } catch (\Throwable $e) {
            // A partial clone is generated data, so remove it; restore an
            // existing invalid workspace to its original path so repair is
            // reversible and retries never accumulate duplicate clones.
            if ($this->pathExists($temporaryPath)) {
                try {
                    $this->removeGeneratedClone($temporaryPath, $parent);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warn("Could not remove failed generated clone '{$temporaryPath}': " . $cleanupError->getMessage());
                }
            }
            if ($quarantined && !$this->pathExists($path) && $this->pathExists($quarantinePath)) {
                @rename($quarantinePath, $path);
            }

            throw $e;
        }
    }

    private function isExpectedCheckout(string $path, string $repositoryUrl, string $remoteName, string $branch): bool
    {
        if (!$this->isValidRepositoryCheckout($path, $repositoryUrl, $remoteName)) {
            return false;
        }

        try {
            return $this->git->currentBranch($path) === $branch
                && preg_match('/^[0-9a-f]{40}$/', $this->git->lastCommitHash($path)) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Branch-independent half of isExpectedCheckout(): is this a real,
     * writable Git working tree pointed at the correct repository? A path
     * that fails this check is genuinely invalid (not a Git repo, wrong
     * repository, or inaccessible) and remains eligible for the existing
     * quarantine-then-reclone repair. A path that PASSES this check but is
     * on the wrong branch is not — see reconcileProject()'s 'preserved'
     * branch.
     */
    private function isValidRepositoryCheckout(string $path, string $repositoryUrl, string $remoteName): bool
    {
        if (!is_dir($path) || !is_writable($path)) {
            return false;
        }

        $inside = $this->git->run(['git', 'rev-parse', '--is-inside-work-tree'], $path, $this->git->timeoutFor('status'));
        if ($inside['exitCode'] !== 0 || trim($inside['output']) !== 'true') {
            return false;
        }

        // Read the stored value directly. `git remote get-url` expands
        // url.*.insteadOf rules and can hide the repository identity that is
        // actually recorded in this workspace.
        $remote = $this->git->run(['git', 'config', '--get', 'remote.' . $remoteName . '.url'], $path, $this->git->timeoutFor('status'));

        return $remote['exitCode'] === 0 && $this->normalizeRepository((string) $remote['output']) === $this->normalizeRepository($repositoryUrl);
    }

    private function configureSafeDirectory(string $path, string $cwd): void
    {
        $normalized = $this->normalizePath($path);
        $current = $this->git->run(['git', 'config', '--global', '--get-all', 'safe.directory'], $cwd, $this->git->timeoutFor('status'));
        $entries = preg_split('/\r?\n/', trim($current['output'])) ?: [];

        foreach ($entries as $entry) {
            if ($entry !== '' && $this->normalizePath($entry) === $normalized) {
                return;
            }
        }

        $added = $this->git->run(['git', 'config', '--global', '--add', 'safe.directory', $normalized], $cwd, $this->git->timeoutFor('status'));
        if ($added['exitCode'] !== 0) {
            throw new \RuntimeException("Could not register '{$normalized}' as a Git safe.directory: " . trim($added['output']));
        }
    }

    private function assertNonProductionPath(string $path): void
    {
        if (SafetyChecker::productionSourcePathForbidden($path)) {
            throw new \RuntimeException("Refusing to manage a source workspace at production-like path '{$path}'.");
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        $path = trim($path);

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function validBranch(string $branch): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $branch) === 1
            && !str_contains($branch, '..')
            && !str_contains($branch, '@{')
            && !str_ends_with($branch, '/')
            && !str_ends_with($branch, '.');
    }

    private function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $drive = preg_match('/^([A-Za-z]:)(?:\/|$)/', $normalized, $matches) === 1 ? $matches[1] : null;
        $absolute = str_starts_with($normalized, '/');
        $tail = $drive === null ? $normalized : substr($normalized, strlen($drive));
        $segments = [];
        foreach (explode('/', $tail) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $prefix = $drive ?? ($absolute ? '' : null);
        if ($prefix === null) {
            return implode('/', $segments);
        }

        return $prefix . '/' . implode('/', $segments);
    }

    private function normalizeRepository(string $repository): string
    {
        $normalized = trim(str_replace('\\', '/', $repository));
        if (preg_match('/^git@([^:]+):(.+)$/i', $normalized, $matches) === 1) {
            $host = strtolower($matches[1]);
            // SSH config aliases such as "github-safhe" still identify
            // github.com; compare the canonical GitHub repository identity,
            // not the machine-local transport alias.
            if ($host === 'github.com' || $host === 'github-safhe') {
                return 'github.com/' . strtolower(preg_replace('#\.git/?$#i', '', $matches[2]) ?? $matches[2]);
            }

            $normalized = $host . '/' . $matches[2];
        }

        $parts = parse_url($normalized);
        if (is_array($parts) && in_array(strtolower((string) ($parts['host'] ?? '')), ['github.com', 'ssh.github.com'], true)) {
            $path = ltrim((string) ($parts['path'] ?? ''), '/');

            return 'github.com/' . strtolower(preg_replace('#\.git/?$#i', '', $path) ?? $path);
        }

        return preg_replace('#\.git/?$#i', '', rtrim($normalized, '/')) ?? $normalized;
    }

    private function githubSshFallback(string $repository): ?string
    {
        $parts = parse_url(trim($repository));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
            return null;
        }

        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$#', $path) !== 1) {
            return null;
        }

        return 'ssh://git@ssh.github.com:443/' . $path;
    }

    private function removeGeneratedClone(string $path, string $parent): void
    {
        if (dirname($path) !== $parent || !str_starts_with(basename($path), '.workspace-clone-')) {
            throw new \RuntimeException('Refusing to remove a path that is not a generated workspace clone.');
        }

        SafeFilesystem::recursiveDelete($path);
        if ($this->pathExists($path)) {
            throw new \RuntimeException('the generated clone could not be fully removed');
        }
    }
}
