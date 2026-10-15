<?php

namespace App\Services\Sentinel;

use App\Models\Deployment;
use App\Models\Release;

/** Read-only expected-value adapter backed by the exact Hub deployment row. */
final class HubReleaseExpectationProvider implements ReleaseExpectationProvider
{
    public function expectation(SentinelCollectionTarget $target): ?SentinelReleaseExpectation
    {
        $release = Release::query()
            ->where('public_id', $target->releaseId)
            ->whereHas('project', fn ($query) => $query
                ->where('project_key', $target->projectKey)
                ->where('environment', $target->environment))
            ->with('project')
            ->first();

        if ($release === null) {
            return null;
        }

        $deployment = Deployment::query()
            ->where('public_id', $target->deploymentPublicId)
            ->where('release_id', $release->id)
            ->where('project_id', $release->project_id)
            ->with(['artifact', 'server.agents'])
            ->first();

        if ($deployment === null || ! hash_equals((string) $release->commit_hash, (string) $deployment->commit_hash)) {
            return null;
        }

        $artifact = $deployment->artifact;
        if ($artifact !== null && ($artifact->project_id !== $release->project_id
            || $artifact->release_id !== $release->id
            || ! hash_equals((string) $artifact->commit_hash, (string) $release->commit_hash)
            || ! $artifact->isAvailable())) {
            $artifact = null;
        }

        $health = $deployment->http_health_config ?: $release->project->deploymentHttpHealthConfig();
        $endpoint = is_array($health) && is_string($health['url'] ?? null) ? $health['url'] : null;
        $expectedStatus = is_array($health) && is_int($health['expected_status'] ?? null) ? $health['expected_status'] : 200;
        $agentId = $deployment->server?->agents()
            ->where('status', 'approved')
            ->where('last_seen_at', '>', now()->subSeconds(config('deployment.server_offline_threshold_seconds')))
            ->value('public_id');

        // File manifest from artifact (feature-flagged)
        $expectedFileManifestHash = null;
        $expectedFileManifestEntryCount = null;
        $expectedFileManifestTotalSize = null;
        $fileComparisonScope = null;

        if (config('sentinel.file_verification_enabled', false) === true) {
            $expectedFileManifestHash = $artifact?->file_manifest_hash;
            $expectedFileManifestEntryCount = $artifact?->file_manifest_entry_count;
            $expectedFileManifestTotalSize = $artifact?->file_manifest_total_size;
            $fileComparisonScope = config('sentinel.file_comparison_scope', 'full');
        }

        return new SentinelReleaseExpectation(
            projectKey: $target->projectKey,
            environment: $target->environment,
            releaseId: $release->public_id,
            commitSha: (string) $release->commit_hash,
            artifactId: $artifact?->public_id,
            artifactChecksumSha256: $artifact?->checksum_sha256,
            expectedRuntimeMarker: 'release:'.$deployment->public_id.'|commit:'.$release->commit_hash,
            deploymentPublicId: $deployment->public_id,
            healthEndpoint: $endpoint,
            expectedHealthStatus: $expectedStatus,
            agentId: $agentId,
            expectedFileManifestHash: $expectedFileManifestHash,
            expectedFileManifestEntryCount: $expectedFileManifestEntryCount,
            expectedFileManifestTotalSize: $expectedFileManifestTotalSize,
            fileComparisonScope: $fileComparisonScope,
        );
    }
}
