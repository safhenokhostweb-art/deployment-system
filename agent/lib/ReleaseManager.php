<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Owns the on-disk release layout for a recipe-driven project — never
 * sent to or known by the Hub (see docs/ARCHITECTURE.md §8: "the Hub
 * never learns server file paths"). Layout, under the project's
 * configured `working_directory` (the project's app root for a recipe
 * project, e.g. /var/www/sandogh):
 *
 *   releases/<deployment public_id>/   one immutable directory per deploy,
 *                                      created once by checkout_release
 *                                      and never modified again — this is
 *                                      exactly what makes rollback a plain
 *                                      symlink swap instead of "hope git
 *                                      reset --hard left a clean tree".
 *   current -> releases/<id>           symlink the running services
 *                                      actually reference (systemd units
 *                                      should point at `current/...`).
 *   previous -> releases/<id>          the release `current` pointed at
 *                                      immediately before the last
 *                                      activate() call — rollback's target.
 */
final class ReleaseManager
{
    public function releasesDir(string $releaseRoot): string
    {
        return rtrim($releaseRoot, '/') . '/releases';
    }

    public function releasePath(string $releaseRoot, string $deploymentId): string
    {
        return $this->releasesDir($releaseRoot) . '/' . $deploymentId;
    }

    private function currentSymlinkPath(string $releaseRoot): string
    {
        return rtrim($releaseRoot, '/') . '/current';
    }

    private function previousSymlinkPath(string $releaseRoot): string
    {
        return rtrim($releaseRoot, '/') . '/previous';
    }

    public function currentReleasePath(string $releaseRoot): ?string
    {
        $link = $this->currentSymlinkPath($releaseRoot);

        return is_link($link) ? (readlink($link) ?: null) : null;
    }

    public function previousReleasePath(string $releaseRoot): ?string
    {
        $link = $this->previousSymlinkPath($releaseRoot);

        return is_link($link) ? (readlink($link) ?: null) : null;
    }

    /**
     * `git worktree add` against a persistent, out-of-band-bootstrapped
     * source clone — shares the object store (no re-clone over the
     * network on every deploy) and produces a directory that is, once
     * created, never written to again by any later deploy.
     *
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    public function checkout(Executor $executor, string $sourceRepository, string $releasePath, string $commitHash, int $timeoutSeconds, ?callable $onTick = null): array
    {
        if (!is_dir(dirname($releasePath))) {
            mkdir(dirname($releasePath), 0755, true);
        }

        $fetch = $executor->runArgv(['git', '-C', $sourceRepository, 'fetch', '--quiet', 'origin', $commitHash], sys_get_temp_dir(), $timeoutSeconds, [], $onTick);
        if ($fetch['exitCode'] !== 0) {
            return $fetch;
        }

        return $executor->runArgv(
            ['git', '-C', $sourceRepository, 'worktree', 'add', '--detach', '--quiet', $releasePath, $commitHash],
            sys_get_temp_dir(),
            $timeoutSeconds,
            [],
            $onTick,
        );
    }

    /**
     * Atomic: the new symlink is built at a temporary path and moved into
     * place with rename(), which POSIX guarantees is atomic within the
     * same filesystem — there is never a moment where `current` is
     * missing or points at a half-written target. `previous` is updated
     * to whatever `current` pointed at immediately before this call, so
     * rollbackToPrevious() always has a well-defined target.
     */
    public function activate(string $releaseRoot, string $releasePath): void
    {
        $current = $this->currentSymlinkPath($releaseRoot);
        $previous = $this->previousSymlinkPath($releaseRoot);
        $existingCurrentTarget = $this->currentReleasePath($releaseRoot);

        $tmpCurrent = $current . '.tmp-' . bin2hex(random_bytes(4));
        symlink($releasePath, $tmpCurrent);
        rename($tmpCurrent, $current);

        if ($existingCurrentTarget !== null && $existingCurrentTarget !== $releasePath) {
            $tmpPrevious = $previous . '.tmp-' . bin2hex(random_bytes(4));
            symlink($existingCurrentTarget, $tmpPrevious);
            rename($tmpPrevious, $previous);
        }
    }

    /**
     * Deletes every release directory beyond $retentionCount, newest
     * first, always excluding whatever `current`/`previous` point at
     * right now regardless of age — retention is about disk hygiene, not
     * about ever deleting something still referenced. A release created
     * via checkout()'s `git worktree add` is properly deregistered with
     * `git worktree remove` first when $sourceRepository is known, so
     * git's own worktree metadata never goes stale; otherwise it's just
     * removed from disk directly.
     *
     * @return array<int, string> paths that were removed
     */
    public function cleanupOldReleases(Executor $executor, string $releaseRoot, int $retentionCount, ?string $sourceRepository): array
    {
        $releasesDir = $this->releasesDir($releaseRoot);
        if (!is_dir($releasesDir)) {
            return [];
        }

        $mustKeep = array_filter([
            $this->currentReleasePath($releaseRoot),
            $this->previousReleasePath($releaseRoot),
        ]);

        $entries = array_values(array_diff(scandir($releasesDir) ?: [], ['.', '..']));
        $paths = array_map(static fn (string $entry): string => $releasesDir . '/' . $entry, $entries);

        usort($paths, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        $keep = array_unique(array_merge(array_slice($paths, 0, $retentionCount), $mustKeep));

        $removed = [];
        foreach ($paths as $path) {
            if (in_array($path, $keep, true)) {
                continue;
            }

            if ($sourceRepository !== null) {
                $executor->runArgv(['git', '-C', $sourceRepository, 'worktree', 'remove', '--force', $path], sys_get_temp_dir(), 30);
            }

            self::recursiveDelete($path);
            $removed[] = $path;
        }

        return $removed;
    }

    private static function recursiveDelete(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::recursiveDelete($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
