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
     * @return array{path: string, checksum: string, size: int}
     * @throws LocalBuildFailedException
     */
    public function build(string $projectDir, string $workspaceRoot, int $commitTimestamp, array $buildResult, ?BuildProfile $profile = null): array
    {
        if ($profile?->nextStandalone) $this->assembleNextStandalone($projectDir, $profile->nextAppPath);
        if ($profile?->prismaGenerate) $this->bundlePrismaRuntime($projectDir);
        $this->validateStaging($projectDir, $buildResult, $profile);

        $finalZipPath = null;
        try {
            $finalZipPath = self::buildDeterministicZip($projectDir, $workspaceRoot, $commitTimestamp);

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
        }
    }

    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult
     * @throws LocalBuildFailedException
     */
    private function validateStaging(string $projectDir, array $buildResult, ?BuildProfile $profile = null): void
    {
        if (SafeFilesystem::isEffectivelyEmpty($projectDir)) {
            throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact workspace is empty.');
        }

        if ($buildResult['ranComposer'] && !is_file("{$projectDir}/vendor/autoload.php")) {
            throw new LocalBuildFailedException('artifact_missing_vendor', 'composer install succeeded but vendor/autoload.php is missing from the assembled artifact.');
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
        foreach ($profile?->requiredPaths ?? [] as $path) if (!file_exists("{$projectDir}/{$path}")) throw new LocalBuildFailedException('artifact_missing_required_path', "Build profile required artifact path is missing: {$path}.");
        if ($profile?->prismaGenerate && !is_file("{$projectDir}/node_modules/.bin/prisma") && !is_file("{$projectDir}/node_modules/.bin/prisma.cmd")) throw new LocalBuildFailedException('artifact_missing_prisma_cli', 'prisma_generate requires a locally installed Prisma CLI.');
    }

    private function assembleNextStandalone(string $projectDir, ?string $appPath): void
    {
        $appDir = rtrim($projectDir . '/' . $appPath, '/');
        $standalone = "{$appDir}/.next/standalone" . ($appPath === '.' ? '' : "/{$appPath}");
        if (!is_dir($standalone)) throw new LocalBuildFailedException('artifact_missing_next_standalone', "Next standalone output is missing at {$standalone}.");
        foreach (['.next/static' => '.next/static', 'public' => 'public'] as $from => $to) {
            if (!is_dir("{$appDir}/{$from}")) throw new LocalBuildFailedException('artifact_missing_next_runtime', "Next runtime input is missing: {$appDir}/{$from}.");
            self::copyDirectory("{$appDir}/{$from}", "{$standalone}/{$to}");
        }
    }
    private static function copyDirectory(string $from, string $to): void { if (!is_dir($to)) mkdir($to, 0755, true); foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) { $a="$from/$entry"; $b="$to/$entry"; is_dir($a) ? self::copyDirectory($a, $b) : copy($a, $b); } }

    /** Bundle Prisma's declared runtime dependency closure, never all node_modules. */
    private function bundlePrismaRuntime(string $projectDir): void
    {
        $sourceRoot = "{$projectDir}/node_modules";
        if (!is_file("{$sourceRoot}/prisma/build/index.js")) throw new LocalBuildFailedException('artifact_missing_prisma_cli', 'Local Prisma CLI entrypoint is missing.');
        $copied = [];
        $this->copyNpmPackageClosure($sourceRoot, "{$projectDir}/.deployment-runtime/prisma/node_modules", 'prisma', $copied);
    }
    /** @param array<string, true> $copied */
    private function copyNpmPackageClosure(string $sourceRoot, string $targetRoot, string $package, array &$copied): void
    {
        if (isset($copied[$package])) return;
        $source = "{$sourceRoot}/{$package}";
        $manifest = "{$source}/package.json";
        if (!is_file($manifest)) throw new LocalBuildFailedException('artifact_missing_prisma_dependency', "Missing Prisma runtime dependency: {$package}.");
        $copied[$package] = true;
        $json = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($json)) throw new LocalBuildFailedException('artifact_missing_prisma_dependency', "Invalid package metadata: {$package}.");
        foreach (array_keys($json['dependencies'] ?? []) as $dependency) if (is_string($dependency)) $this->copyNpmPackageClosure($sourceRoot, $targetRoot, $dependency, $copied);
        self::copyDirectory($source, "{$targetRoot}/{$package}");
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
