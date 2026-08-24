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

        if ($newStatus === Deployment::STATUS_SUCCEEDED) {
            // Root-cause fix (deployment details observability): mirror the
            // legacy AgentJobService::updateStatus() Phase 5 semantics —
            // every task succeeded means activation and every recipe
            // health-check step already passed (the agent fails the instant
            // any step fails), so an all-tasks-succeeded aggregate genuinely
            // IS "verified deployed". Without this, a successful multi-target
            // deployment showed "verified deployed: no" on the details page
            // even though it passed every health check.
            $attributes['deployed_at'] = now();
            $attributes['execution_status'] = Deployment::EXECUTION_SUCCEEDED;
            $attributes['execution_finished_at'] = now();
            if ($deployment->hasHttpHealthCheck()) {
                $attributes['http_health_status'] = Deployment::HTTP_HEALTH_VERIFIED;
                $attributes['http_health_verified_at'] = now();
                $attributes['http_health_details'] = ['targets_verified' => $statuses->count()];
            }
        }

        if (in_array($newStatus, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_PARTIAL_SUCCESS], true)) {
            $attributes['finished_at'] = now();
            if ($newStatus !== Deployment::STATUS_SUCCEEDED) {
                $failureCodes = $deployment->tasks()->where('status', DeploymentTask::STATUS_FAILED)->pluck('failure_code');
                $healthOnlyFailure = $failureCodes->isNotEmpty()
                    && $failureCodes->every(fn (?string $code): bool => $code === Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED);
                $attributes['execution_status'] = $healthOnlyFailure
                    ? Deployment::EXECUTION_SUCCEEDED
                    : Deployment::EXECUTION_FAILED;
                $attributes['execution_finished_at'] = now();
                if ($deployment->hasHttpHealthCheck() && $healthOnlyFailure) {
                    $attributes['http_health_status'] = Deployment::HTTP_HEALTH_FAILED;
                    $attributes['http_health_details'] = [
                        'failed_targets' => $failureCodes->count(),
                        'message' => 'One or more targets failed customer-facing HTTP health verification.',
                    ];
                }
            }
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
