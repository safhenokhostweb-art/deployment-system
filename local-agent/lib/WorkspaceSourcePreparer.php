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
 *    `"repositories": [{"type": "path", "url": "..."}]` entries, and
 *    copy each referenced directory from the real, live location (path
 *    repository URLs are resolved relative to the real project
 *    directory, since that's the only place they actually exist) into
 *    the workspace at the SAME relative position — see
 *    BuildWorkspace::resolvePathDependencyTarget(). Generic: works for
 *    however many path repositories a project declares, wherever they
 *    point, without hardcoding any project's own package names or paths.
 */
final class WorkspaceSourcePreparer
{
    public function __construct(
        private readonly GitManager $git,
        private readonly int $archiveTimeoutSeconds = 300,
    ) {
    }

    /**
     * @return array{commitTimestamp: int}
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

        $this->copyPathDependencies($workspace, $repositoryPath);

        return ['commitTimestamp' => $this->commitTimestamp($repositoryPath, $commitHash)];
    }

    /**
     * @return array{commitTimestamp: int}
     */
    public function prepareRemoteCommit(BuildWorkspace $workspace, string $repositoryPath, string $repositoryUrl, string $remoteName, string $branch, string $commitHash, ?callable $onTick = null, array $dependencyContract = [], array $dependencyAllowlist = [], array $repositoryMappings = []): array
    {
        if (SafetyChecker::productionSourcePathForbidden($repositoryPath)) {
            throw new LocalBuildFailedException('production_source_forbidden', "Production target '{$repositoryPath}' cannot be used as a Git or artifact source.");
        }

        $this->assertTrustedPersistentWorkspace($repositoryPath, $repositoryUrl, $remoteName, $branch);
        $this->fetchBranch($repositoryPath, $remoteName, $branch, $onTick);
        $this->assertLocalBranchNotDiverged($repositoryPath, $remoteName, $branch);
        $this->assertCommitExists($repositoryPath, $commitHash);
        $this->assertCommitReachableFromRemoteBranch($repositoryPath, $commitHash, $remoteName, $branch);

        $remoteUrl = $this->remoteUrl($repositoryPath, $remoteName);
        $this->initializeWorkspaceRepository($workspace->projectDir(), $remoteUrl, $onTick);
        $this->runOrFail(['git', 'fetch', '--prune', 'origin', $branch], $workspace->projectDir(), $this->git->timeoutFor('fetch'), 'fetching the authoritative branch', $onTick);
        $this->runOrFail(['git', 'cat-file', '-e', "{$commitHash}^{commit}"], $workspace->projectDir(), 30, 'validating the requested commit', $onTick);
        $this->runOrFail(['git', 'merge-base', '--is-ancestor', $commitHash, "refs/remotes/origin/{$branch}"], $workspace->projectDir(), 30, 'validating commit reachability', $onTick);
        $this->runOrFail(['git', 'checkout', '--detach', '--force', $commitHash], $workspace->projectDir(), 60, 'checking out the exact commit', $onTick);

        $checkedOut = $this->git->lastCommitHash($workspace->projectDir());
        if (!hash_equals($commitHash, $checkedOut)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Workspace checkout mismatch: expected {$commitHash}, got {$checkedOut}.");
        }

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
            $dependencyLocks = (new PinnedDependencyMaterializer($this->git))->materialize($workspace, $dependencies, $dependencyAllowlist, $repositoryMappings, $onTick);
        } else {
            // Contract v1 compatibility only. Projects opted into pinning never reach this branch.
            $this->copyPathDependencies($workspace, $repositoryPath);
        }

        return ['commitTimestamp' => $this->commitTimestamp($workspace->projectDir(), $commitHash), 'dependencyLocks' => $dependencyLocks];
    }

    private function assertTrustedPersistentWorkspace(string $repositoryPath, string $repositoryUrl, string $remoteName, string $branch): void
    {
        if (!is_dir($repositoryPath)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace '{$repositoryPath}' does not exist.");
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $remoteName) !== 1) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Configured remote name '{$remoteName}' contains unsupported characters.");
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

        $remote = $this->git->run(['git', 'config', '--get', 'remote.' . $remoteName . '.url'], $repositoryPath, $this->git->timeoutFor('status'));
        $actualRepositoryUrl = trim($remote['output']);
        if ($remote['exitCode'] !== 0 || $actualRepositoryUrl === '') {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Configured remote '{$remoteName}' is missing from source workspace '{$repositoryPath}'.");
        }
        if (!hash_equals($this->normalizeRepository($repositoryUrl), $this->normalizeRepository($actualRepositoryUrl))) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Source workspace repository mismatch for remote '{$remoteName}'.");
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

    private function normalizeRepository(string $repository): string
    {
        $normalized = trim(str_replace('\\', '/', $repository));
        if (preg_match('/^git@([^:]+):(.+)$/i', $normalized, $matches) === 1) {
            $host = strtolower($matches[1]);
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

    private function remoteUrl(string $repositoryPath, string $remoteName): string
    {
        $result = $this->git->run(['git', 'remote', 'get-url', $remoteName], $repositoryPath, 30);
        $url = trim($result['output']);
        if ($result['exitCode'] !== 0 || $url === '') {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Cannot resolve remote '{$remoteName}' for source workspace {$repositoryPath}.");
        }

        return $url;
    }

    private function initializeWorkspaceRepository(string $target, string $remoteUrl, ?callable $onTick): void
    {
        $this->runOrFail(['git', 'init'], $target, 30, 'initializing the isolated Git workspace', $onTick);
        $this->runOrFail(['git', 'remote', 'add', 'origin', $remoteUrl], $target, 30, 'configuring the authoritative remote', $onTick);
    }

    private function runOrFail(array $command, string $cwd, int $timeout, string $operation, ?callable $onTick): void
    {
        $result = $this->git->run($command, $cwd, $timeout, $onTick);
        if ($result['exitCode'] !== 0) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', "Failed while {$operation}: " . trim($result['output']));
        }
    }

    private function fetchBranch(string $repositoryPath, string $remoteName, string $branch, ?callable $onTick): void
    {
        $result = $this->git->run(['git', 'fetch', '--prune', $remoteName, $branch], $repositoryPath, $this->git->timeoutFor('fetch'), $onTick);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("git fetch failed for {$remoteName}/{$branch} in {$repositoryPath}: " . trim($result['output']));
        }
    }

    private function assertCommitExists(string $repositoryPath, string $commitHash): void
    {
        $result = $this->git->run(['git', 'cat-file', '-e', "{$commitHash}^{commit}"], $repositoryPath, 30);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Commit {$commitHash} is not present in {$repositoryPath} after fetch.");
        }
    }

    private function assertCommitReachableFromRemoteBranch(string $repositoryPath, string $commitHash, string $remoteName, string $branch): void
    {
        $remoteRef = "refs/remotes/{$remoteName}/{$branch}";
        $result = $this->git->run(['git', 'merge-base', '--is-ancestor', $commitHash, $remoteRef], $repositoryPath, 30);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Commit {$commitHash} is not reachable from {$remoteName}/{$branch}; refusing to build an artifact for an unverified ref.");
        }
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

        $realSource = realpath(rtrim($repositoryPath, '\\/') . '/' . $url);
        if ($realSource === false || !is_dir($realSource)) {
            throw new LocalBuildFailedException(
                'local_workspace_prepare_failed',
                "composer.json declares a path repository ('{$url}') that does not exist at " . rtrim($repositoryPath, '\\/') . '/' . $url . ' — cannot build a self-contained artifact without it.',
            );
        }

        $target = $workspace->resolvePathDependencyTarget($url);
        SafeFilesystem::copyRecursiveSafely($realSource, $realSource, $target);
    }

    private static function looksAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
