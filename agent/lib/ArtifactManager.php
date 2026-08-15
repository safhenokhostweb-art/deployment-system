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
    ) {
    }

    /**
     * @param array{public_id: string, checksum_sha256: string, size_bytes: int} $artifactDescriptor
     * @param array<int, string> $preservePaths project-relative paths to carry over from the currently-active version
     * @return array{status: 'succeeded'|'failed', message: ?string, failureCode: ?string}
     */
    public function deploy(array $artifactDescriptor, string $workingDirectory, array $preservePaths, int $retentionCount, ?callable $onTick = null): array
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
            return $this->failed('artifact_download_failed', "Artifact download failed: HTTP {$downloadResult['status']}" . ($downloadResult['error'] !== null ? " ({$downloadResult['error']})" : '') . '.');
        }

        $actualChecksum = hash_file('sha256', $tempArchivePath);
        if ($actualChecksum !== $artifactDescriptor['checksum_sha256']) {
            @unlink($tempArchivePath);

            return $this->failed('artifact_checksum_mismatch', "Downloaded artifact checksum mismatch: expected {$artifactDescriptor['checksum_sha256']}, got {$actualChecksum}.");
        }

        $stagingPath = rtrim($workingDirectory, '/') . '.staging-' . bin2hex(random_bytes(4));

        try {
            $zip = new \ZipArchive();
            $openResult = $zip->open($tempArchivePath);
            if ($openResult !== true) {
                return $this->failed('artifact_extract_failed', "Could not open downloaded artifact as a zip (error code {$openResult}).");
            }

            mkdir($stagingPath, 0755, true);
            $extracted = $zip->extractTo($stagingPath);
            $zip->close();

            if (!$extracted) {
                self::recursiveDelete($stagingPath);

                return $this->failed('artifact_extract_failed', 'Failed to extract the downloaded artifact.');
            }

            if (self::isEffectivelyEmpty($stagingPath)) {
                self::recursiveDelete($stagingPath);

                return $this->failed('artifact_extract_failed', 'Extracted artifact is empty — refusing to activate it.');
            }

            $missingPath = $this->firstMissingRequiredPath($stagingPath, $artifactDescriptor['required_paths'] ?? []);
            if ($missingPath !== null) {
                self::recursiveDelete($stagingPath);

                return $this->failed('artifact_required_path_missing', "Artifact is incomplete: required runtime path '{$missingPath}' is missing. Live release was not changed.");
            }
        } finally {
            @unlink($tempArchivePath);
        }

        $this->copyPreservedPaths($workingDirectory, $stagingPath, $preservePaths);

        $swapResult = $this->atomicSwap($workingDirectory, $stagingPath);
        if ($swapResult !== null) {
            self::recursiveDelete($stagingPath);

            return $this->failed('activation_failed', $swapResult);
        }

        $this->cleanupOldBackups($workingDirectory, $retentionCount);

        return ['status' => 'succeeded', 'message' => null, 'failureCode' => null];
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
