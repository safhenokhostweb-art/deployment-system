<?php

namespace App\Services\Operations;

use App\Events\OperationFailed;
use App\Events\OperationStarted;
use App\Events\OperationSucceeded;
use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\OperationJob;
use App\Models\Project;
use App\Models\Server;
use App\Services\Security\SecretRedactor;
use App\Support\Operations\OperationRegistry;

/**
 * Phase 4-K: queue/claim/report lifecycle for an Operations Command
 * Center request — the same shape as DiscoveryJobService before it.
 * `operation_type` is validated against OperationRegistry at every entry
 * point (queue() here, and independently again by the Agent itself) —
 * never a raw command string, by construction (Task 4/12).
 */
class OperationJobService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {
    }

    public function queue(Server $server, Agent $agent, string $operationType, ?Project $project, ?string $requestedBy): OperationJob
    {
        abort_unless(OperationRegistry::isAllowed($operationType), 422, "Unknown operation type '{$operationType}'.");

        if (OperationRegistry::requiresProject($operationType)) {
            abort_if($project === null, 422, "Operation '{$operationType}' requires a project.");
        }

        $job = OperationJob::create([
            'server_id' => $server->id,
            'agent_id' => $agent->id,
            'project_id' => $project?->id,
            'operation_type' => $operationType,
            'status' => OperationJob::STATUS_QUEUED,
            'requested_by' => $requestedBy,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: 'operation_job.queued',
            subjectType: OperationJob::class,
            subjectId: $job->id,
            metadata: ['server_id' => $server->id, 'operation_type' => $operationType],
        );

        return $job;
    }

    public function claimNext(Agent $agent): ?OperationJob
    {
        $job = OperationJob::query()
            ->where('agent_id', $agent->id)
            ->where('status', OperationJob::STATUS_QUEUED)
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => OperationJob::STATUS_RUNNING, 'started_at' => now()]);

        event(new OperationStarted($job));

        return $job;
    }

    public function reportResult(OperationJob $job, string $output): OperationJob
    {
        $job->update([
            'status' => OperationJob::STATUS_SUCCEEDED,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'operation_job.succeeded',
            subjectType: OperationJob::class,
            subjectId: $job->id,
            metadata: ['operation_type' => $job->operation_type],
        );

        event(new OperationSucceeded($job->refresh()));

        return $job;
    }

    public function reportFailure(OperationJob $job, string $message, ?string $output = null): OperationJob
    {
        $job->update([
            'status' => OperationJob::STATUS_FAILED,
            'error_message' => $this->redactor->redact($message),
            'output' => $output !== null ? $this->redactor->redact($output) : $job->output,
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'operation_job.failed',
            subjectType: OperationJob::class,
            subjectId: $job->id,
            metadata: ['operation_type' => $job->operation_type, 'message' => $job->error_message],
        );

        event(new OperationFailed($job->refresh()));

        return $job;
    }
}
