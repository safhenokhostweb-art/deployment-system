<?php

namespace App\Services\Sentinel;

use App\Models\Deployment;
use App\Models\SentinelVerificationRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs a read-only evidence collection and stores its auditable result. */
final class SentinelDeploymentVerificationService
{
    public function __construct(
        private readonly SentinelEvidenceCollection $collector,
        private readonly SentinelVerificationRunStore $runs,
    ) {
    }

    public function collect(Deployment $deployment, ?int $userId): SentinelVerificationRun
    {
        try {
            $deployment->loadMissing(['project', 'release']);
            $project = $deployment->project;
            $release = $deployment->release;
        } catch (Throwable) {
            throw new SentinelVerificationContextException('Deployment verification context is unavailable.');
        }
        if ($project === null || $release === null || $release->project_id !== $project->id) {
            throw new SentinelVerificationContextException('Deployment verification context is unavailable.');
        }

        $target = new SentinelCollectionTarget(
            projectKey: (string) $project->project_key,
            environment: (string) $project->environment,
            releaseId: (string) $release->public_id,
            collectedAt: now()->toIso8601String(),
            maxAgeSeconds: (int) config('deployment.runtime_snapshot_freshness_seconds', 300),
            deploymentPublicId: (string) $deployment->public_id,
        );
        try {
            $outcome = $this->collector->collect($target);
        } catch (Throwable) {
            // Do not expose adapter/exception details; retain an explicit
            // inconclusive outcome if the primary database is still usable.
            $outcome = SentinelCollectionOutcome::unavailable($target->verifierExpectation());
        }

        try {
            return $this->runs->record([
                'deployment_id' => $deployment->id,
                'user_id' => $userId,
                'verdict' => $outcome->verdict(),
                'evidence_hash' => $outcome->result()->evidenceHash() ?: null,
                'evidence' => $outcome->evidence()?->toArray(),
                'checks' => $outcome->result()->checks(),
                'reasons' => $outcome->result()->reasons(),
                'collected_at' => $target->collectedAt,
            ]);
        } catch (Throwable) {
            // A failed primary-DB write cannot be represented as a durable
            // audit record in that same database.
            try {
                Log::warning('Sentinel verification result could not be persisted.', [
                    'deployment_id' => $deployment->id,
                ]);
            } catch (Throwable) {
                // Persistence must still surface as failed if logging is unavailable.
            }

            throw new SentinelVerificationPersistenceException('Verification completed or became inconclusive, but its result was not recorded.');
        }
    }
}
