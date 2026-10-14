<?php

declare(strict_types=1);

namespace DeploymentAgent;

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
 */
final class ServerAgentRuntimeSnapshotCollector
{
    public const VERSION = 'sentinel.runtime.v1';

    public function __construct(
        private readonly ReleaseManager $releaseManager,
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

        $hasManifest = $releaseManifestCommitSha !== null;
        $hasMarker = $runtimeMarker !== null;

        $snapshotStatus = ($hasManifest && $hasMarker)
            ? 'complete'
            : 'incomplete';

        return [
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
