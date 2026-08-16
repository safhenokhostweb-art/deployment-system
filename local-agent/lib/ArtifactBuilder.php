<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry — `composer install`/`npm ci`
 * cannot run there at all): builds the FINAL artifact zip from an
 * already-populated, isolated build workspace (see BuildWorkspace /
 * WorkspaceSourcePreparer) — by the time this class runs, the
 * workspace's project directory already contains the exact published
 * commit's tracked source (via `git archive`) PLUS the `vendor/` and
 * `public/build/` LocalBuildRunner just built directly inside it, so the
 * production Server Agent never needs to run composer/npm at all (see
 * agent/agent.php's skip_when_artifact handling and ArtifactManager).
 *
 * Root-cause fix (v1.4.2): this class used to ALSO do the `git archive`
 * + extract-to-staging + copy-vendor-in-from-the-real-working-tree work
 * itself. That's gone — composer/npm now run directly inside the
 * isolated workspace (never the developer's real working tree, which
 * was the actual bug: `npm ci` there could hit a file locked by
 * Windows/an editor/watcher and fail with EPERM), so `vendor/` and
 * `public/build/` are already exactly where they need to be by the time
 * this class runs. What's left here is just: validate, then zip —
 * deterministically, excluding `node_modules/`/`.git` (a real OS
 * concern now that composer/npm run IN the artifact's own source tree
 * rather than a separate copy step that only ever touched vendor/
 * public-build), and with symlink/Junction resolution bounded by the
 * WHOLE workspace root rather than just the project directory — a
 * Composer path-repository dependency
 * (`"options": {"symlink": true}`) is wired up as a real Junction on
 * Windows pointing at a WORKSPACE-SIBLING directory (see
 * WorkspaceSourcePreparer), so the safe boundary has to include that,
 * not just the source tree itself.
 */
final class ArtifactBuilder
{
    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult what LocalBuildRunner reported — determines which validations below actually apply
     * @return array{path: string, checksum: string, size: int, requiredPaths: array<int, string>}
     * @throws LocalBuildFailedException
     */
    public function build(string $projectDir, string $workspaceRoot, int $commitTimestamp, array $buildResult, ?callable $onStage = null): array
    {
        $this->validateStaging($projectDir, $buildResult);

        $finalZipPath = null;
        try {
            $onStage?->__invoke('ZIP generation started');
            $finalZipPath = self::buildDeterministicZip($projectDir, $workspaceRoot, $commitTimestamp);
            $onStage?->__invoke('ZIP generation completed');

            $size = @filesize($finalZipPath);
            if ($size === false || $size === 0) {
                throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact is empty.');
            }

            $onStage?->__invoke('checksum calculation started');
            $checksum = hash_file('sha256', $finalZipPath);
            if ($checksum === false) {
                throw new LocalBuildFailedException('artifact_build_failed', 'Could not compute a checksum for the assembled artifact.');
            }

            $onStage?->__invoke('checksum calculation completed');

            return ['path' => $finalZipPath, 'checksum' => $checksum, 'size' => $size, 'requiredPaths' => $this->requiredPaths($projectDir, $buildResult)];
        } catch (\Throwable $e) {
            if ($finalZipPath !== null) {
                @unlink($finalZipPath);
            }

            throw $e;
        }
    }

    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult
     * @throws LocalBuildFailedException
     */
    private function validateStaging(string $projectDir, array $buildResult): void
    {
        if (SafeFilesystem::isEffectivelyEmpty($projectDir)) {
            throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact workspace is empty.');
        }

        if (is_file("{$projectDir}/composer.json") && !is_file("{$projectDir}/vendor/autoload.php")) {
            throw new LocalBuildFailedException('artifact_missing_vendor', 'composer.json exists but vendor/autoload.php is missing from the assembled artifact.');
        }

        // The Laravel + Vite convention this codebase's own presets use
        // (see ProjectController::PIPELINE_PRESET_LARAVEL) — an
        // explicit, documented assumption about the manifest's location
        // and name, not a guess: a project whose build tool emits a
        // differently-named or -located manifest needs its own
        // validation, not covered here.
        if ($buildResult['ranNpmBuild'] && !is_file("{$projectDir}/public/build/manifest.json")) {
            throw new LocalBuildFailedException('artifact_missing_build', 'npm run build succeeded but public/build/manifest.json is missing from the assembled artifact.');
        }
    }

    /** @return array<int, string> */
    private function requiredPaths(string $projectDir, array $buildResult): array
    {
        $paths = [];
        if (is_file("{$projectDir}/composer.json")) {
            $paths = ['composer.json', 'vendor/autoload.php'];
            if (is_file("{$projectDir}/artisan")) {
                $paths = [...$paths, 'artisan', 'bootstrap/app.php', 'public/index.php'];
            }
        }
        if ($buildResult['ranNpmBuild']) {
            $paths[] = 'public/build/manifest.json';
        }
        return $paths;
    }

    /**
     * Deterministic: entries are added in sorted path order and every
     * entry's mtime is pinned to the commit's own timestamp — the same
     * project directory always produces byte-identical zip metadata
     * (entry order, timestamps) regardless of filesystem iteration order
     * or wall-clock build time. This does not guarantee vendor/public-build's
     * own CONTENT is bit-for-bit reproducible across separate composer/
     * npm runs (a deeper property outside this class's control) — only
     * that packaging the same directory content twice yields the same
     * zip.
     *
     * $workspaceRoot bounds symlink/Junction resolution (see class
     * docblock) — must be an ancestor of (or equal to) $projectDir.
     */
    private static function buildDeterministicZip(string $projectDir, string $workspaceRoot, int $mtime): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'la-final-') . '.zip';
        @unlink($zipPath);

        $realBoundary = realpath($workspaceRoot);
        if ($realBoundary === false) {
            throw new \RuntimeException("Could not resolve the build workspace root at {$workspaceRoot}.");
        }

        $files = [];
        SafeFilesystem::collectFilesSafely($realBoundary, $projectDir, '', $files);
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
}
