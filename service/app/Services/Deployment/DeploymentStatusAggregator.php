<?php

namespace App\Services\Deployment;

use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Events\RollbackCompleted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Environment;

/**
 * Recomputes a multi-target Deployment's aggregate status from its
 * DeploymentTasks (docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md §9).
 * Never called for a legacy Deployment — one with zero tasks — so its
 * status continues to be set directly by AgentJobService exactly as
 * before.
 */
class DeploymentStatusAggregator
{
    public function recalculate(Deployment $deployment): Deployment
    {
        $statuses = $deployment->tasks()->pluck('status');

        if ($statuses->isEmpty()) {
            return $deployment;
        }

        $newStatus = $this->aggregate($statuses);

        if ($newStatus === $deployment->status) {
            return $deployment;
        }

        $attributes = ['status' => $newStatus];

        if (in_array($newStatus, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_PARTIAL_SUCCESS], true)) {
            $attributes['finished_at'] = now();
            $this->releaseEnvironmentLock($deployment);
        }

        $deployment->update($attributes);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'deployment.aggregate_status_recalculated',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
            metadata: ['status' => $newStatus],
        );

        // Task 7: notification foundation — no listener registered yet.
        // partial_success fires DeploymentFailed too — a mixed-outcome
        // rollout needs attention just as much as a fully failed one.
        if ($newStatus === Deployment::STATUS_SUCCEEDED) {
            event($deployment->action === Deployment::ACTION_ROLLBACK
                ? new RollbackCompleted($deployment)
                : new DeploymentSucceeded($deployment));
        } elseif (in_array($newStatus, [Deployment::STATUS_FAILED, Deployment::STATUS_PARTIAL_SUCCESS], true)) {
            event(new DeploymentFailed($deployment));
        }

        return $deployment->refresh();
    }

    /**
     * @param \Illuminate\Support\Collection<int, string> $statuses
     */
    private function aggregate($statuses): string
    {
        if ($statuses->every(fn (string $s) => $s === DeploymentTask::STATUS_SUCCEEDED)) {
            return Deployment::STATUS_SUCCEEDED;
        }

        if ($statuses->every(fn (string $s) => $s === DeploymentTask::STATUS_FAILED)) {
            return Deployment::STATUS_FAILED;
        }

        if ($statuses->contains(DeploymentTask::STATUS_QUEUED) || $statuses->contains(DeploymentTask::STATUS_RUNNING)) {
            return Deployment::STATUS_RUNNING;
        }

        // Every task has reached a terminal state, but not all the same
        // one — e.g. two succeeded, one failed.
        return Deployment::STATUS_PARTIAL_SUCCESS;
    }

    private function releaseEnvironmentLock(Deployment $deployment): void
    {
        Environment::where('locked_by_deployment_id', $deployment->id)
            ->update(['locked_by_deployment_id' => null, 'locked_at' => null]);
    }
}
