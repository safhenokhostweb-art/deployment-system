<?php

namespace App\Services\HealthCheck;

use App\Models\AuditLogEntry;
use App\Models\DeploymentHealthCheck;
use App\Models\DeploymentTask;

/**
 * Task 5: runs a configured health check against a DeploymentTask and
 * records the result. Manually triggered from the dashboard in this
 * phase (not wired automatically into the deploy flow) — see
 * docs/PHASE_3D_PRODUCTION_READINESS_REPORT.md §6 for why.
 */
class HealthCheckService
{
    /**
     * @var array<string, HealthChecker>
     */
    private array $checkers;

    public function __construct()
    {
        $this->checkers = [
            'http_status' => new HttpStatusHealthChecker(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function run(DeploymentTask $task, string $type, array $config): DeploymentHealthCheck
    {
        $checker = $this->checkers[$type] ?? null;
        abort_unless($checker !== null, 422, "Unknown health check type '{$type}'.");

        $healthCheck = DeploymentHealthCheck::create([
            'deployment_task_id' => $task->id,
            'type' => $type,
            'config' => $config,
            'status' => 'pending',
        ]);

        $result = $checker->check($task, $config);

        $healthCheck->update([
            'status' => $result->success ? 'success' : 'failed',
            'result_message' => $result->message,
            'checked_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'deployment_health_check.completed',
            subjectType: DeploymentTask::class,
            subjectId: $task->id,
            metadata: ['type' => $type, 'success' => $result->success, 'message' => $result->message],
        );

        return $healthCheck->fresh();
    }
}
