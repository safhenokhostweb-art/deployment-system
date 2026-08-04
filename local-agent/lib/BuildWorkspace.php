<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (v1.4.2): `composer install`/`npm ci` used to run
 * directly inside the developer's own working tree — real bug reported:
 * `npm ci` needs to delete the existing `node_modules/`, but a file
 * inside it (a native Tailwind oxide binary) was locked by Windows/VS
 * Code/an editor watcher, so every publish failed with `EPERM unlink`,
 * and manual retries "worked" only because closing every other process
 * first happened to release the lock. This class gives each publish its
 * own throwaway directory tree, entirely OUTSIDE any project's own
 * folder, so composer/npm never touch — and can never be blocked by
 * anything holding a lock inside — the real `vendor/`/`node_modules/`.
 *
 * Layout: `<baseDir>/<projectKey>/<jobId>/project/` is where the exact
 * published commit is archived and where composer/npm actually run.
 * `<baseDir>/<projectKey>/<jobId>/` (the workspace root, one level up)
 * is also where a Composer path-repository dependency
 * (e.g. `../saas-platform/...`) gets copied to, at the SAME relative
 * position it holds next to the real project — see
 * resolvePathDependencyTarget() and WorkspaceSourcePreparer.
 *
 * Every deletion this class performs is guarded: it refuses to operate
 * on anything whose resolved real path isn't confirmed to be nested
 * inside the expected base builds directory — a coding mistake here can
 * at worst fail loudly, never silently delete something unexpected (see
 * guardedRemoveTree()).
 */
final class BuildWorkspace
{
    private function __construct(
        private readonly string $root,
        private readonly string $projectDir,
    ) {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Job ids are Hub-issued and unique, so two publishes — even for the
     * same project, even from two Local Agent processes in principle —
     * never collide on a workspace path; each is fully independent by
     * construction, with no locking of its own needed.
     */
    public static function create(string $baseDir, string $projectKey, string $jobId): self
    {
        $root = self::joinPath($baseDir, self::sanitizeSegment($projectKey) . '/' . self::sanitizeSegment($jobId));

        if (is_dir($root)) {
            // A stale leftover from a previous crashed run reusing the
            // same job id should never legitimately happen (job ids are
            // unique) — but silently merging a fresh build into it would
            // be worse than refusing to trust it. Clear it first.
            self::guardedRemoveTree($root, $baseDir);
        }

        if (!self::mkdirRecursive($root)) {
            throw new LocalBuildFailedException('local_workspace_create_failed', "Could not create build workspace directory at {$root}.");
        }

        $projectDir = $root . '/project';
        if (!self::mkdirRecursive($projectDir)) {
            throw new LocalBuildFailedException('local_workspace_create_failed', "Could not create project directory inside build workspace at {$projectDir}.");
        }

        return new self($root, $projectDir);
    }

    /**
     * Where a Composer path-repository dependency, declared relative to
     * the real project's composer.json, must be copied to so the SAME
     * relative URL resolves correctly from inside the workspace's own
     * project directory (i.e. the workspace mirrors the real sibling
     * layout on disk, just rooted somewhere disposable under a temp
     * directory instead of next to the real project).
     *
     * @throws LocalBuildFailedException if the resolved target would
     *     land outside this workspace's own root — e.g. an absolute
     *     path-repository URL, or one with enough `..` segments to
     *     escape the workspace entirely. Never silently written to.
     */
    public function resolvePathDependencyTarget(string $relativeUrl): string
    {
        $target = self::normalizeJoinedPath($this->projectDir, $relativeUrl);
        $root = self::normalizeJoinedPath($this->root, '');

        if ($target !== $root && !str_starts_with($target, $root . '/')) {
            throw new LocalBuildFailedException(
                'local_workspace_prepare_failed',
                "Composer path repository '{$relativeUrl}' resolves outside the isolated build workspace ({$target}) — refusing to copy it there. Path repositories must stay within the project's own directory tree (relative to composer.json).",
            );
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $target);
    }

    /**
     * Never throws — a cleanup failure (e.g. a file locked by something
     * else even inside our OWN disposable workspace) must not corrupt
     * the Publish Job's actual result, which by this point has already
     * been determined. Only logs a warning.
     */
    public function cleanup(?Logger $logger = null): void
    {
        try {
            // The floor two levels above root: root = <base>/<projectKey>/<jobId>.
            self::guardedRemoveTree($this->root, dirname(dirname($this->root)));
        } catch (\Throwable $e) {
            $logger?->warn("[local_workspace_cleanup_failed] Could not fully clean up build workspace at {$this->root}: " . $e->getMessage());
        }
    }

    /**
     * Best-effort sweep for workspaces left behind by a crashed/killed
     * agent process (the normal path always cleans up its own workspace
     * in a finally block — this only ever catches the abnormal case).
     * Called once per publish, before a new workspace is created, so it
     * never needs its own scheduler.
     */
    public static function pruneStale(string $baseDir, int $ttlSeconds, ?Logger $logger = null): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        $cutoff = time() - $ttlSeconds;

        foreach (array_diff(scandir($baseDir) ?: [], ['.', '..']) as $projectEntry) {
            $projectPath = "{$baseDir}/{$projectEntry}";
            if (!is_dir($projectPath)) {
                continue;
            }

            foreach (array_diff(scandir($projectPath) ?: [], ['.', '..']) as $jobEntry) {
                $jobPath = "{$projectPath}/{$jobEntry}";
                if (!is_dir($jobPath)) {
                    continue;
                }

                $mtime = @filemtime($jobPath);
                if ($mtime === false || $mtime > $cutoff) {
                    continue; // fresh enough, or unreadable — never guess, leave it alone
                }

                try {
                    self::guardedRemoveTree($jobPath, $baseDir);
                    $logger?->info("Pruned stale build workspace: {$jobPath}");
                } catch (\Throwable $e) {
                    $logger?->warn("[local_workspace_cleanup_failed] Could not prune stale build workspace {$jobPath}: " . $e->getMessage());
                }
            }
        }
    }

    private static function mkdirRecursive(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0700, true);
    }

    private static function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '';

        return $sanitized === '' ? '_' : $sanitized;
    }

    private static function joinPath(string $base, string $relative): string
    {
        return str_replace('\\', '/', rtrim($base, '\\/')) . '/' . $relative;
    }

    /**
     * Pure string normalization — the target may not exist on disk yet
     * (that's the whole point when resolving a path-dependency's
     * destination before it's been copied), so realpath() can't be used
     * here. Resolves "." and ".." segments; preserves a Windows drive
     * letter or a leading "/" (Unix-absolute) at the front.
     */
    private static function normalizeJoinedPath(string $base, string $relative): string
    {
        $combined = $relative === '' ? $base : rtrim($base, '\\/') . '/' . ltrim($relative, '\\/');
        $combined = str_replace('\\', '/', $combined);
        $isUnixAbsolute = str_starts_with($combined, '/');

        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return ($isUnixAbsolute ? '/' : '') . implode('/', $parts);
    }

    /**
     * Refuses to delete anything unless its resolved real path is
     * confirmed to be nested inside $expectedFloor (never equal to it —
     * a job workspace is always a subdirectory of the shared builds
     * base, never that base directory itself). This is the ONLY
     * deletion path in this class — every public method above routes
     * through it.
     */
    private static function guardedRemoveTree(string $path, string $expectedFloor): void
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return; // already gone
        }

        $realFloor = realpath($expectedFloor);
        if ($realFloor === false) {
            throw new \RuntimeException("Refusing to delete {$path} — its expected floor {$expectedFloor} does not exist, so containment cannot be confirmed.");
        }

        if ($realPath === $realFloor) {
            throw new \RuntimeException("Refusing to delete {$path} — it resolved to the shared builds root itself, not a specific job workspace.");
        }

        if (!str_starts_with($realPath, $realFloor . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Refusing to delete {$path} — it is not confirmed to be inside its expected root {$expectedFloor}.");
        }

        SafeFilesystem::recursiveDelete($realPath);

        // recursiveDelete() suppresses individual unlink()/rmdir()
        // failures (e.g. one file locked by something else) rather than
        // throwing mid-walk — so completion must be verified explicitly
        // here, or a partial failure would silently look like success
        // and cleanup() would never log its warning.
        if (is_dir($realPath) || is_file($realPath)) {
            throw new \RuntimeException("Could not fully delete {$path} — some entry inside it is still in use (e.g. locked by another process).");
        }
    }
}
