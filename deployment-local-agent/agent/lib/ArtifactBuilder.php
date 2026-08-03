<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry — `composer install`/`npm ci`
 * cannot run there at all): assembles a COMPLETE, runtime-ready artifact
 * on THIS machine — the exact commit's tracked files (via `git archive`,
 * so no .git and nothing gitignored: no node_modules/.env/storage/logs/
 * caches) PLUS the `vendor/` and `public/build/` directories
 * LocalBuildRunner just built — so the production Server Agent never
 * needs to run composer/npm at all (see agent/agent.php's
 * skip_when_artifact handling and ArtifactManager).
 *
 * Assembly happens in a STAGING directory, never directly in a zip:
 * 1. `git archive` the exact commit to a temp zip, extract it into
 *    staging (via ZipArchive — never a shelled-out `unzip`, so a git
 *    symlink blob's content, literally the target path as a text
 *    string, is written as a plain file, never recreated as a real OS
 *    symlink; there is nothing here for a ZIP-Slip/symlink-escape
 *    attack to act on).
 * 2. Copy `vendor/` and `public/build/` from the LIVE working tree
 *    (just built by LocalBuildRunner) into staging, following the same
 *    "never recreate a symlink, only ever copy resolved file content
 *    from inside the expected root" rule.
 * 3. Validate staging actually has what composer/npm were supposed to
 *    produce.
 * 4. Build the FINAL zip from staging, deterministically (sorted entry
 *    order, every entry's mtime pinned to the commit's own timestamp).
 *
 * Because the tracked-source portion comes from `git archive <commit>`
 * (git's own object database), it is completely unaffected by whatever
 * `composer install`/`npm run build` did to the LIVE working tree
 * afterwards — there is no risk of a modified/generated file leaking in
 * as if it were tracked source; only the two explicitly named
 * directories (vendor/, public/build/) are ever pulled from the working
 * tree, never a blanket copy of it.
 */
final class ArtifactBuilder
{
    private const BUILD_OUTPUT_DIRS = ['vendor', 'public/build'];

    public function __construct(
        private readonly GitManager $git,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult what LocalBuildRunner reported — determines which validations below actually apply
     * @return array{path: string, checksum: string, size: int}
     * @throws \RuntimeException if `git archive` fails, produces an empty file, or the commit changed underneath us
     * @throws LocalBuildFailedException if a build output required by $buildResult is missing from staging
     */
    public function build(string $repositoryPath, string $commitHash, array $buildResult, ?callable $onTick = null): array
    {
        // Race-condition guard: the working tree must still be exactly at
        // the commit the caller captured right after push — if something
        // else moved it in the meantime (a concurrent job on the same
        // repo, in principle prevented by the Hub's own publish lock, but
        // checked here too rather than trusted blindly), refuse to
        // archive the wrong commit.
        $actualHead = $this->git->lastCommitHash($repositoryPath);
        if (!hash_equals($commitHash, $actualHead)) {
            throw new \RuntimeException("Refusing to build an artifact: expected HEAD to be {$commitHash} but it is {$actualHead} — the working tree changed during the build.");
        }

        $stagingPath = sys_get_temp_dir() . '/la-staging-' . bin2hex(random_bytes(6));
        $tempArchivePath = null;
        $finalZipPath = null;

        try {
            $tempArchivePath = $this->archiveCommitToZip($repositoryPath, $commitHash, $onTick);
            mkdir($stagingPath, 0755, true);
            self::extractZipSafely($tempArchivePath, $stagingPath);

            $this->copyBuildOutput($repositoryPath, $stagingPath, 'vendor');
            $this->copyBuildOutput($repositoryPath, $stagingPath, 'public/build');

            $this->validateStaging($stagingPath, $buildResult);

            $commitTimestamp = $this->commitTimestamp($repositoryPath, $commitHash);
            $finalZipPath = self::buildDeterministicZip($stagingPath, $commitTimestamp);

            $size = @filesize($finalZipPath);
            if ($size === false || $size === 0) {
                throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact is empty.');
            }

            $checksum = hash_file('sha256', $finalZipPath);
            if ($checksum === false) {
                throw new LocalBuildFailedException('artifact_build_failed', 'Could not compute a checksum for the assembled artifact.');
            }

            return ['path' => $finalZipPath, 'checksum' => $checksum, 'size' => $size];
        } catch (\Throwable $e) {
            if ($finalZipPath !== null) {
                @unlink($finalZipPath);
            }

            throw $e;
        } finally {
            if ($tempArchivePath !== null) {
                @unlink($tempArchivePath);
            }
            self::recursiveDelete($stagingPath);
        }
    }

    private function archiveCommitToZip(string $repositoryPath, string $commitHash, ?callable $onTick): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'la-archive-') . '.zip';
        @unlink($archivePath); // tempnam() creates it; git archive -o refuses to write over some filesystems' quirks otherwise

        $result = $this->git->run(
            ['git', 'archive', '--format=zip', '-o', $archivePath, $commitHash],
            $repositoryPath,
            $this->timeoutSeconds,
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

    /**
     * ZIP-Slip guard: rejects any entry whose name is absolute or
     * contains a ".." path segment BEFORE ever calling extractTo() —
     * defense in depth on top of libzip's own traversal protection, not
     * a replacement for it.
     */
    private static function extractZipSafely(string $zipPath, string $destination): void
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
     * Copies one build-output directory (vendor/ or public/build/) from
     * the live working tree into staging. Silently a no-op if the
     * directory doesn't exist (e.g. a project with no package.json never
     * has public/build) — validateStaging() below is what actually
     * enforces "it must exist" for whichever stages LocalBuildRunner
     * reported as having run.
     */
    private function copyBuildOutput(string $repositoryPath, string $stagingPath, string $relativeDir): void
    {
        $source = "{$repositoryPath}/{$relativeDir}";
        if (!is_dir($source)) {
            return;
        }

        $realSource = realpath($source);
        if ($realSource === false) {
            return;
        }

        $destination = "{$stagingPath}/{$relativeDir}";
        self::copyRecursiveSafely($realSource, $realSource, $destination);
    }

    /**
     * @param string $sourceRoot the resolved, real path every copied
     *     entry must stay within — a symlink whose target resolves
     *     outside of it is skipped entirely (never followed, never
     *     recreated as a symlink in the destination).
     */
    private static function copyRecursiveSafely(string $sourceRoot, string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }

        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            $fromPath = "{$from}/{$entry}";
            $toPath = "{$to}/{$entry}";

            if (is_link($fromPath)) {
                $real = realpath($fromPath);
                if ($real === false || !str_starts_with($real, $sourceRoot)) {
                    continue; // symlink escapes the expected root (or is broken) — never followed, never copied
                }

                if (is_dir($real)) {
                    self::copyRecursiveSafely($sourceRoot, $real, $toPath);
                } else {
                    copy($real, $toPath);
                }

                continue;
            }

            if (is_dir($fromPath)) {
                self::copyRecursiveSafely($sourceRoot, $fromPath, $toPath);
            } else {
                copy($fromPath, $toPath);
            }
        }
    }

    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult
     * @throws LocalBuildFailedException
     */
    private function validateStaging(string $stagingPath, array $buildResult): void
    {
        if (self::isEffectivelyEmpty($stagingPath)) {
            throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact staging directory is empty.');
        }

        if ($buildResult['ranComposer'] && !is_file("{$stagingPath}/vendor/autoload.php")) {
            throw new LocalBuildFailedException('artifact_missing_vendor', 'composer install succeeded but vendor/autoload.php is missing from the assembled artifact.');
        }

        // The Laravel + Vite convention this codebase's own presets use
        // (see ProjectController::PIPELINE_PRESET_LARAVEL) — an
        // explicit, documented assumption about the manifest's location
        // and name, not a guess: a project whose build tool emits a
        // differently-named or -located manifest needs its own
        // validation, not covered here.
        if ($buildResult['ranNpmBuild'] && !is_file("{$stagingPath}/public/build/manifest.json")) {
            throw new LocalBuildFailedException('artifact_missing_build', 'npm run build succeeded but public/build/manifest.json is missing from the assembled artifact.');
        }
    }

    /**
     * Deterministic: entries are added in sorted path order and every
     * entry's mtime is pinned to the commit's own timestamp — the same
     * staging directory always produces byte-identical zip metadata
     * (entry order, timestamps) regardless of filesystem iteration order
     * or wall-clock build time. This does not guarantee vendor/public-build's
     * own CONTENT is bit-for-bit reproducible across separate composer/
     * npm runs (a deeper property outside this class's control) — only
     * that packaging the same staging content twice yields the same zip.
     */
    private static function buildDeterministicZip(string $stagingPath, int $mtime): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'la-final-') . '.zip';
        @unlink($zipPath);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($stagingPath) + 1);
            $files[str_replace('\\', '/', $relative)] = $item->getPathname();
        }
        ksort($files, SORT_STRING);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Could not create zip at {$zipPath}.");
        }

        foreach ($files as $relativePath => $absolutePath) {
            $zip->addFile($absolutePath, $relativePath);
            $zip->setMtimeName($relativePath, $mtime);
            // Deterministic, non-executable-by-default permission bits
            // (0644 for files) regardless of the source filesystem's own
            // mode — Windows in particular has no meaningful Unix mode to
            // preserve here anyway.
            $zip->setExternalAttributesName($relativePath, \ZipArchive::OPSYS_UNIX, (0100644 << 16));
        }

        $zip->close();

        return $zipPath;
    }

    private static function isEffectivelyEmpty(string $dir): bool
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

    private static function recursiveDelete(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

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
}
