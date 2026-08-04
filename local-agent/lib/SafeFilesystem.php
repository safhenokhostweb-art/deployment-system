<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Shared, defense-in-depth filesystem primitives used anywhere this
 * agent extracts an archive, copies a directory tree from one place to
 * another, or deletes one — originally private to ArtifactBuilder, moved
 * here once BuildWorkspace/WorkspaceSourcePreparer (isolated build
 * workspace, v1.4.2) needed the exact same guarantees for a second and
 * third call site.
 *
 * Root-cause fix, discovered empirically on this project's own Windows
 * dev machine while building the isolated-workspace feature — TWO
 * separate false starts, both confirmed by direct testing with a real
 * `mklink /J` Junction (exactly how Composer wires up a
 * `"options": {"symlink": true}` path-repository package on Windows):
 *   1. `is_link()` does NOT detect a Junction — returns `false` for one.
 *   2. `readlink()` (the obvious next choice) is WORSE than useless
 *      here: on this platform it returns a non-false, realpath-like
 *      string for an ORDINARY directory too, not just for a real link —
 *      so "readlink() succeeded" cannot be used to mean "this is a
 *      link" at all. Trusting it caused recursiveDelete() to
 *      mis-classify every ordinary directory as if it were a link and
 *      never actually recurse into (or delete) its contents.
 * The one technique that DID prove reliable across repeated testing:
 * compare `realpath($path)` against `realpath(dirname($path)) .
 * DIRECTORY_SEPARATOR . basename($path)` — i.e. "where would this path
 * be if its own final component were NOT a reparse point" vs "where
 * does it actually, fully resolve to". They match for an ordinary entry
 * and differ for a Junction/symlink (whose final component resolves
 * somewhere else entirely). See isLinkLikeEntry().
 */
final class SafeFilesystem
{
    /**
     * ZIP-Slip guard: rejects any entry whose name is absolute or
     * contains a ".." path segment BEFORE ever calling extractTo() —
     * defense in depth on top of libzip's own traversal protection, not
     * a replacement for it.
     */
    public static function extractZipSafely(string $zipPath, string $destination): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Could not open {$zipPath} as a zip archive.");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (str_starts_with($name, '/') || str_contains($name, '..') || preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
                $zip->close();

                throw new \RuntimeException("Refusing to extract a zip entry with an unsafe path: {$name}");
            }
        }

        $extracted = $zip->extractTo($destination);
        $zip->close();

        if (!$extracted) {
            throw new \RuntimeException("Failed to extract {$zipPath} to {$destination}.");
        }
    }

    /**
     * Copies a directory tree from $from into $to. Every entry is
     * resolved via realpath() before copying — harmless and a no-op for
     * an ordinary file/directory (realpath of an ordinary entry is just
     * its own canonical path), and correctly dereferences a
     * symlink/Junction to its REAL target — only if that target stays
     * within $sourceRoot; anything resolving outside of it (or broken)
     * is silently skipped. Never recreates a link in the destination —
     * only ever copies real, resolved file content.
     *
     * @param string $sourceRoot the resolved, real path every copied
     *     entry must stay within.
     */
    public static function copyRecursiveSafely(string $sourceRoot, string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }

        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            $fromPath = "{$from}/{$entry}";
            $toPath = "{$to}/{$entry}";

            $real = realpath($fromPath);
            if ($real === false || (!str_starts_with($real, $sourceRoot . DIRECTORY_SEPARATOR) && $real !== $sourceRoot)) {
                continue; // escapes the expected root (or is broken/unreadable) — never followed, never copied
            }

            if (is_dir($real)) {
                self::copyRecursiveSafely($sourceRoot, $real, $toPath);
            } else {
                copy($real, $toPath);
            }
        }
    }

    /**
     * Recursively walks $from (rooted at $boundaryRoot for the same
     * escape guard as copyRecursiveSafely — anything resolving outside
     * of it is skipped) and populates $files with relativeZipPath =>
     * realAbsoluteFilePath for every real file found. Any directory
     * literally named `node_modules` or `.git` is skipped entirely —
     * neither may ever enter a build artifact.
     *
     * @param array<string, string> $files
     */
    public static function collectFilesSafely(string $boundaryRoot, string $from, string $relativePrefix, array &$files): void
    {
        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            if ($entry === 'node_modules' || $entry === '.git') {
                continue;
            }

            $fromPath = "{$from}/{$entry}";
            $relativePath = $relativePrefix === '' ? $entry : "{$relativePrefix}/{$entry}";

            $real = realpath($fromPath);
            if ($real === false || (!str_starts_with($real, $boundaryRoot . DIRECTORY_SEPARATOR) && $real !== $boundaryRoot)) {
                continue; // escapes the isolated workspace (or is broken/unreadable) — never followed, never included
            }

            if (is_dir($real)) {
                self::collectFilesSafely($boundaryRoot, $real, $relativePath, $files);
            } else {
                $files[$relativePath] = $real;
            }
        }
    }

    public static function isEffectivelyEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            return false;
        }

        return true;
    }

    /**
     * A plain recursive delete with NO root guard of its own — callers
     * that delete anything derived from configuration or a job id (i.e.
     * anything not hardcoded) MUST verify the target is where they
     * expect first (see BuildWorkspace's own guardedRemoveTree()).
     *
     * Unlike copy/collect above, deletion CANNOT simply "always resolve
     * via realpath()" — that would delete through a Junction/symlink
     * into its real target, which must never happen (a Composer
     * path-repository Junction inside a workspace's vendor/ points at
     * ANOTHER part of the same disposable workspace here, but deleting
     * through it would still delete that content out from under its own,
     * separate directory-tree walk elsewhere in the same recursive
     * delete — order-dependent and wrong). So this explicitly detects a
     * link-like entry first (see isLinkLikeEntry()) and, for one,
     * removes only the pointer itself, never recursing into it.
     */
    public static function recursiveDelete(string $path): void
    {
        if (self::isLinkLikeEntry($path)) {
            // Order matters here: a Windows directory Junction can ONLY
            // be removed via rmdir() (unlink() fails on it outright,
            // confirmed by direct testing — DeleteFile() doesn't work on
            // a directory reparse point); a POSIX symlink (to a file OR
            // a directory) is the opposite — POSIX rmdir() on a symlink
            // fails with ENOTDIR by spec, and unlink() is what actually
            // removes it. Trying rmdir() first, then unlink(), is
            // correct and target-content-safe on both.
            if (!@rmdir($path)) {
                @unlink($path);
            }

            return;
        }

        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::recursiveDelete("{$path}/{$entry}");
        }
        @rmdir($path);
    }

    /**
     * True if $path's own final path component is itself a
     * symlink/Junction (i.e. resolves somewhere OTHER than where it
     * lexically sits) — see this class's own docblock for why this
     * specific realpath-comparison technique is used instead of
     * is_link()/readlink(), both confirmed unreliable for a Windows
     * Junction by direct testing.
     */
    private static function isLinkLikeEntry(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false; // broken/nonexistent — nothing to delete specially, let the caller's normal unlink() handle it
        }

        $parentReal = realpath(dirname($path));
        if ($parentReal === false) {
            return false;
        }

        $expectedIfOrdinary = rtrim($parentReal, '\\/') . DIRECTORY_SEPARATOR . basename($path);

        return $expectedIfOrdinary !== $real;
    }
}
