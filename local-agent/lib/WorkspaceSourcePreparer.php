<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (v1.4.2, isolated build workspace): populates a fresh
 * BuildWorkspace with EXACTLY the published commit's tracked source, plus
 * a self-contained copy of every Composer path-repository dependency the
 * project declares — never a Junction/symlink pointing back at the real
 * developer working tree, never anything gitignored (.env, storage,
 * caches). Nothing from this point on (composer install, npm ci/build,
 * the final artifact zip) ever reads from or writes to the real project
 * directory again — see BuildWorkspace's own docblock for why.
 *
 * Order of operations:
 * 1. Race-guard: confirm the working tree is still exactly at the commit
 *    the caller captured right after push. This is the ONE moment this
 *    class touches the live working tree at all — everything after
 *    operates purely on the isolated, already-snapshotted workspace.
 * 2. `git archive` that exact commit to a temp zip, extract it into the
 *    workspace's project directory (SafeFilesystem — ZIP-Slip guarded).
 * 3. Read composer.json FROM THE ARCHIVED COPY (not the live working
 *    tree — the exact same commit-fidelity reasoning as step 2) for any
 *    `"repositories": [{"type": "path", "url": "..."}]` entries. A
 *    declared path that resolves to somewhere already inside the
 *    checked-out tree (a monorepo package tracked by this same commit,
 *    e.g. `packages/*`) needs nothing further — the checkout above
 *    already put it there, at the exact requested commit. Only a path
 *    that resolves OUTSIDE the checked-out tree (a genuinely external
 *    sibling, never tracked by this repository) is copied in from its
 *    real, live location next to the persistent source workspace — see
 *    BuildWorkspace::resolvePathDependencyTarget(). Generic: works for
 *    however many path repositories a project declares, wherever they
 *    point, without hardcoding any project's own package names or paths.
 */
final class WorkspaceSourcePreparer
{
    private readonly DependencyRepositoryResolver $repositories;
    private readonly RetryPolicy $retryPolicy;
    private readonly RetryableGitFetch $fetchRetrier;
    /** @var \Closure(array<int,string>,string,int,?callable):array{exitCode:int,output:string,timedOut:bool}|null */
    private readonly ?\Closure $fetchRunner;

    public function __construct(
        private readonly GitManager $git,
        private readonly int $archiveTimeoutSeconds = 300,
        ?DependencyRepositoryResolver $repositories = null,
        ?RetryPolicy $retryPolicy = null,
        private readonly ?Logger $logger = null,
        /**
         * @var (callable(array<int,string>,string,int,?callable):array{exitCode:int,output:string,timedOut:bool})|null
         *     Test-only seam — see RetryableGitFetch::run()'s own docblock.
         */
        ?callable $fetchRunner = null,
    ) {
        $this->repositories = $repositories ?? new DependencyRepositoryResolver($git);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->fetchRetrier = new RetryableGitFetch($git, $this->retryPolicy, $logger);
        $this->fetchRunner = $fetchRunner === null ? null : \Closure::fromCallable($fetchRunner);
    }

    /**
     * @return array{commitTimestamp: int, sourcePaths: array<int, string>}
     */
    public function prepare(BuildWorkspace $workspace, string $repositoryPath, string $commitHash, ?callable $onTick = null): array
    {
        $actualHead = $this->git->lastCommitHash($repositoryPath);
        if (!hash_equals($commitHash, $actualHead)) {
            throw new \RuntimeException("Refusing to build an artifact: expected HEAD to be {$commitHash} but it is {$actualHead} — the working tree changed during the build.");
        }

        $tempArchivePath = $this->archiveCommitToZip($repositoryPath, $commitHash, $onTick);
        try {
            SafeFilesystem::extractZipSafely($tempArchivePath, $workspace->projectDir());
        } finally {
            @unlink($tempArchivePath);
        }

        $sourcePaths = self::sourcePaths($workspace->projectDir());
        $this->copyPathDependencies($workspace, $repositoryPath);

        return ['commitTimestamp' => $this->commitTimestamp($repositoryPath, $commitHash), 'sourcePaths' => $sourcePaths];
    }

    /**
     * @return array{commitTimestamp: int, sourcePaths: array<int, string>, dependencyLocks: array<int, array<string, mixed>>}
     */
    public function prepareRemoteCommit(BuildWorkspace $workspace, string $repositoryPath, string $repositoryUrl, string $remoteName, string $branch, string $commitHash, ?callable $onTick = null, array $dependencyContract = [], array $dependencyAllowlist = [], array $repositoryMappings = [], string $repositoryMapping = 'primary-project'): array
    {
        if (SafetyChecker::productionSourcePathForbidden($repositoryPath)) {
            throw new LocalBuildFailedException('production_source_forbidden', "Production target '{$repositoryPath}' cannot be used as a Git or artifact source.");
        }

        $transport = $this->repositories->resolveTransport([
            'repository_url' => $repositoryUrl,
            'source_workspace_path' => $repositoryPath,
            'remote_name' => $remoteName,
            'branch' => $branch,
        ], $repositoryMapping, $branch);
        $this->assertTrustedPersistentWorkspace($repositoryPath, $branch);
        $this->assertLocalBranchNotDiverged($repositoryPath, $remoteName, $branch);

        $context = [
            'mapping' => $repositoryMapping,
            'branch' => $branch,
            'identity' => $transport['identity'],
            'transport' => $transport['category'],
        ];
        $this->initializeWorkspaceRepository($workspace->projectDir(), $transport['url'], $onTick);
        $this->fetchWithRetry(['git', 'fetch', '--no-tags', '--prune', 'origin', $branch], $workspace->projectDir(), $this->git->timeoutFor('fetch'), 'fetching the authoritative branch', $onTick, $context);
        $this->runOrFail(['git', 'cat-file', '-e', "{$commitHash}^{commit}"], $workspace->projectDir(), 30, 'validating the requested commit', $onTick);
        $this->runOrFail(['git', 'merge-base', '--is-ancestor', $commitHash, 'FETCH_HEAD'], $workspace->projectDir(), 30, 'validating commit reachability', $onTick);
        $this->runOrFail(['git', 'checkout', '--detach', '--force', $commitHash], $workspace->projectDir(), 60, 'checking out the exact commit', $onTick);
        $this->assertCheckedOutAtExactCommit($workspace->projectDir(), $commitHash);
        $sourcePaths = self::sourcePaths($workspace->projectDir());

        $dependencyLocks = [];
        $pinningRequired = ($dependencyContract['required'] ?? false) === true;
        $dependencies = $dependencyContract['dependencies'] ?? [];
        if ($pinningRequired || $dependencies !== []) {
            if (($dependencyContract['version'] ?? null) !== 2 || !is_array($dependencies) || ($pinningRequired && $dependencies === [])) {
                throw new LocalBuildFailedException('local_workspace_prepare_failed', 'A valid dependency contract v2 is required for this project.');
            }
            $composerPaths = self::parsePathRepositoryUrls($workspace->projectDir() . '/composer.json');
            $requestedPaths = array_map(static fn (array $dependency): string => (string) ($dependency['destination_path'] ?? ''), $dependencies);
            sort($composerPaths, SORT_STRING);
            sort($requestedPaths, SORT_STRING);
            if ($composerPaths !== $requestedPaths) {
                throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Dependency contract must pin every Composer path repository exactly once.');
            }
            $dependencyLocks = (new PinnedDependencyMaterializer($this->git, null, $this->retryPolicy, $this->logger))->materialize($workspace, $dependencies, $dependencyAllowlist, $repositoryMappings, $onTick);
        } else {
            // Contract v1 compatibility only. Projects opted into pinning never reach this branch.
            $this->copyPathDependencies($workspace, $repositoryPath);
        }

        return ['commitTimestamp' => $this->commitTimestamp($workspace->projectDir(), $commitHash), 'sourcePaths' => $sourcePaths, 'dependencyLocks' => $dependencyLocks];
    }

    /**
     * Captures the immutable source-file boundary before dependencies or build
     * output are materialized. The list is embedded in the sealed release
     * manifest so the Server Agent can refuse a persistence mapping that
     * would hide tracked application source (for example `database/`).
     *
     * @return array<int, string>
     */
    private static function sourcePaths(string $projectDir): array
    {
        $root = rtrim(str_replace('\\', '/', $projectDir), '/');
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($projectDir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => $entry->getFilename() !== '.git',
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $absolute = str_replace('\\', '/', $entry->getPathname());
            if (!str_starts_with($absolute, $root . '/')) {
                throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Tracked source inventory escaped the isolated build workspace.');
            }
            $paths[] = substr($absolute, strlen($root) + 1);
        }

        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * Last self-consistency check before anything ever reads composer.json
     * or copies a path repository out of the isolated workspace: proves
     * the checkout that just ran actually landed on the exact requested
     * commit, not merely "some commit". Fails closed rather than letting a
     * silently wrong checkout (e.g. a `git checkout` that partially failed
     * without a non-zero exit code) build an artifact from the wrong code.
     */
    private function assertCheckedOutAtExactCommit(string $projectDir, string $commitHash): void
    {
        $checkedOut = $this->git->lastCommitHash($projectDir);
        if (!hash_equals($commitHash, $checkedOut)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Workspace checkout mismatch: expected {$commitHash}, got {$checkedOut}.");
        }
    }

    private function assertTrustedPersistentWorkspace(string $repositoryPath, string $branch): void
    {
        if (!is_dir($repositoryPath)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace '{$repositoryPath}' does not exist.");
        }
        if (!$this->validBranch($branch)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Configured branch '{$branch}' contains unsupported characters.");
        }

        $inside = $this->git->run(['git', 'rev-parse', '--is-inside-work-tree'], $repositoryPath, $this->git->timeoutFor('status'));
        if ($inside['exitCode'] !== 0 || trim($inside['output']) !== 'true') {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace '{$repositoryPath}' is not a Git working tree.");
        }

        $actualBranch = $this->git->currentBranch($repositoryPath);
        if (!hash_equals($branch, $actualBranch)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace branch mismatch: expected '{$branch}', found '{$actualBranch}'.");
        }

        $changedFiles = $this->git->changedFiles($repositoryPath);
        if ($changedFiles !== []) {
            $sample = implode(', ', array_slice($changedFiles, 0, 5));
            throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Source workspace is dirty; refusing to fetch or build. Changed paths: ' . $sample);
        }
    }

    private function assertLocalBranchNotDiverged(string $repositoryPath, string $remoteName, string $branch): void
    {
        $remoteRef = "refs/remotes/{$remoteName}/{$branch}";
        $result = $this->git->run(['git', 'merge-base', '--is-ancestor', 'HEAD', $remoteRef], $repositoryPath, 30);
        if ($result['exitCode'] !== 0) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace branch has diverged from {$remoteName}/{$branch}; refusing to reset or discard local commits.");
        }
    }

    private function validBranch(string $branch): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $branch) === 1
            && !str_contains($branch, '..')
            && !str_contains($branch, '@{')
            && !str_ends_with($branch, '/')
            && !str_ends_with($branch, '.');
    }

    private function initializeWorkspaceRepository(string $target, string $remoteUrl, ?callable $onTick): void
    {
        $this->runOrFail(['git', 'init'], $target, 30, 'initializing the isolated Git workspace', $onTick);
        $this->runOrFail(['git', 'remote', 'add', 'origin', $remoteUrl], $target, 30, 'configuring the authoritative remote', $onTick);
    }

    /** @param array{mapping?: string, branch?: string, identity?: string, transport?: string} $context */
    private function runOrFail(array $command, string $cwd, int $timeout, string $operation, ?callable $onTick, array $context = []): void
    {
        $result = $this->git->run($command, $cwd, $timeout, $onTick);
        if ($result['exitCode'] !== 0) {
            throw $this->materializationFailure($operation, $context, $result);
        }
    }

    /**
     * Root-cause fix (Release #50/#53 primary-repository materialization
     * failures): the ONE network-exposed step in this whole method — see
     * RetryableGitFetch's own docblock for the full retry/logging
     * contract. Every other git command here (init, remote add, cat-file,
     * merge-base, checkout) stays exactly as single-attempt as before;
     * only the actual branch fetch retries a classified-transient
     * failure. The externally-compatible failure message on final
     * failure is byte-identical to what runOrFail() has always produced —
     * only the LAST attempt's result is ever surfaced there.
     *
     * @param array{mapping?: string, branch?: string, identity?: string, transport?: string} $context
     */
    private function fetchWithRetry(array $command, string $cwd, int $timeout, string $operation, ?callable $onTick, array $context = []): void
    {
        $result = $this->fetchRetrier->run($command, $cwd, $timeout, $onTick, $operation, $context, $this->fetchRunner);
        if ($result['exitCode'] !== 0) {
            throw $this->materializationFailure($operation, $context, $result);
        }
    }

    /**
     * @param array{mapping?: string, branch?: string, identity?: string, transport?: string} $context
     * @param array{exitCode: int, output: string, timedOut: bool} $result
     */
    private function materializationFailure(string $operation, array $context, array $result): LocalBuildFailedException
    {
        $timedOut = ($result['timedOut'] ?? false) === true;
        $details = "exit_code={$result['exitCode']}, timed_out=" . ($timedOut ? 'true' : 'false')
            . ', stderr_category=' . DependencyRepositoryResolver::stderrCategory((string) ($result['output'] ?? ''), $timedOut);
        if ($context !== []) {
            $details = "mapping={$context['mapping']}, branch={$context['branch']}, repository_identity={$context['identity']}, transport={$context['transport']}, " . $details;
        }

        return new LocalBuildFailedException('local_workspace_prepare_failed', "Primary repository materialization failed while {$operation} [{$details}].");
    }

    private function archiveCommitToZip(string $repositoryPath, string $commitHash, ?callable $onTick): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'la-archive-') . '.zip';
        @unlink($archivePath); // tempnam() creates it; git archive -o refuses to write over some filesystems' quirks otherwise

        $result = $this->git->run(
            ['git', 'archive', '--format=zip', '-o', $archivePath, $commitHash],
            $repositoryPath,
            $this->archiveTimeoutSeconds,
            $onTick,
        );

        if ($result['exitCode'] !== 0) {
            @unlink($archivePath);

            throw new \RuntimeException("git archive failed for commit {$commitHash} in {$repositoryPath}: " . trim($result['output']));
        }

        $size = @filesize($archivePath);
        if ($size === false || $size === 0) {
            @unlink($archivePath);

            throw new \RuntimeException("git archive produced an empty or unreadable file for commit {$commitHash} in {$repositoryPath}.");
        }

        return $archivePath;
    }

    private function commitTimestamp(string $repositoryPath, string $commitHash): int
    {
        $result = $this->git->run(['git', 'show', '-s', '--format=%ct', $commitHash], $repositoryPath, 30);

        $timestamp = (int) trim($result['exitCode'] === 0 ? $result['output'] : '0');

        return $timestamp > 0 ? $timestamp : time();
    }

    private function copyPathDependencies(BuildWorkspace $workspace, string $repositoryPath): void
    {
        $composerJsonPath = $workspace->projectDir() . '/composer.json';
        if (!is_file($composerJsonPath)) {
            return;
        }

        foreach (self::parsePathRepositoryUrls($composerJsonPath) as $url) {
            $this->copyOnePathDependency($workspace, $repositoryPath, $url);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function parsePathRepositoryUrls(string $composerJsonPath): array
    {
        $decoded = json_decode((string) @file_get_contents($composerJsonPath), true);
        if (!is_array($decoded) || !is_array($decoded['repositories'] ?? null)) {
            return [];
        }

        $urls = [];
        foreach ($decoded['repositories'] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && is_string($repository['url'] ?? null) && $repository['url'] !== '') {
                $urls[] = $repository['url'];
            }
        }

        return array_values(array_unique($urls));
    }

    private function copyOnePathDependency(BuildWorkspace $workspace, string $repositoryPath, string $url): void
    {
        // Composer itself supports glob patterns in path repository URLs
        // (e.g. "../packages/*") — not handled here (none of this
        // project's own audited path repositories use one); a project
        // that starts using one would fail clearly at the
        // "does not exist" check below rather than silently resolving
        // wrong, since a literal glob string is never a real directory.
        if (self::looksAbsolute($url)) {
            throw new LocalBuildFailedException(
                'local_workspace_prepare_failed',
                "composer.json declares an absolute path repository ('{$url}') — the isolated build workspace only supports relative path repositories (relative to composer.json), so it can mirror them at the same relative position inside the workspace.",
            );
        }

        $target = $workspace->resolvePathDependencyTarget($url);

        // A path repository declared relative to composer.json (no `..`
        // escaping the project root) may live INSIDE the same Git tree as
        // the rest of the project (a monorepo package under e.g.
        // packages/*) rather than in a genuinely external sibling
        // directory. When that's the case, the exact-commit checkout
        // already placed it at this exact target as part of the commit
        // that was just verified and checked out above — it is already a
        // faithful, self-contained copy of the requested commit, so there
        // is nothing to copy and nothing to validate against any other
        // location. Only a path repository that resolves OUTSIDE the
        // checked-out tree (a real external sibling, never tracked by
        // this commit) needs to be copied in from the live persistent
        // source workspace.
        if (is_dir($target)) {
            return;
        }

        $realSource = realpath(rtrim($repositoryPath, '\\/') . '/' . $url);
        if ($realSource === false || !is_dir($realSource)) {
            throw new LocalBuildFailedException(
                'local_workspace_prepare_failed',
                "composer.json declares a path repository ('{$url}') that does not exist at " . rtrim($repositoryPath, '\\/') . '/' . $url . ' — cannot build a self-contained artifact without it.',
            );
        }

        SafeFilesystem::copyRecursiveSafely($realSource, $realSource, $target);
    }

    private static function looksAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
