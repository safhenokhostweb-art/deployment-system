<?php

namespace App\Services\Sentinel;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * AgentRuntimeEvidenceReader — the REAL read-only adapter for
 * AgentRuntimeReader.
 *
 * Reads the latest valid runtime snapshot from the Hub's own
 * authoritative records (Agent/LocalAgent heartbeat payloads).
 * Does NOT trust the Dashboard's textual status — it relies on
 * the signed payload the agent already sends on every heartbeat.
 *
 * Supports both Server Agent (Agent model) and Local Agent (LocalAgent model).
 *
 * Invariants:
 *  - READ-ONLY: issues no database writes, no outbound requests.
 *  - Returns UNKNOWN (null) when no valid snapshot exists.
 *  - Matches agent, project, environment and release.
 *  - Uses SentinelRuntimeEvidenceContract for validation.
 *  - Never writes independently to the database.
 *  - Never reconstructs snapshots from Hub records for LocalAgent
 *    (the Hub has no access to the developer's filesystem).
 */
final class AgentRuntimeEvidenceReader implements AgentRuntimeReader
{
    public function __construct(
        /**
         * Explicit opt-in for the local proof context only. Default false:
         * in the production Hub a Local Agent's source workspace is never
         * evidence of deployed runtime. The local proof Hub enables this
         * flag so it can read back the snapshot its own ingest validated
         * and persisted — no other validation is bypassed either way.
         */
        private readonly bool $allowLocalAgent = false,
    ) {}

    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelRuntimeSnapshot
    {
        // Resolve the agent (Server Agent or Local Agent) that serves this release/environment
        $agent = $this->resolveAgent($target, $expectation);
        if ($agent === null) {
            return null;
        }

        // Read the latest valid runtime snapshot from the agent's heartbeat payload
        $snapshotPayload = $this->readLatestValidSnapshot($agent, $target, $expectation);
        if ($snapshotPayload === null) {
            return null;
        }

        // Validate and parse through the contract
        try {
            $contract = SentinelRuntimeEvidenceContract::fromArray($snapshotPayload);
        } catch (\InvalidArgumentException) {
            // Invalid payload (secret-shaped, path escape, schema violation) -> treat as no snapshot
            return null;
        }

        if (! $contract->isSupportedVersion() || ! $contract->isComplete()
            || $contract->missingRequiredFields() !== []) {
            return null;
        }

        // Verify attribution matches the expectation
        if (! hash_equals((string) $agent->public_id, (string) $contract->get('agent_id'))
            || ! $this->attributionMatches($contract, $target, $expectation)) {
            return null;
        }

        return new SentinelRuntimeSnapshot(
            agentId: $contract->get('agent_id'),
            agentVersion: $contract->get('agent_version'),
            observedReleaseId: $contract->get('release_id'),
            observedCommitSha: $contract->get('release_manifest_commit_sha'),
            currentReleasePath: $contract->get('current_release_path'),
            runtimeMarker: $contract->get('runtime_marker'),
            observedAt: $contract->get('timestamp'),
            fileManifestHash: $contract->get('file_manifest_hash'),
            fileManifestEntryCount: $contract->get('file_manifest_entry_count'),
            fileManifestTotalSize: $contract->get('file_manifest_total_size'),
            fileObservationStatus: $contract->get('file_observation_status'),
        );
    }

    /**
     * Resolve the agent (Server Agent or Local Agent) that serves the target
     * project/environment/release.
     *
     * Priority:
     * 1. Explicit agentId in expectation (check both Agent and LocalAgent)
     * 2. Server Agent via Deployment chain (production runtime)
     * 3. Local Agent via LocalProjectMapping (source workspace)
     *
     * @return Agent|LocalAgent|null
     */
    private function resolveAgent(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): Agent|LocalAgent|null
    {
        // 1. Explicit agentId in expectation — check both tables. Server Agent
        // keeps priority; the Local Agent fallback is gated behind the explicit
        // local proof flag and never active in the production Hub.
        if ($expectation->agentId !== null) {
            $server = Agent::query()
                ->where('public_id', $expectation->agentId)
                ->where('status', Agent::STATUS_APPROVED)
                ->first();
            if ($server !== null) {
                return $server;
            }
            if ($this->allowLocalAgent) {
                return LocalAgent::query()
                    ->where('public_id', $expectation->agentId)
                    ->where('status', LocalAgent::STATUS_APPROVED)
                    ->first();
            }
            return null;
        }

        // 2. Server Agent via Deployment chain (production runtime)
        $deployment = Deployment::query()
            ->where('public_id', $expectation->deploymentPublicId)
            ->whereIn('release_id', Release::query()->select('id')->where('public_id', $expectation->releaseId))
            ->with('server')
            ->first();

        if ($deployment?->server !== null) {
            return $deployment->server->agents()
                ->where('status', Agent::STATUS_APPROVED)
                ->where('last_seen_at', '>', now()->subSeconds(config('deployment.server_offline_threshold_seconds')))
                ->first();
        }

        return null;
    }

    /**
     * Read the latest valid runtime snapshot from the agent's persisted
     * heartbeat storage. Since Phase 3B, the heartbeat controller writes
     * the validated snapshot into the agent's `runtime_snapshot` column
     * on every heartbeat that carries one. Older heartbeats (no
     * runtime_snapshot field) leave the column untouched and are treated
     * as legacy -- they are not evidence of "no snapshot ever existed".
     *
     * The stored snapshot is authoritative: it was collected by the
     * running agent, signed over the wire, validated by the Hub's
     * SentinelRuntimeEvidenceContract, and persisted as canonical JSON.
     *
     * For LocalAgent: reconstruction from Hub records is NEVER allowed
     * (the Hub has no access to the developer's filesystem). This method
     * returns null if no stored snapshot exists.
     *
     * For Agent (Server Agent): reconstruction is only used as an explicit,
     * opt-in fallback when the column is empty AND the caller passes
     * allowReconstruction=true.
     *
     * @param  Agent|LocalAgent  $agent
     */
    private function readLatestValidSnapshot(Agent|LocalAgent $agent, SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation, bool $allowReconstruction = false): ?array
    {
        $stored = $agent->runtime_snapshot;

        if (is_array($stored) && $stored !== []) {
            // Verify the stored snapshot is still fresh enough to be
            // authoritative evidence. A snapshot older than the
            // configured freshness window is treated as stale -> UNKNOWN.
            $timestamp = $stored['timestamp'] ?? null;
            if (is_string($timestamp) && $this->isFresh($timestamp)) {
                // Ingest enforces monotonicity. Here the JSON must match
                // the watermark atomically stored with this snapshot.
                $sequence = $stored['sequence'] ?? null;
                if ($this->isSequenceAcceptable($agent, $sequence)) {
                    return $stored;
                }
            }
        }

        // For LocalAgent: NEVER reconstruct (Hub has no access to developer's filesystem)
        // For Agent: only reconstruct if explicitly allowed (opt-in)
        if ($agent instanceof LocalAgent || ! $allowReconstruction) {
            return null;
        }

        // Explicit fallback only: reconstruct from authoritative Hub
        // records. This path is opt-in and must never be the default.
        // Only for Server Agent (Agent model).
        return $this->reconstructFromHubRecords($agent, $target, $expectation);
    }

    /**
     * Reconstruct a runtime snapshot from authoritative Hub records.
     *
     * This is an explicit, opt-in fallback used only when no stored
     * snapshot exists AND the caller passes allowReconstruction=true.
     * It reads the deployment's project configuration and filesystem
     * state to build a snapshot that mirrors what the agent-side
     * RuntimeSnapshotCollector would have produced. It is NOT a
     * substitute for the real stored snapshot and must never be used
     * as the default evidence path.
     *
     * ONLY FOR SERVER AGENT (Agent model) — never called for LocalAgent.
     */
    private function reconstructFromHubRecords(Agent $agent, SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?array
    {
        // Find the Deployment that produced this release
        $deployment = Deployment::query()
            ->where('public_id', $expectation->deploymentPublicId)
            ->with(['project', 'artifact', 'server'])
            ->first();

        if ($deployment === null) {
            return null;
        }

        // Verify this deployment belongs to the agent's server
        if ($deployment->server_id !== $agent->server_id) {
            return null;
        }

        // Build the snapshot from Hub authoritative records
        // The Agent will mirror this in its heartbeat
        $releaseRoot = $this->resolveReleaseRoot($deployment);
        if ($releaseRoot === null) {
            return null;
        }

        $currentReleasePath = $this->resolveCurrentReleasePath($releaseRoot, $deployment->public_id);
        if ($currentReleasePath === null) {
            return null;
        }

        $runtimeMarker = $this->readRuntimeMarker($currentReleasePath);
        $releaseManifestCommitSha = $this->readReleaseManifestCommitSha($currentReleasePath);
        $artifactChecksum = $deployment->artifact?->checksum_sha256;

        // Check if we have the minimum required data
        $hasManifest = $releaseManifestCommitSha !== null;
        $hasMarker = $runtimeMarker !== null;

        $snapshotStatus = ($hasManifest && $hasMarker)
            ? SentinelRuntimeEvidenceContract::SNAPSHOT_STATUS_COMPLETE
            : SentinelRuntimeEvidenceContract::SNAPSHOT_STATUS_INCOMPLETE;

        return [
            'evidence_version' => SentinelRuntimeEvidenceContract::VERSION,
            'agent_id' => $agent->public_id,
            'agent_version' => $agent->agent_version,
            'project_key' => $deployment->project->project_key,
            'environment' => $deployment->project->environment ?? $target->environment,
            'release_id' => $expectation->releaseId,
            'deployment_id' => $deployment->public_id,
            'current_release_path' => $currentReleasePath,
            'runtime_marker' => $runtimeMarker,
            'release_manifest_commit_sha' => $releaseManifestCommitSha,
            'artifact_checksum_sha256' => $artifactChecksum,
            'timestamp' => now()->toIso8601String(),
            'sequence' => $agent->id, // Use agent DB id as monotonic sequence
            'nonce' => bin2hex(random_bytes(16)),
            'snapshot_status' => $snapshotStatus,
        ];
    }

    private function isFresh(string $timestamp): bool
    {
        try {
            $parsed = \Carbon\Carbon::parse($timestamp);
        } catch (\Exception) {
            return false;
        }

        return ! $parsed->isFuture() && $parsed->greaterThan(now()->subSeconds(
            (int) config('deployment.runtime_snapshot_freshness_seconds', 300)
        ));
    }

    /**
     * Check consistency with the last accepted snapshot's stored watermark.
     *
     * @param  Agent|LocalAgent  $agent
     */
    private function isSequenceAcceptable(Agent|LocalAgent $agent, $sequence): bool
    {
        if (! is_int($sequence) || $sequence < 1) {
            return false;
        }

        // Inspect the raw column as an integer cast could hide malformed text.
        $lastSequence = $agent->getRawOriginal('runtime_snapshot_sequence');
        if (is_string($lastSequence)) {
            $lastSequence = preg_match('/^[1-9][0-9]*$/D', $lastSequence)
                ? filter_var($lastSequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
        }

        return is_int($lastSequence) && $lastSequence > 0 && $sequence === $lastSequence;
    }

    /**
     * Resolve the release root directory from the project configuration.
     * This is the only place where server filesystem paths are read.
     */
    private function resolveReleaseRoot(Deployment $deployment): ?string
    {
        $project = $deployment->project;

        // For recipe-driven projects, the release root is the working_directory
        // For legacy projects, it's the artifact_persistent_root if configured
        $releaseRoot = $project->artifact_persistent_root ?? $project->working_directory ?? null;

        if ($releaseRoot === null || ! is_dir($releaseRoot)) {
            return null;
        }

        return rtrim($releaseRoot, '/');
    }

    /**
     * Read and canonicalize the current symlink target.
     * Validates that it resolves within the releases/ directory.
     */
    private function resolveCurrentReleasePath(string $releaseRoot, string $deploymentId): ?string
    {
        $currentSymlink = $releaseRoot . '/current';

        if (! is_link($currentSymlink)) {
            return null;
        }

        $target = readlink($currentSymlink);
        if ($target === false || $target === '') {
            return null;
        }

        // Canonicalize: resolve to absolute path
        $canonical = realpath($target);
        if ($canonical === false) {
            return null;
        }

        // Validate path containment: must be under releases/ directory
        $releasesDir = realpath($releaseRoot . '/releases');
        if ($releasesDir === false || ! str_starts_with($canonical, $releasesDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        // The directory name must match the deployment public_id
        $dirName = basename($canonical);
        if (! hash_equals($deploymentId, $dirName)) {
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

    /**
     * Verify the snapshot attribution matches the collection target.
     */
    private function attributionMatches(SentinelRuntimeEvidenceContract $contract, SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): bool
    {
        if (! hash_equals($expectation->commitSha, (string) $contract->get('release_manifest_commit_sha'))
            || ! hash_equals($expectation->expectedRuntimeMarker, (string) $contract->get('runtime_marker'))
            || ($expectation->artifactChecksumSha256 !== null
                && ! hash_equals($expectation->artifactChecksumSha256, (string) $contract->get('artifact_checksum_sha256')))) {
            return false;
        }
        if (! hash_equals((string) $contract->get('project_key'), $target->projectKey)) {
            return false;
        }
        if (! hash_equals((string) $contract->get('environment'), $target->environment)) {
            return false;
        }
        if (! hash_equals((string) $contract->get('release_id'), $target->releaseId)) {
            return false;
        }
        if ($expectation->deploymentPublicId !== null) {
            if (! hash_equals((string) $contract->get('deployment_id'), $expectation->deploymentPublicId)) {
                return false;
            }
        }

        return true;
    }
}
