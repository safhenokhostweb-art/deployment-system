<?php

namespace App\Services\Publish;

use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Services\Security\SecretRedactor;

/**
 * Phase 5.3: queue/claim/report lifecycle for a remote-control request
 * against a Local Agent (restart, fetch recent logs) — the same shape as
 * OperationJobService, the established pattern for "a Hub-initiated
 * action an agent picks up on its next poll."
 */
class LocalAgentControlJobService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {
    }

    public function queue(LocalAgent $localAgent, string $action, ?string $requestedBy): LocalAgentControlJob
    {
        abort_unless(
            in_array($action, [
                LocalAgentControlJob::ACTION_RESTART,
                LocalAgentControlJob::ACTION_FETCH_LOGS,
                LocalAgentControlJob::ACTION_RELOAD_CONFIG,
            ], true),
            422,
            "Unknown local agent control action '{$action}'."
        );

        $job = LocalAgentControlJob::create([
            'local_agent_id' => $localAgent->id,
            'action' => $action,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
            'requested_by' => $requestedBy,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: self::requestedAuditAction($action),
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
            metadata: ['local_agent_id' => $localAgent->id],
        );

        return $job;
    }

    public function claimNext(LocalAgent $localAgent): ?LocalAgentControlJob
    {
        $job = LocalAgentControlJob::query()
            ->where('local_agent_id', $localAgent->id)
            ->where('status', LocalAgentControlJob::STATUS_QUEUED)
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => LocalAgentControlJob::STATUS_RUNNING]);

        return $job;
    }

    public function reportResult(LocalAgentControlJob $job, string $output): LocalAgentControlJob
    {
        $job->update([
            'status' => LocalAgentControlJob::STATUS_SUCCEEDED,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: self::completedAuditAction($job->action),
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
        );

        return $job;
    }

    public function reportFailure(LocalAgentControlJob $job, string $message): LocalAgentControlJob
    {
        $job->update([
            'status' => LocalAgentControlJob::STATUS_FAILED,
            'error_message' => $this->redactor->redact($message),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'local_agent.control_job_failed',
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
            metadata: ['action' => $job->action, 'message' => $job->error_message],
        );

        return $job;
    }

    private static function requestedAuditAction(string $action): string
    {
        return match ($action) {
            LocalAgentControlJob::ACTION_RESTART => 'local_agent.restart_requested',
            LocalAgentControlJob::ACTION_RELOAD_CONFIG => 'local_agent.reload_config_requested',
            default => 'local_agent.logs_requested',
        };
    }

    private static function completedAuditAction(string $action): string
    {
        return match ($action) {
            LocalAgentControlJob::ACTION_RESTART => 'local_agent.restarted',
            LocalAgentControlJob::ACTION_RELOAD_CONFIG => 'local_agent.reload_config_completed',
            default => 'local_agent.logs_fetched',
        };
    }
}
