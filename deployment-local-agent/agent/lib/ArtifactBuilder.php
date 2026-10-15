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
    public const DEPENDENCY_LOCK_MARKER = '.deployment/dependency-lock-v2';

    /**
     * @param array{ranComposer: bool, ranNpmBuild: bool} $buildResult what LocalBuildRunner reported — determines which validations below actually apply
     * @param array{project_key?: string, commit_sha?: string, branch?: string, agent_version?: string, source_paths?: array<int, string>, dependency_pinning_required?: bool, dependency_locks?: array<int, array<string, mixed>>} $provenance release provenance written into the artifact's own manifest
     * @return array{path: string, checksum: string, size: int, requiredPaths: array<int, string>, file_manifest_hash?: string, file_manifest_entry_count?: int, file_manifest_total_size?: int}
     * @throws LocalBuildFailedException
     */
    public function build(string $projectDir, string $workspaceRoot, int $commitTimestamp, array $buildResult, ?callable $onStage = null, array $provenance = []): array
    {
        $this->validateStaging($projectDir, $buildResult);

        // Root-cause fix (Laravel release completeness): embed a small
        // provenance manifest describing the artifact that is actually
        // being sealed — commit SHA, composer.lock SHA-256, the required
        // runtime paths, and the build result — so the Server Agent's
        // pre-activation gate can verify release metadata against the job
        // before activation. Written into the isolated workspace tree, so
        // it becomes part of the immutable zip (never the developer's live
        // working tree).
        $this->validateDependencyLocks($projectDir, $provenance['dependency_locks'] ?? [], false);
        $this->writeReleaseManifest($projectDir, $buildResult, $provenance, $commitTimestamp);

        // Generate and embed the deterministic file manifest
        $fileManifest = $this->generateAndWriteFileManifest($projectDir);
        $fileManifestHash = $fileManifest->manifestHash();
        $fileManifestEntryCount = $fileManifest->entryCount();
        $fileManifestTotalSize = $fileManifest->totalSize();

        $finalZipPath = null;
        try {
            $onStage?->__invoke('ZIP generation started');
            $finalZipPath = self::buildDeterministicZip($projectDir, $workspaceRoot, $commitTimestamp);
            $onStage?->__invoke('ZIP generation completed');

            $size = @filesize($finalZipPath);
            if ($size === false || $size === 0) {
                throw new LocalBuildFailedException('artifact_build_failed', 'The assembled artifact is empty.');
            }

            $this->verifySealedDependencyLocks($finalZipPath, $provenance['dependency_locks'] ?? []);
            $onStage?->__invoke('checksum calculation started');
            $checksum = hash_file('sha256', $finalZipPath);
            if ($checksum === false) {
                throw new LocalBuildFailedException('artifact_build_failed', 'Could not compute a checksum for the assembled artifact.');
            }

            $onStage?->__invoke('checksum calculation completed');

            return [
                'path' => $finalZipPath,
                'checksum' => $checksum,
                'size' => $size,
                'requiredPaths' => $this->requiredPaths($projectDir, $buildResult),
                'file_manifest_hash' => $fileManifestHash,
                'file_manifest_entry_count' => $fileManifestEntryCount,
                'file_manifest_total_size' => $fileManifestTotalSize,
            ];
        } catch (\Throwable $e) {
            if ($finalZipPath !== null) {
                @unlink($finalZipPath);
            }

            throw $e;
        }
    }

    /**
     * Generate the deterministic file manifest and write it into the
     * project directory so it becomes part of the sealed artifact.
     */
    private function generateAndWriteFileManifest(string $projectDir): ArtifactFileManifest
    {
        $files = [];
        SafeFilesystem::collectFilesSafely(realpath($projectDir) ?? $projectDir, $projectDir, '', $files);

        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $manifestDir = "{$projectDir}/.deployment";
        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0755, true);
        }

        $manifestPath = "{$manifestDir}/" . ArtifactFileManifest::MANIFEST_FILENAME;
        file_put_contents($manifestPath, $manifest->toJson());

        return $manifest;
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
        $paths[] = '.deployment/release-manifest.json';
        if (is_file($projectDir . '/' . self::DEPENDENCY_LOCK_MARKER)) {
            $paths[] = self::DEPENDENCY_LOCK_MARKER;
        }
        return $paths;
    }

/**
         * Writes `.deployment/release-manifest.json` into the build workspace so
         * it becomes part of the sealed artifact. Describes the artifact that is
         * actually uploaded (not the developer working tree): the pinned commit,
         * the composer.lock digest, the required runtime paths, and the build
         * result. The Server Agent verifies this manifest during its
         * pre-activation release gate.
         *
         * @param array{project_key?: string, commit_sha?: string, branch?: string, agent_version?: string, source_paths?: array<int, string>, dependency_pinning_required?: bool, dependency_locks?: array<int, array<string, mixed>>} $provenance
         */
        private function writeReleaseManifest(string $projectDir, array $buildResult, array $provenance, int $commitTimestamp): void
        {
            $manifestDir = "{$projectDir}/.deployment";
            if (!is_dir($manifestDir)) {
                mkdir($manifestDir, 0755, true);
            }
            if (($provenance['dependency_pinning_required'] ?? false) === true) {
                // Durable protocol marker used by Hub dispatch after policy rollback.
                file_put_contents($projectDir . '/' . self::DEPENDENCY_LOCK_MARKER, "dependency-lock-manifest-v2\n");
            }
            $paths = array_values(array_unique(array_merge($this->requiredPaths($projectDir, $buildResult), ['.deployment/release-manifest.json', '.deployment/file-manifest.json'])));

            $manifest = [
                'format' => 'deployment-system-release-manifest',
                'version' => ($provenance['dependency_pinning_required'] ?? false) ? 2 : 1,
                'project_key' => $provenance['project_key'] ?? null,
                'commit_sha' => $provenance['commit_sha'] ?? null,
                'branch' => $provenance['branch'] ?? null,
                'composer_lock_sha256' => is_file("{$projectDir}/composer.lock") ? hash_file('sha256', "{$projectDir}/composer.lock") : '',
                'required_paths' => $paths,
                // Exact tracked-source inventory captured before composer/npm add
                // generated content. Persistence validation uses this immutable
                // list to distinguish application source from runtime data.
                'source_paths' => array_values($provenance['source_paths'] ?? []),
                'build_manifest_present' => is_file("{$projectDir}/public/build/manifest.json"),
                'build_result' => [
                    'ran_composer' => (bool) ($buildResult['ranComposer'] ?? false),
                    'ran_npm_build' => (bool) ($buildResult['ranNpmBuild'] ?? false),
                ],
                'agent_version' => $provenance['agent_version'] ?? 'unknown',
                'generated_at' => gmdate('c', $commitTimestamp),
                'status' => 'pass',
            ];
            if (($provenance['dependency_pinning_required'] ?? false) === true) {
                $manifest['dependency_lock_required'] = true;
                $manifest['dependency_locks'] = array_values($provenance['dependency_locks'] ?? []);
            }

            file_put_contents("{$manifestDir}/release-manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

    private function validateDependencyLocks(string $projectDir, array $locks, bool $sealed): void
    {
        foreach ($locks as $lock) {
            $path = $projectDir . '/' . ($lock['artifact_path'] ?? '');
            $resolved = realpath($path);
            if ($resolved === false) throw new LocalBuildFailedException('artifact_build_failed', 'Pinned Composer dependency is missing from vendor.');
            if ($sealed) PinnedDependencyMaterializer::assertOrdinaryTree($path);
            PinnedDependencyMaterializer::assertOrdinaryTree($resolved);
            if (!hash_equals((string) ($lock['checksum_sha256'] ?? ''), PinnedDependencyMaterializer::contentChecksum($resolved))) {
                throw new LocalBuildFailedException('artifact_build_failed', 'Composer materialized dependency differs from its exact pinned Git tree.');
            }
        }
    }

    private function verifySealedDependencyLocks(string $zipPath, array $locks): void
    {
        if ($locks === []) return;
        $root = sys_get_temp_dir() . '/la-verify-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        try {
            SafeFilesystem::extractZipSafely($zipPath, $root);
            $this->validateDependencyLocks($root, $locks, true);
        } finally {
            SafeFilesystem::recursiveDelete($root);
        }
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
