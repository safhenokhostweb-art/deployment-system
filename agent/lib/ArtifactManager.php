<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly — direct connect
 * and SSH on 22/443 both time out after ~135s): downloads the artifact
 * the Local Agent already built and uploaded to the Hub — a connection
 * this agent already needs for polling — instead of ever running `git
 * fetch`/`git reset` against GitHub. Extracts to a staging directory,
 * preserves runtime files a fresh checkout never contains (.env,
 * storage, uploads), then atomically swaps it into place — the
 * previously-active version is renamed aside, never deleted until a
 * later successful deploy pushes it out of the retention window, so a
 * failure at any point before the final swap leaves the live site
 * completely untouched.
 */
final class ArtifactManager
{
    /** Used whenever a Project has no explicit artifact_preserve_paths — the runtime files a fresh `git archive` extraction never contains at all. */
    public const DEFAULT_PRESERVE_PATHS = ['.env', 'storage', 'public/storage'];

    public function __construct(
        private readonly Client $client,
        private readonly ?ReleaseValidator $releaseValidator = null,
    ) {
    }

    /**
     * Legacy convenience wrapper that prepares and immediately activates a
     * staged artifact — kept for direct callers (and the existing agent
     * test harness) that do not need the two-phase maintenance-first
     * lifecycle. Production PipelineExecutor now uses prepare()/activate()
     * separately so maintenance can be entered before the live swap.
     *
     * @param array{public_id: string, checksum_sha256: string, size_bytes: int} $artifactDescriptor
     * @param array<int, string> $preservePaths project-relative paths to carry over from the currently-active version (copy semantics, unchanged)
     * @param array<int|string, string> $persistentPaths project-relative paths that live OUTSIDE the swappable release and are referenced via symlink, never copied. Associative entries map release path => persistent-root-relative target.
     * @param string|null $persistentRoot trusted absolute persistent root (signed Hub contract, with server-local compatibility fallback)
     * @return array{status: 'succeeded'|'failed', message: ?string, failureCode: ?string}
     */
    public function deploy(array $artifactDescriptor, string $workingDirectory, array $preservePaths, int $retentionCount, array $persistentPaths = [], ?string $persistentRoot = null, ?callable $onTick = null, ?string $phpBinary = null): array
    {
        $prepared = $this->prepare($artifactDescriptor, $workingDirectory, $preservePaths, $persistentPaths, $persistentRoot, $onTick, $phpBinary);

        if ($prepared['status'] !== 'succeeded' || $prepared['stagingPath'] === null) {
            return ['status' => 'failed', 'message' => $prepared['message'], 'failureCode' => $prepared['failureCode']];
        }

        return $this->activate($workingDirectory, $prepared['stagingPath'], $retentionCount, $persistentPaths, $persistentRoot);
    }

    /**
     * Phase 1 of the two-phase artifact lifecycle. Downloads, verifies,
     * extracts, carries over legacy preserve paths, and installs persistent
     * references into a staging directory. NO live release mutation happens
     * here — the current release stays fully active until activate() is
     * called, so every failure in this phase leaves the live site untouched.
     *
     * @param array{public_id: string, checksum_sha256: string, size_bytes: int} $artifactDescriptor
     * @param array<int, string> $preservePaths
     * @param array<int|string, string> $persistentPaths
     * @return array{status: 'succeeded'|'failed', stagingPath: string|null, message: ?string, failureCode: ?string}
     */
    public function prepare(array $artifactDescriptor, string $workingDirectory, array $preservePaths, array $persistentPaths = [], ?string $persistentRoot = null, ?callable $onTick = null, ?string $phpBinary = null): array
    {
        $tempArchivePath = tempnam(sys_get_temp_dir(), 'da-artifact-') . '.zip';
        @unlink($tempArchivePath);

        $downloadResult = $this->client->downloadToFile("/api/agent/artifacts/{$artifactDescriptor['public_id']}/download", $tempArchivePath);
        if ($onTick !== null) {
            try {
                $onTick();
            } catch (\Throwable) {
            }
        }

        if ($downloadResult['status'] !== 200) {
            return ['status' => 'failed', 'stagingPath' => null, 'message' => "Artifact download failed: HTTP {$downloadResult['status']}" . ($downloadResult['error'] !== null ? " ({$downloadResult['error']})" : '') . '.', 'failureCode' => 'artifact_download_failed'];
        }

        $actualChecksum = hash_file('sha256', $tempArchivePath);
        if ($actualChecksum !== $artifactDescriptor['checksum_sha256']) {
            @unlink($tempArchivePath);

            return ['status' => 'failed', 'stagingPath' => null, 'message' => "Downloaded artifact checksum mismatch: expected {$artifactDescriptor['checksum_sha256']}, got {$actualChecksum}.", 'failureCode' => 'artifact_checksum_mismatch'];
        }

        $stagingPath = rtrim($workingDirectory, '/') . '.staging-' . bin2hex(random_bytes(4));

        try {
            $zip = new \ZipArchive();
            $openResult = $zip->open($tempArchivePath);
            if ($openResult !== true) {
                return ['status' => 'failed', 'stagingPath' => null, 'message' => "Could not open downloaded artifact as a zip (error code {$openResult}).", 'failureCode' => 'artifact_extract_failed'];
            }

            mkdir($stagingPath, 0755, true);
            $extracted = $zip->extractTo($stagingPath);
            $zip->close();

            if (!$extracted) {
                self::recursiveDelete($stagingPath);

                return ['status' => 'failed', 'stagingPath' => null, 'message' => 'Failed to extract the downloaded artifact.', 'failureCode' => 'artifact_extract_failed'];
            }

            if (self::isEffectivelyEmpty($stagingPath)) {
                self::recursiveDelete($stagingPath);

                return ['status' => 'failed', 'stagingPath' => null, 'message' => 'Extracted artifact is empty — refusing to activate it.', 'failureCode' => 'artifact_extract_failed'];
            }

            $missingPath = $this->firstMissingRequiredPath($stagingPath, $artifactDescriptor['required_paths'] ?? []);
            if ($missingPath !== null) {
                self::recursiveDelete($stagingPath);

                return ['status' => 'failed', 'stagingPath' => null, 'message' => "Artifact is incomplete: required runtime path '{$missingPath}' is missing. Live release was not changed.", 'failureCode' => 'artifact_required_path_missing'];
            }

            // Root-cause fix (Laravel release completeness): before ANY
            // preserve-copy / persistent-reference / activation work, run
            // the fail-closed release-completeness gate against the final
            // staged tree. The gate derives its requirements from the tree
            // itself (composer.json/lock, vendor/autoload.php,
            // vendor/composer/, the artisan bootstrap chain, every declared
            // package, the Vite build manifest, junction/symlink escapes,
            // an isolated autoload boot) and never fails open on a missing
            // required_paths contract. A failure here discards staging and
            // leaves the currently-active release completely untouched.
            $validator = $this->releaseValidator ?? new ReleaseValidator($phpBinary ?? 'php');
            $validation = $validator->validate($stagingPath, (string) ($artifactDescriptor['commit_hash'] ?? ''), $artifactDescriptor['required_paths'] ?? [], ($artifactDescriptor['dependency_lock_required'] ?? false) === true, (string) ($artifactDescriptor['application_root'] ?? '.'));
            if (!$validation['pass']) {
                self::recursiveDelete($stagingPath);

                return [
                    'status' => 'failed',
                    'stagingPath' => null,
                    'message' => 'Release completeness validation failed before activation: ' . implode(', ', array_slice($validation['failures'], 0, 8)) . '. Live release was not changed.',
                    'failureCode' => 'artifact_release_incomplete',
                ];
            }
        } finally {
            @unlink($tempArchivePath);
        }

        // Legacy copy-style carry-over (unchanged): small effectively-static
        // files such as .env are copied from the current release.
        $effectivePreserve = $this->effectivePreservePaths($preservePaths);
        $this->copyPreservedPaths($workingDirectory, $stagingPath, $effectivePreserve);

        // Persistent references: never copied. The release exposes a symlink
        // to the trusted persistent root. Fails BEFORE activation when a
        // target is missing so a missing DB/upload tree can never be
        // silently replaced by an empty reference.
        $persistentError = $this->preparePersistentReferences($stagingPath, $persistentPaths, $persistentRoot, $effectivePreserve);
        if ($persistentError !== null) {
            self::recursiveDelete($stagingPath);

            return ['status' => 'failed', 'stagingPath' => null, 'message' => $persistentError['message'], 'failureCode' => $persistentError['code']];
        }

        return ['status' => 'succeeded', 'stagingPath' => $stagingPath, 'message' => null, 'failureCode' => null];
    }

    /**
     * Phase 2 of the two-phase artifact lifecycle: atomically swaps the
     * prepared staging directory into the live path (the previously-active
     * release is renamed aside, never deleted until retention prunes it),
     * then prunes old backups to the retention window. Backups contain code
     * and symlinks only — persistent targets are never inside a release, so
     * retention can never delete them.
     *
     * @return array{status: 'succeeded'|'failed', message: ?string, failureCode: ?string}
     */
    public function activate(string $workingDirectory, string $stagingPath, int $retentionCount, array $persistentPaths = [], ?string $persistentRoot = null): array
    {
        $swapResult = $this->atomicSwap($workingDirectory, $stagingPath);
        if ($swapResult !== null) {
            self::recursiveDelete($stagingPath);

            return ['status' => 'failed', 'message' => $swapResult, 'failureCode' => 'activation_failed'];
        }

        // Rename is atomic, but the served path is the contract that matters.
        // Re-validate every installed reference after the swap and before old
        // backups can be pruned. A filesystem race or altered link therefore
        // restores the previous release rather than producing false success.
        $persistentError = $this->validatePersistentReferences($workingDirectory, $persistentPaths, $persistentRoot);
        if ($persistentError !== null) {
            $rollbackError = $this->rollback($workingDirectory);
            $rollbackSuffix = $rollbackError === null
                ? ' The previous release was restored.'
                : " Automatic restore failed: {$rollbackError}";

            return [
                'status' => 'failed',
                'message' => $persistentError['message'] . $rollbackSuffix,
                'failureCode' => $persistentError['code'],
            ];
        }

        $this->cleanupOldBackups($workingDirectory, $retentionCount);

        return ['status' => 'succeeded', 'message' => null, 'failureCode' => null];
    }

    /**
     * Deletes a prepared staging directory that will never be activated
     * (the pre-activation failure path). Only ever touches a staging sibling
     * of the working directory — never a live release, never a persistent
     * target (staging contains only code + unlinked symlinks at this point).
     */
    public function discardStaging(string $stagingPath): void
    {
        if ($stagingPath !== '' && is_dir($stagingPath)) {
            self::recursiveDelete($stagingPath);
        }
    }

    /**
     * Best-effort code rollback: restores the newest backup as the live
     * release and removes the failed activated release. Persistent targets
     * are never touched — the restored release's symlinks reference the same
     * persistent state, so the database/storage/upload data stays current.
     *
     * @return string|null null on success, an error message when nothing could be restored
     */
    public function rollback(string $workingDirectory): ?string
    {
        $parent = dirname($workingDirectory);
        $prefix = basename($workingDirectory) . '.backup-';

        if (!is_dir($parent)) {
            return 'No release parent directory to roll back within.';
        }

        $backups = [];
        foreach (scandir($parent) ?: [] as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $backups[] = $parent . '/' . $entry;
            }
        }

        if ($backups === []) {
            return 'No previous release backup exists to restore.';
        }

        usort($backups, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $backup = $backups[0];

        $failedLive = rtrim($workingDirectory, '/') . '.failed-' . bin2hex(random_bytes(4));

        if (!@rename($workingDirectory, $failedLive)) {
            return "Could not move the activated release aside for rollback (target: {$failedLive}).";
        }

        if (!@rename($backup, $workingDirectory)) {
            @rename($failedLive, $workingDirectory);

            return "Could not restore the previous release from {$backup} — the activated release was put back.";
        }

        self::recursiveDelete($failedLive);

        return null;
    }

    /**
     * Verifies the release-visible side of the signed persistence contract.
     * This performs no mutation and requires every entry to remain a symlink
     * resolving to its exact configured target after activation.
     *
     * @param array<int|string, string> $persistentPaths
     * @return array{code: string, message: string}|null
     */
    private function validatePersistentReferences(string $releasePath, array $persistentPaths, ?string $persistentRoot): ?array
    {
        if ($persistentPaths === []) {
            return null;
        }

        if (!is_string($persistentRoot) || trim($persistentRoot) === '') {
            return ['code' => 'artifact_persistent_root_missing', 'message' => 'Persistent root is missing during post-activation validation.'];
        }

        $rootReal = self::canonicalRealPath(rtrim($persistentRoot, '/'));
        if ($rootReal === false || !is_dir($rootReal)) {
            return ['code' => 'artifact_persistent_root_missing', 'message' => "Persistent root '{$persistentRoot}' is missing during post-activation validation."];
        }

        foreach ($persistentPaths as $releaseKey => $configuredTargetPath) {
            $relativePath = is_string($releaseKey) ? $releaseKey : $configuredTargetPath;
            $normalized = rtrim(str_replace('\\', '/', trim($relativePath)), '/');
            $targetNormalized = rtrim(str_replace('\\', '/', trim($configuredTargetPath)), '/');
            $link = rtrim($releasePath, '/') . '/' . $normalized;
            $expected = self::canonicalRealPath(rtrim($rootReal, '/') . '/' . $targetNormalized);

            if (!is_link($link)) {
                return ['code' => 'artifact_persistent_reference_invalid', 'message' => "Activated persistent path '{$link}' is not a symlink."];
            }

            $actual = self::canonicalRealPath($link);
            if ($expected === false || $actual === false || $actual !== $expected) {
                return ['code' => 'artifact_persistent_reference_invalid', 'message' => "Activated persistent path '{$link}' does not resolve to its configured target."];
            }
        }

        return null;
    }

    /** @param array<int, mixed> $requiredPaths */
    private function firstMissingRequiredPath(string $stagingPath, array $requiredPaths): ?string
    {
        foreach ($requiredPaths as $path) {
            if (!is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
                return '(invalid required path contract)';
            }
            if (!file_exists(rtrim($stagingPath, '/') . '/' . $path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Copies each configured project-relative path from the currently-
     * active version into the new staging directory before activation —
     * a fresh `git archive` extraction never contains any of these (by
     * construction: they're gitignored, so never tracked, so never
     * archived). Silently skipped when there is no currently-active
     * version yet (the very first deploy of a project) — nothing to
     * carry over.
     *
     * @param array<int, string> $preservePaths
     */
    private function copyPreservedPaths(string $workingDirectory, string $stagingPath, array $preservePaths): void
    {
        if (!is_dir($workingDirectory)) {
            return;
        }

        $paths = $preservePaths === [] ? self::DEFAULT_PRESERVE_PATHS : $preservePaths;

        foreach ($paths as $relativePath) {
            $source = rtrim($workingDirectory, '/') . '/' . ltrim($relativePath, '/');
            $destination = rtrim($stagingPath, '/') . '/' . ltrim($relativePath, '/');

            if (!file_exists($source)) {
                continue;
            }

            if (is_dir($destination)) {
                self::recursiveDelete($destination);
            } elseif (file_exists($destination)) {
                @unlink($destination);
            }

            if (is_dir($source)) {
                self::copyRecursive($source, $destination);
            } else {
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0755, true);
                }
                copy($source, $destination);
            }
        }
    }

    /** @return array<int, string> */
    private function effectivePreservePaths(array $preservePaths): array
    {
        return $preservePaths === [] ? self::DEFAULT_PRESERVE_PATHS : $preservePaths;
    }

    /**
     * Installs release-visible persistent references in the staging release.
     * Each configured project-relative path becomes a symlink inside staging
     * pointing at {persistentRoot}/{path} — never a copy. Fail-closed:
     * an invalid path, a missing/escaping target, or an overlap with the
     * effective preserve list aborts BEFORE activation.
     *
     * @param array<int|string, string> $persistentPaths
     * @param array<int, string> $effectivePreservePaths
     * @return array{code: string, message: string}|null null on success
     */
    private function preparePersistentReferences(string $stagingPath, array $persistentPaths, ?string $persistentRoot, array $effectivePreservePaths): ?array
    {
        if ($persistentPaths === []) {
            return null;
        }

        if (!is_string($persistentRoot) || trim($persistentRoot) === '') {
            return ['code' => 'artifact_persistent_root_missing', 'message' => 'artifact_persistent_root must be configured when artifact_persistent_paths are set.'];
        }

        $rootReal = self::canonicalRealPath(rtrim($persistentRoot, '/'));
        if ($rootReal === false || !is_dir($rootReal)) {
            return ['code' => 'artifact_persistent_root_missing', 'message' => "Persistent root '{$persistentRoot}' does not exist — refusing to activate without persistent state."];
        }

        $rootPrefix = rtrim($rootReal, '/') . '/';
        $sourcePaths = $this->manifestSourcePaths($stagingPath);
        if (isset($sourcePaths['error'])) {
            return ['code' => 'artifact_persistent_source_contract_invalid', 'message' => $sourcePaths['error']];
        }

        // Validate the complete mapping set before creating even one staging
        // symlink. Ancestor/descendant mappings are ambiguous and, if applied
        // sequentially, a nested entry could traverse through an earlier
        // staging symlink into persistent data.
        $persistentPathOverlap = self::persistentPathOverlapError($persistentPaths);
        if ($persistentPathOverlap !== null) {
            return ['code' => 'artifact_persistent_overlap', 'message' => $persistentPathOverlap];
        }
        $preserveOverlap = self::persistentOverlapError($effectivePreservePaths, $persistentPaths);
        if ($preserveOverlap !== null) {
            return ['code' => 'artifact_persistent_overlap', 'message' => $preserveOverlap];
        }

        foreach ($persistentPaths as $releaseKey => $configuredTargetPath) {
            $relativePath = is_string($releaseKey) ? $releaseKey : $configuredTargetPath;
            $targetRelativePath = $configuredTargetPath;

            $error = self::validateRelativePersistentPath($relativePath);
            if ($error !== null) {
                return ['code' => 'artifact_persistent_path_invalid', 'message' => "Invalid persistent path '{$relativePath}': {$error}."];
            }

            $targetError = self::validateRelativePersistentPath($targetRelativePath);
            if ($targetError !== null) {
                return ['code' => 'artifact_persistent_path_invalid', 'message' => "Invalid persistent target path '{$targetRelativePath}': {$targetError}."];
            }

            $normalized = rtrim(str_replace('\\', '/', trim($relativePath)), '/');
            $targetNormalized = rtrim(str_replace('\\', '/', trim($targetRelativePath)), '/');
            if ($normalized === '') {
                return ['code' => 'artifact_persistent_path_invalid', 'message' => 'Persistent path must not be empty.'];
            }

            $target = $rootPrefix . $targetNormalized;
            $targetReal = self::canonicalRealPath($target);
            if ($targetReal === false || !file_exists($targetReal)) {
                return ['code' => 'artifact_persistent_target_missing', 'message' => "Persistent target '{$target}' does not exist — refusing to activate (missing persistent state would be silently replaced). Create it first (installer/runtime helper)."];
            }

            if ($targetReal !== $rootReal && !str_starts_with($targetReal, $rootPrefix)) {
                return ['code' => 'artifact_persistent_path_invalid', 'message' => "Persistent target '{$target}' escapes the persistent root."];
            }

            $sourceOverlap = self::persistentSourceOverlap($normalized, $sourcePaths['paths']);
            if ($sourceOverlap !== null) {
                return [
                    'code' => 'artifact_persistent_source_overlap',
                    'message' => "Persistent path '{$normalized}' would hide tracked artifact source '{$sourceOverlap}'. Configure the specific runtime file or a source-free runtime directory instead.",
                ];
            }

            $stagingEntry = rtrim($stagingPath, '/') . '/' . $normalized;

            // The artifact may legitimately ship content at a persistent path
            // (e.g. an empty storage/ dir baked into the archive). Remove ONLY
            // that release-side staging entry — never anything external — and
            // replace it with the reference. recursiveDelete() unlinks
            // symlinks without following them, so a malicious zip entry can
            // never redirect deletion outside staging.
            if (is_link($stagingEntry)) {
                @unlink($stagingEntry);
            } elseif (is_dir($stagingEntry)) {
                self::recursiveDelete($stagingEntry);
            } elseif (file_exists($stagingEntry)) {
                @unlink($stagingEntry);
            }

            $parent = dirname($stagingEntry);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            if (!@symlink($targetReal, $stagingEntry)) {
                return ['code' => 'artifact_persistent_path_invalid', 'message' => "Could not create persistent reference for '{$normalized}' in the staging release."];
            }

            $linkReal = self::canonicalRealPath($stagingEntry);
            if ($linkReal === false || ($linkReal !== $rootReal && !str_starts_with($linkReal, $rootPrefix))) {
                @unlink($stagingEntry);

                return ['code' => 'artifact_persistent_path_invalid', 'message' => "Persistent reference '{$normalized}' failed confinement verification."];
            }
        }

        return null;
    }

    /** @param array<int|string, string> $persistentPaths */
    private static function persistentPathOverlapError(array $persistentPaths): ?string
    {
        $releasePaths = [];
        foreach ($persistentPaths as $releaseKey => $targetPath) {
            $releasePath = is_string($releaseKey) ? $releaseKey : $targetPath;
            $releasePaths[] = rtrim(str_replace('\\', '/', trim($releasePath)), '/');
        }

        for ($i = 0; $i < count($releasePaths); $i++) {
            for ($j = $i + 1; $j < count($releasePaths); $j++) {
                $left = $releasePaths[$i];
                $right = $releasePaths[$j];
                if ($left === $right
                    || str_starts_with($left . '/', $right . '/')
                    || str_starts_with($right . '/', $left . '/')) {
                    return "Persistent release paths '{$left}' and '{$right}' overlap; configure only the narrowest runtime-owned path.";
                }
            }
        }

        return null;
    }

    /**
     * Older manifests intentionally remain compatible: absence of
     * source_paths means the pre-existing persistence behavior is retained.
     * Once present, however, malformed entries fail closed.
     *
     * @return array{paths: array<int, string>, error?: string}
     */
    private function manifestSourcePaths(string $stagingPath): array
    {
        $manifestPath = rtrim($stagingPath, '/') . '/' . ReleaseValidator::IN_ARTIFACT_MANIFEST_PATH;
        if (!is_file($manifestPath)) {
            return ['paths' => []];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !array_key_exists('source_paths', $manifest)) {
            return ['paths' => []];
        }
        if (!is_array($manifest['source_paths'])) {
            return ['paths' => [], 'error' => 'Artifact release manifest source_paths must be an array.'];
        }

        $paths = [];
        foreach ($manifest['source_paths'] as $path) {
            if (!is_string($path) || self::validateRelativePersistentPath($path) !== null) {
                return ['paths' => [], 'error' => 'Artifact release manifest contains an invalid tracked source path.'];
            }
            $normalized = rtrim(str_replace('\\', '/', trim($path)), '/');
            if ($normalized === '') {
                return ['paths' => [], 'error' => 'Artifact release manifest contains an empty tracked source path.'];
            }
            $paths[] = $normalized;
        }

        return ['paths' => array_values(array_unique($paths))];
    }

    /** @param array<int, string> $sourcePaths */
    private static function persistentSourceOverlap(string $persistentPath, array $sourcePaths): ?string
    {
        foreach ($sourcePaths as $sourcePath) {
            if ($sourcePath === $persistentPath) {
                return $sourcePath;
            }
            if (str_starts_with($sourcePath, $persistentPath . '/')) {
                $basename = basename($sourcePath);
                if (in_array($basename, ['.gitignore', '.gitkeep'], true)) {
                    continue;
                }

                return $sourcePath;
            }
        }

        return null;
    }

    private static function canonicalRealPath(string $path): string|false
    {
        $real = realpath($path);

        return $real === false ? false : str_replace('\\', '/', $real);
    }

    /**
     * @return string|null an actionable error when a persistent path overlaps
     *                     a preserve path, null when the two contracts are disjoint
     * @param array<int, string> $preservePaths
     * @param array<int|string, string> $persistentPaths
     */
    private static function persistentOverlapError(array $preservePaths, array $persistentPaths): ?string
    {
        foreach ($persistentPaths as $releaseKey => $configuredTargetPath) {
            $persistent = is_string($releaseKey) ? $releaseKey : $configuredTargetPath;
            $p = rtrim(str_replace('\\', '/', trim($persistent)), '/');
            foreach ($preservePaths as $preserve) {
                $s = rtrim(str_replace('\\', '/', trim($preserve)), '/');
                if ($s === '' || $p === '') {
                    continue;
                }
                if ($p === $s) {
                    return "Persistent path '{$p}' exactly overlaps preserve path '{$s}' — remove it from one contract.";
                }
                if (str_starts_with($p . '/', $s . '/')) {
                    return "Persistent path '{$p}' is inside preserve path '{$s}' — overlapping contracts are ambiguous.";
                }
                if (str_starts_with($s . '/', $p . '/')) {
                    return "Preserve path '{$s}' is inside persistent path '{$p}' — overlapping contracts are ambiguous.";
                }
            }
        }

        return null;
    }

    /**
     * @return string|null an error describing the first rule a relative path
     *                     violates, null when the path is a valid relative,
     *                     non-traversing project path
     */
    private static function validateRelativePersistentPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return 'must not be empty';
        }
        if (str_starts_with($path, '/')) {
            return 'must be relative (no leading slash)';
        }
        if (str_starts_with($path, '\\')) {
            return 'must be relative (no leading separator)';
        }
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return 'must not be a Windows drive path';
        }
        if (str_contains($path, "\0")) {
            return 'must not contain NUL bytes';
        }
        if (preg_match('#(^|[\\\\\\/])\\.\\.([\\\\\\/]|$)#', $path) === 1) {
            return 'must not contain traversal segments';
        }

        return null;
    }

    /**
     * Renames the currently-active directory aside (never deletes it —
     * that's cleanupOldBackups()'s job, governed by retention count, not
     * this method) and moves staging into its place. If the FIRST rename
     * fails, nothing has changed — the live site is completely
     * untouched. If the SECOND rename fails (staging -> live path) after
     * the first succeeded, this attempts to immediately restore the
     * backup back to the live path before reporting failure, so a
     * same-filesystem rename failure (extremely rare in practice) still
     * can't leave the site down.
     *
     * @return string|null null on success, an error message on failure
     */
    private function atomicSwap(string $workingDirectory, string $stagingPath): ?string
    {
        $hadPrevious = is_dir($workingDirectory);
        $backupPath = rtrim($workingDirectory, '/') . '.backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        if ($hadPrevious) {
            if (!@rename($workingDirectory, $backupPath)) {
                return "Could not rename the current release aside to {$backupPath} — the live site was not touched.";
            }
        }

        if (!@rename($stagingPath, $workingDirectory)) {
            if ($hadPrevious) {
                @rename($backupPath, $workingDirectory);
            }

            return "Could not activate the new release at {$workingDirectory} — attempted to restore the previous version.";
        }

        return null;
    }

    /**
     * Keeps only the newest $retentionCount backups (`{workingDirectory}.backup-*`
     * siblings) — deleting older ones so disk usage never grows
     * unbounded. Manual rollback (renaming a backup back into place) is
     * a server-side operation outside this agent's scope for the
     * customer-site pipeline model; see the final report's rollback plan.
     */
    private function cleanupOldBackups(string $workingDirectory, int $retentionCount): void
    {
        $parent = dirname($workingDirectory);
        $prefix = basename($workingDirectory) . '.backup-';

        if (!is_dir($parent)) {
            return;
        }

        $backups = [];
        foreach (scandir($parent) ?: [] as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $backups[] = $parent . '/' . $entry;
            }
        }

        usort($backups, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        foreach (array_slice($backups, $retentionCount) as $stale) {
            self::recursiveDelete($stale);
        }
    }

    private static function isEffectivelyEmpty(string $dir): bool
    {
        return array_diff(scandir($dir) ?: [], ['.', '..']) === [];
    }

    private static function copyRecursive(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }

        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            $fromPath = "{$from}/{$entry}";
            $toPath = "{$to}/{$entry}";

            if (is_dir($fromPath)) {
                self::copyRecursive($fromPath, $toPath);
            } else {
                copy($fromPath, $toPath);
            }
        }
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

    /** @return array{status: 'failed', message: string, failureCode: string} */
    private function failed(string $failureCode, string $message): array
    {
        return ['status' => 'failed', 'message' => $message, 'failureCode' => $failureCode];
    }
}
