<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * RuntimeSnapshotCollector — collects the Agent's local runtime evidence
 * for inclusion in the signed heartbeat payload.
 *
 * Reads from the local release directory structure (current symlink,
 * release manifest, runtime marker) and produces a payload that
 * conforms to sentinel.runtime.v1 contract.
 *
 * Invariants:
 *  - NEVER reads .env files.
 *  - NEVER reads secrets, tokens, cookies, or credentials.
 *  - NEVER reports arbitrary filesystem paths (only release root).
 *  - NEVER sends full release file contents.
 *  - NEVER writes to the release path.
 *  - NEVER triggers restart, deploy, or upload.
 *  - If manifest or marker not found -> snapshot_status = incomplete.
 */
final class RuntimeSnapshotCollector
{
    public function __construct(
        private readonly string $releaseRoot,
        private readonly string $deploymentId,
        private readonly string $projectKey,
        private readonly string $environment,
        private readonly string $releaseId,
        private readonly string $agentId,
        private readonly string $agentVersion,
        private readonly ?string $artifactPath = null,
        private readonly ?string $proofRunRoot = null,
    ) {
    }

    /**
     * Collect the runtime snapshot and return an array suitable for
     * sentinel.runtime.v1 contract validation.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $currentReleasePath = $this->readCurrentReleasePath();
        $runtimeMarker = $currentReleasePath !== null
            ? $this->readRuntimeMarker($currentReleasePath)
            : null;
        $releaseManifestCommitSha = $currentReleasePath !== null
            ? $this->readReleaseManifestCommitSha($currentReleasePath)
            : null;
        $artifactChecksum = $this->readArtifactChecksum();

        $hasManifest = $releaseManifestCommitSha !== null;
        $hasMarker = $runtimeMarker !== null;

        $snapshotStatus = ($hasManifest && $hasMarker)
            ? 'complete'
            : 'incomplete';

        return [
            'evidence_version' => 'sentinel.runtime.v1',
            'agent_id' => $this->agentId,
            'agent_version' => $this->agentVersion,
            'project_key' => $this->projectKey,
            'environment' => $this->environment,
            'release_id' => $this->releaseId,
            'deployment_id' => $this->deploymentId,
            'current_release_path' => $currentReleasePath !== null
                ? 'releases/' . basename($currentReleasePath)
                : null,
            'runtime_marker' => $runtimeMarker,
            'release_manifest_commit_sha' => $releaseManifestCommitSha,
            'artifact_checksum_sha256' => $artifactChecksum,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'sequence' => $this->generateSequence(),
            'nonce' => Security::nonce(),
            'snapshot_status' => $snapshotStatus,
        ];
    }

    /**
     * Read and canonicalize the current symlink target.
     * Validates that it resolves within the releases/ directory.
     */
    private function readCurrentReleasePath(): ?string
    {
        $currentSymlink = rtrim($this->releaseRoot, '/') . '/current';

        if (! is_link($currentSymlink)) {
            return null;
        }

        $target = readlink($currentSymlink);
        if ($target === false || $target === '') {
            return null;
        }

        // Canonicalize: resolve to absolute path
        if (preg_match('~(^[\\\\/]{2}|(^|[\\\\/])\.\.([\\\\/]|$)|^[A-Za-z]:(?![\\\\/]))~', $target)) {
            return null;
        }
        $canonical = realpath($currentSymlink);
        if ($canonical === false) {
            return null;
        }

        // Validate path containment: must be under releases/ directory
        $releasesDir = realpath(rtrim($this->releaseRoot, '/') . '/releases');
        if ($releasesDir === false || ! str_starts_with($canonical, $releasesDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        // The directory name must match the deployment public_id
        $dirName = basename($canonical);
        if (! hash_equals($this->deploymentId, $dirName)
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $dirName)
            || realpath($releasesDir . DIRECTORY_SEPARATOR . $dirName) !== $canonical) {
            return null;
        }

        return $canonical;
    }

    /**
     * Read the runtime marker from the active release.
     * Format: release:{deployment_public_id}|commit:{commit_sha}
     */
    private function readRuntimeMarker(string $releasePath): ?string
    {
        $markerPath = rtrim($releasePath, '/') . '/.deployment/runtime-marker';
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

        // Validate format: release:{id}|commit:{sha}
        if (! preg_match('/^release:[a-z0-9-]+\|commit:[0-9a-f]{40}$/i', $marker)) {
            return null;
        }

        return $marker;
    }

    /**
     * Read the commit SHA from the release manifest.
     */
    private function readReleaseManifestCommitSha(string $releasePath): ?string
    {
        $manifestPath = rtrim($releasePath, '/') . '/.deployment/release-manifest.json';
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

    /**
     * Read the artifact checksum if available.
     * This would come from the artifact metadata the agent has access to.
     * For the Local Agent, this is typically not available on the server side.
     */
    private function readArtifactChecksum(): ?string
    {
        if ($this->artifactPath !== null && $this->proofRunRoot !== null) {
            $root = $this->validatedProofRoot();
            $artifact = realpath($this->artifactPath);
            if ($root !== null && $artifact !== false && is_file($artifact) && is_readable($artifact)
                && !is_link($this->artifactPath)
                && str_starts_with($artifact, $root . DIRECTORY_SEPARATOR)) {
                return @hash_file('sha256', $artifact) ?: null;
            }
            return null;
        }
        // The Local Agent (running on developer machine) doesn't have
        // access to the production server's artifact checksum.
        // This field is populated by the Server Agent (agent/) which
        // downloads and verifies the artifact.
        return null;
    }

    /**
     * Generate a monotonically increasing sequence number.
     * Uses a local counter file to persist across restarts.
     */
    private function generateSequence(): int
    {
        if ($this->proofRunRoot !== null) {
            $root = $this->validatedProofRoot();
            if ($root === null || !preg_match('/^[a-z0-9-]+$/iD', $this->agentId)) {
                throw new \RuntimeException('proof_sequence_root_unavailable');
            }
            $counterFile = $root . '/sequence-' . $this->agentId . '.counter';
            if (is_link($counterFile)) { throw new \RuntimeException('proof_sequence_link_rejected'); }
            $handle = fopen($counterFile, 'c+');
            if ($handle === false) { throw new \RuntimeException('proof_sequence_unavailable'); }
            try {
                if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('proof_sequence_lock_failed'); }
                $previous = trim((string) stream_get_contents($handle));
                if ($previous !== '' && (!ctype_digit($previous) || (int) $previous >= PHP_INT_MAX)) {
                    throw new \RuntimeException('proof_sequence_invalid');
                }
                $next = (int) $previous + 1;
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, (string) $next) !== strlen((string) $next)) {
                    throw new \RuntimeException('proof_sequence_write_failed');
                }
                fflush($handle);
                return $next;
            } finally { flock($handle, LOCK_UN); fclose($handle); }
        } else {
            $counterFile = sys_get_temp_dir() . '/deployment-agent-sequence-' . $this->agentId . '.counter';
        }
        $sequence = 1;

        if (is_file($counterFile)) {
            $content = @file_get_contents($counterFile);
            if ($content !== false) {
                $sequence = max(1, (int) trim($content) + 1);
            }
        }

        @file_put_contents($counterFile, (string) $sequence);

        return $sequence;
    }

    private function validatedProofRoot(): ?string
    {
        if ($this->proofRunRoot === null || is_link($this->proofRunRoot)) { return null; }
        $root = realpath($this->proofRunRoot);
        $temp = realpath(sys_get_temp_dir());
        if ($root === false || $temp === false) { return null; }
        $normalized = str_replace('\\', '/', $root);
        $prefix = str_replace('\\', '/', $temp) . '/SentinelProof/';
        if (!str_starts_with($normalized, $prefix)
            || !preg_match('/^[0-9a-f-]{36}$/D', substr($normalized, strlen($prefix)))) { return null; }
        $marker = $root . '/run.json';
        if (!is_file($marker) || !is_readable($marker) || is_link($marker)) { return null; }
        $metadata = json_decode((string) @file_get_contents($marker), true);
        return ($metadata['root'] ?? null) === $normalized ? $root : null;
    }
}
