<?php

namespace App\Services\Deployment;

use App\Events\DeploymentStarted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Server;
use App\Services\Deployment\Artifact\ArtifactService;
use Illuminate\Support\Facades\DB;

/**
 * Deployment-level retry (root-cause fix: agent protocol compatibility).
 *
 * A deployment can reach a terminal FAILED state for a reason that is
 * fully recoverable once the environment changes — most importantly
 * `agent_protocol_incompatible`: the Server Agent was too old to enforce
 * the Laravel release-completeness gate when the deployment was claimed.
 * Nothing re-queued it after the agent was upgraded, so operators were
 * forced into manual DB edits.
 *
 * Retry is NOT a status flip. It:
 *   1. re-validates the deployment is actually retryable (failed or
 *      partial_success, never succeeded/cancelled/rolled_back);
 *   2. re-checks the Server Agent version/capability BEFORE requeueing —
 *      if the agent is still too old it fails with a clear, actionable
 *      error instead of re-queueing straight back into another
 *      claim-time rejection;
 *   3. re-attaches an exact-commit artifact if one became available
 *      meanwhile (same last-pre-queue boundary as normal dispatch);
 *   4. re-queues the correct work: failed DeploymentTasks (multi-target)
 *      or the legacy Deployment row itself (no tasks) — never a manual
 *      `status` change in isolation;
 *   5. preserves full history (log entries, AgentJob attempts, prior
 *      failure_code/failure_message remain discoverable via logs);
 *   6. audits `deployment.retried` and fires DeploymentStarted.
 */
class RetryDeploymentService
{
    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {
    }

    public function retry(Deployment $deployment, string $actor): Deployment
    {
        abort_unless($deployment->isRetryable(), 409, 'Only a failed or partially-successful deployment can be retried.');

        $this->assertAgentsCompatible($deployment);

        return DB::transaction(function () use ($deployment, $actor) {
            // Same last-pre-queue artifact resolution as DeploymentDispatcher::approve().
            $this->artifacts->attachToDeploymentIfAvailable($deployment);

            $now = now();

            if ($deployment->tasks()->exists()) {
                // Multi-target: re-queue only the tasks that actually failed;
                // succeeded tasks keep their result and are never re-run.
                $deployment->tasks()
                    ->where('status', DeploymentTask::STATUS_FAILED)
                    ->update([
                        'status' => DeploymentTask::STATUS_QUEUED,
                        'next_retry_at' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);
            }

            $deployment->update([
                'status' => Deployment::STATUS_QUEUED,
                'queued_at' => $now,
                'failure_code' => null,
                'failure_message' => null,
                'finished_at' => null,
                'deployed_at' => null,
                'execution_status' => Deployment::EXECUTION_PENDING,
                'execution_finished_at' => null,
                'http_health_status' => $deployment->hasHttpHealthCheck()
                    ? Deployment::HTTP_HEALTH_PENDING
                    : Deployment::HTTP_HEALTH_NOT_CONFIGURED,
                'http_health_verified_at' => null,
                'http_health_details' => null,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actor,
                action: 'deployment.retried',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['project_id' => $deployment->project_id, 'commit_hash' => $deployment->commit_hash],
            );

            event(new DeploymentStarted($deployment));

            return $deployment->refresh();
        });
    }

    /**
     * Re-queues a single failed DeploymentTask (multi-target case) without
     * touching its succeeded siblings. The parent deployment is moved back
     * to queued when it was terminal so the aggregate can be recomputed by
     * the normal status flow as the requeued task executes.
     */
    public function retryTask(DeploymentTask $task, string $actor): Deployment
    {
        abort_unless($task->status === DeploymentTask::STATUS_FAILED, 409, 'Only a failed task can be retried.');

        $deployment = $task->deployment;
        $this->assertAgentsCompatible($deployment);

        return DB::transaction(function () use ($task, $deployment, $actor) {
            $this->artifacts->attachToDeploymentIfAvailable($deployment);

            $task->update([
                'status' => DeploymentTask::STATUS_QUEUED,
                'next_retry_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            if ($deployment->isRetryable() || $deployment->isInProgress()) {
                $deployment->update([
                    'status' => Deployment::STATUS_QUEUED,
                    'queued_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                    'finished_at' => null,
                    'deployed_at' => null,
                    'execution_status' => Deployment::EXECUTION_PENDING,
                    'execution_finished_at' => null,
                    'http_health_status' => $deployment->hasHttpHealthCheck()
                        ? Deployment::HTTP_HEALTH_PENDING
                        : Deployment::HTTP_HEALTH_NOT_CONFIGURED,
                    'http_health_verified_at' => null,
                    'http_health_details' => null,
                ]);
            }

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actor,
                action: 'deployment_task.retried',
                subjectType: DeploymentTask::class,
                subjectId: $task->id,
                metadata: ['deployment_id' => $deployment->id, 'commit_hash' => $deployment->commit_hash],
            );

            event(new DeploymentStarted($deployment));

            return $deployment->refresh();
        });
    }

    private function assertAgentsCompatible(Deployment $deployment): void
    {
        $requiresGate = $deployment->artifact_id !== null
            || ($deployment->project->requires_complete_artifact && $deployment->action === Deployment::ACTION_DEPLOY);

        if (!$requiresGate && !$deployment->hasHttpHealthCheck()) {
            return;
        }

        $servers = $deployment->tasks()->exists()
            ? $deployment->tasks()->with('deploymentTarget.server')->get()
                ->pluck('deploymentTarget.server')
                ->filter()
                ->unique('id')
            : collect([$deployment->server])->filter();

        $required = (string) config('deployment.min_server_agent_version_for_artifact');

        foreach ($servers as $server) {
            $version = $server->agents()->first()?->agent_version;

            if ($requiresGate && (!is_string($version) || $version === '' || version_compare($version, $required, '<'))) {
                abort(409, "The server agent (version " . ($version ?? 'unknown') . ") is too old to enforce the Laravel release-completeness gate. Upgrade the agent to at least {$required} before retrying this deployment.");
            }

            $healthRequired = (string) config('deployment.min_server_agent_version_for_http_health');
            if ($deployment->hasHttpHealthCheck()
                && (!is_string($version) || $version === '' || version_compare($version, $healthRequired, '<'))) {
                abort(409, "The server agent (version " . ($version ?? 'unknown') . ") cannot execute customer-facing HTTP health verification. Upgrade the agent to at least {$healthRequired} before retrying this deployment.");
            }
        }
    }
}
