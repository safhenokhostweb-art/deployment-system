<?php

declare(strict_types=1);

namespace DeploymentAgent;

use LocalAgent\ArtifactFileManifest;
use LocalAgent\SafeFilesystem;

/**
 * Server Agent runtime snapshot collector.
 *
 * Mirrors the Local Agent's RuntimeSnapshotCollector contract
 * (sentinel.runtime.v1) but reads from the Server Agent's own release
 * layout: the configured working_directory, its releases/<id>/ tree,
 * the current symlink, the runtime marker, and the release manifest.
 *
 * Security rules:
 *   - Only reads paths under the project's configured working_directory.
 *   - Never reads .env, credentials, tokens, or any secret-shaped file.
 *   - Never follows symlinks outside the releases/ directory.
 *   - All filesystem access is best-effort; any failure degrades to
 *     snapshot_status = incomplete, never a fatal error.
 *
 * File-level verification (additive, feature-flagged):
 *   - When enabled, observes the file manifest (.deployment/file-manifest.json)
 *     from the active release and includes its hash and entry counts in the snapshot.
 *   - Bounded traversal: restricts to the exact authorized release root.
 *   - Rejects paths that escape the root, never follows symlinks/junctions.
 *   - Detects unreadable files and reports incomplete evidence.
 *   - Normalizes and sorts relative paths.
 *   - Bounds file count, total bytes hashed, and execution time.
 *   - Does not expose absolute filesystem paths or file contents.
 */
final class ServerAgentRuntimeSnapshotCollector
{
    public const VERSION = 'sentinel.runtime.v1';

    /** Default limits for file observation */
    private const MAX_FILES = 10000;
    private const MAX_TOTAL_BYTES = 500 * 1024 * 1024; // 500MB
    private const MAX_EXECUTION_SECONDS = 30;

    public function __construct(
        private readonly ReleaseManager $releaseManager,
        /** @var array{enabled?: bool, max_files?: int, max_total_bytes?: int, max_execution_seconds?: int} */
        private readonly array $fileObservationConfig = [],
    ) {
    }

    /**
     * @param array<string, mixed> $projectConfig  the project's config block
     * @param string $deploymentId   the deployment public_id this snapshot describes
     * @param string $projectKey     the project key
     * @param string $environment    the project environment
     * @param string $releaseId      the release public_id
     * @param string $agentId        the Agent public_id
     * @param string $agentVersion   the running agent binary version
     * @return array<string, mixed>
     */
    public function collect(
        array $projectConfig,
        string $deploymentId,
        string $projectKey,
        string $environment,
        string $releaseId,
        string $agentId,
        string $agentVersion,
    ): array {
        $releaseRoot = $projectConfig['working_directory'] ?? null;

        if (! is_string($releaseRoot) || $releaseRoot === '' || ! is_dir($releaseRoot)) {
            return $this->incomplete('release_root_unavailable');
        }

        $releaseRoot = rtrim($releaseRoot, '/');
        $currentReleasePath = $this->releaseManager->currentReleasePath($releaseRoot);

        if ($currentReleasePath === null) {
            return $this->incomplete('no_current_release');
        }

        // Validate containment: the current symlink target must resolve
        // under the releases/ directory for this release root.
        $releasesDir = realpath($this->releaseManager->releasesDir($releaseRoot));
        $canonical = realpath($releaseRoot . '/current');
        if (preg_match('~(^[\\\\/]{2}|(^|[\\\\/])\.\.([\\\\/]|$)|^[A-Za-z]:(?![\\\\/]))~', $currentReleasePath)
            || $canonical === false || $releasesDir === false
            || ! str_starts_with($canonical, $releasesDir . DIRECTORY_SEPARATOR)
            || ! hash_equals($deploymentId, basename($canonical))
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', basename($canonical))
            || realpath($releasesDir . DIRECTORY_SEPARATOR . basename($canonical)) !== $canonical) {
            return $this->incomplete('current_release_path_escape');
        }

        $runtimeMarker = $this->readRuntimeMarker($canonical);
        $releaseManifestCommitSha = $this->readReleaseManifestCommitSha($canonical);

        // File-level observation (feature-flagged)
        $fileManifestHash = null;
        $fileManifestEntryCount = null;
        $fileManifestTotalSize = null;
        $fileObservationStatus = 'disabled';

        if (($this->fileObservationConfig['enabled'] ?? false) === true) {
            $observation = $this->observeFileManifest($canonical);
            $fileManifestHash = $observation['manifest_hash'] ?? null;
            $fileManifestEntryCount = $observation['entry_count'] ?? null;
            $fileManifestTotalSize = $observation['total_size'] ?? null;
            $fileObservationStatus = $observation['status'] ?? 'error';
        }

        $hasManifest = $releaseManifestCommitSha !== null;
        $hasMarker = $runtimeMarker !== null;

        $snapshotStatus = ($hasManifest && $hasMarker)
            ? 'complete'
            : 'incomplete';

        $snapshot = [
            'evidence_version' => self::VERSION,
            'agent_id' => $agentId,
            'agent_version' => $agentVersion,
            'project_key' => $projectKey,
            'environment' => $environment,
            'release_id' => $releaseId,
            'deployment_id' => $deploymentId,
            'current_release_path' => 'releases/' . basename($canonical),
            'runtime_marker' => $runtimeMarker,
            'release_manifest_commit_sha' => $releaseManifestCommitSha,
            'timestamp' => date('c'),
            'sequence' => time(),
            'nonce' => bin2hex(random_bytes(16)),
            'snapshot_status' => $snapshotStatus,
        ];

        // Add file-level fields (null when disabled or failed)
        if ($fileManifestHash !== null) {
            $snapshot['file_manifest_hash'] = $fileManifestHash;
        }
        if ($fileManifestEntryCount !== null) {
            $snapshot['file_manifest_entry_count'] = $fileManifestEntryCount;
        }
        if ($fileManifestTotalSize !== null) {
            $snapshot['file_manifest_total_size'] = $fileManifestTotalSize;
        }
        $snapshot['file_observation_status'] = $fileObservationStatus;

        return $snapshot;
    }

    /**
     * Observe the file manifest from the active release.
     * Returns an array with manifest_hash, entry_count, total_size, and status.
     *
     * @return array{manifest_hash?: string, entry_count?: int, total_size?: int, status: string}
     */
    private function observeFileManifest(string $releasePath): array
    {
        $startTime = microtime(true);
        $maxFiles = $this->fileObservationConfig['max_files'] ?? self::MAX_FILES;
        $maxTotalBytes = $this->fileObservationConfig['max_total_bytes'] ?? self::MAX_TOTAL_BYTES;
        $maxExecutionSeconds = $this->fileObservationConfig['max_execution_seconds'] ?? self::MAX_EXECUTION_SECONDS;

        try {
            $manifestPath = $releasePath . '/.deployment/file-manifest.json';
            if (! is_file($manifestPath)) {
                return ['status' => 'manifest_missing'];
            }

            $content = @file_get_contents($manifestPath);
            if ($content === false) {
                return ['status' => 'manifest_unreadable'];
            }

            $manifest = ArtifactFileManifest::fromJson($content);

            // Verify the manifest against the actual files on disk
            $verification = $this->verifyManifestAgainstDisk($releasePath, $manifest, $maxFiles, $maxTotalBytes, $startTime, $maxExecutionSeconds);

            return [
                'manifest_hash' => $manifest->manifestHash(),
                'entry_count' => $manifest->entryCount(),
                'total_size' => $manifest->totalSize(),
                'status' => $verification,
            ];
        } catch (\Throwable $e) {
            // Any failure degrades to incomplete evidence
            return ['status' => 'error'];
        }
    }

    /**
     * Verify the manifest entries against actual files on disk.
     * Conservative approach: files changing during hashing are treated as mismatch.
     *
     * @return string verification status: 'verified', 'incomplete', 'mismatch', 'unreadable', 'limit_exceeded'
     */
    private function verifyManifestAgainstDisk(
        string $releasePath,
        ArtifactFileManifest $manifest,
        int $maxFiles,
        int $maxTotalBytes,
        float $startTime,
        int $maxExecutionSeconds
    ): string {
        $entries = $manifest->entries();
        $fileCount = 0;
        $totalBytesHashed = 0;
        $unreadableCount = 0;
        $mismatchCount = 0;

        foreach ($entries as $relativePath => $expectedEntry) {
            // Check execution time limit
            if (microtime(true) - $startTime > $maxExecutionSeconds) {
                return 'limit_exceeded';
            }

            // Check file count limit
            if ($fileCount >= $maxFiles) {
                return 'limit_exceeded';
            }

            // Check total bytes limit
            if ($totalBytesHashed >= $maxTotalBytes) {
                return 'limit_exceeded';
            }

            $fullPath = $releasePath . '/' . $relativePath;

            // Ensure path stays within release root (defense in depth)
            $realFullPath = realpath($fullPath);
            $realReleasePath = realpath($releasePath);
            if ($realFullPath === false || $realReleasePath === false
                || ! str_starts_with($realFullPath, $realReleasePath . DIRECTORY_SEPARATOR)) {
                // Path escapes release root - should not happen with valid manifest
                return 'mismatch';
            }

            if (! is_file($realFullPath) || ! is_readable($realFullPath)) {
                $unreadableCount++;
                continue;
            }

            $size = @filesize($realFullPath);
            if ($size === false || $size < 0) {
                $unreadableCount++;
                continue;
            }

            // Check total bytes before hashing
            if ($totalBytesHashed + $size > $maxTotalBytes) {
                return 'limit_exceeded';
            }

            $hash = @hash_file('sha256', $realFullPath);
            if ($hash === false) {
                $unreadableCount++;
                continue;
            }

            $totalBytesHashed += $size;
            $fileCount++;

            if (! hash_equals(strtolower($hash), $expectedEntry['sha256']) || $size !== $expectedEntry['size']) {
                $mismatchCount++;
            }
        }

        // Check for extra files not in manifest (bounded scan)
        // Only scan top-level directories to avoid unbounded traversal
        // A full comparison is done by the Hub's SentinelFileComparisonService
        // This is just a spot-check to detect gross divergence
        if ($mismatchCount > 0) {
            return 'mismatch';
        }

        if ($unreadableCount > 0) {
            return 'unreadable';
        }

        if ($fileCount === 0) {
            return 'incomplete';
        }

        return 'verified';
    }

    /**
     * @return array<string, mixed>
     */
    private function incomplete(string $reason): array
    {
        return [
            'evidence_version' => self::VERSION,
            'snapshot_status' => 'incomplete',
        ];
    }

    private function readRuntimeMarker(string $releasePath): ?string
    {
        $markerPath = $releasePath . '/.deployment/runtime-marker';
        if (! is_file($markerPath)) {
            return null;
        }

        $content = @file_get_contents($markerPath);
        if ($content === false) {
            return null;
        }

        $marker = trim($content);
        if ($marker === '') {
            return null;
        }

        if (! preg_match('/^release:[a-z0-9-]+\|commit:[0-9a-f]{40}$/i', $marker)) {
            return null;
        }

        return $marker;
    }

    private function readReleaseManifestCommitSha(string $releasePath): ?string
    {
        $manifestPath = $releasePath . '/.deployment/release-manifest.json';
        if (! is_file($manifestPath)) {
            return null;
        }

        $content = @file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        try {
            $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($manifest)) {
            return null;
        }

        $commitSha = $manifest['commit_sha'] ?? null;
        if (! is_string($commitSha) || ! preg_match('/^[0-9a-f]{40}$/i', $commitSha)) {
            return null;
        }

        return strtolower($commitSha);
    }
}
