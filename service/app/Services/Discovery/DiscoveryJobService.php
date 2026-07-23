<?php

namespace App\Services\Discovery;

use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\DiscoveryJob;
use App\Services\Security\SecretRedactor;

/**
 * Phase 4-H: queue/claim/report lifecycle for a Local Discovery scan.
 * Deliberately its own service (not folded into AgentTaskService) — a
 * DiscoveryJob has no Deployment/DeploymentTask/Environment locking
 * concerns at all, just a single queued -> running -> succeeded|failed
 * transition per scan.
 *
 * reportResult() re-applies SecretRedactor to every string leaf of the
 * agent-reported payload as defense in depth: the Agent-side scanner and
 * RemoteUrlSanitizer are already supposed to never produce a secret, but
 * this is the same "redact at write time, not just display time" posture
 * already used for pipeline log text (see SecretRedactor's own docblock).
 */
class DiscoveryJobService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {
    }

    public function queue(Agent $agent, string $workspacePath, ?string $requestedBy): DiscoveryJob
    {
        $job = DiscoveryJob::create([
            'agent_id' => $agent->id,
            'workspace_path' => $workspacePath,
            'status' => DiscoveryJob::STATUS_QUEUED,
            'requested_by' => $requestedBy,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: 'discovery_job.queued',
            subjectType: DiscoveryJob::class,
            subjectId: $job->id,
            metadata: ['agent_id' => $agent->id, 'workspace_path' => $workspacePath],
        );

        return $job;
    }

    public function claimNext(Agent $agent): ?DiscoveryJob
    {
        $job = DiscoveryJob::query()
            ->where('agent_id', $agent->id)
            ->where('status', DiscoveryJob::STATUS_QUEUED)
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => DiscoveryJob::STATUS_RUNNING]);

        return $job;
    }

    /**
     * @param array<int, array<string, mixed>> $detectedProjects
     */
    public function reportResult(DiscoveryJob $job, array $detectedProjects): DiscoveryJob
    {
        $job->update([
            'status' => DiscoveryJob::STATUS_SUCCEEDED,
            'result' => $this->redactRecursively($detectedProjects),
            'error_message' => null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'discovery_job.succeeded',
            subjectType: DiscoveryJob::class,
            subjectId: $job->id,
            metadata: ['detected_count' => count($detectedProjects)],
        );

        return $job->refresh();
    }

    public function reportFailure(DiscoveryJob $job, string $message): DiscoveryJob
    {
        $job->update([
            'status' => DiscoveryJob::STATUS_FAILED,
            'error_message' => $this->redactor->redact($message),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'discovery_job.failed',
            subjectType: DiscoveryJob::class,
            subjectId: $job->id,
            metadata: ['message' => $job->error_message],
        );

        return $job->refresh();
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redactRecursively(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactRecursively($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactor->redact($value);
            }
        }

        return $data;
    }
}
