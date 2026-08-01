<?php

namespace App\Console\Commands;

use App\Events\DeploymentFailed;
use App\Models\AgentJob;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Services\Deployment\DeploymentStatusAggregator;
use Illuminate\Console\Command;

/**
 * A deployment (or, since Phase 3-C.2, a new-domain AgentJob) an agent
 * claimed but then never reported back on again (crashed process, lost
 * connectivity, killed server) would otherwise sit "running" forever,
 * permanently blocking that server's queue — and, for the new domain,
 * permanently holding its Environment's lock. This finds those and fails
 * them so a human (or a re-push) can recover.
 *
 * Same policy, same timeout config, applied to both domains — extending
 * this command rather than standing up a near-identical parallel one for
 * the new domain (docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md §8).
 */
class DetectStalledDeployments extends Command
{
    protected $signature = 'deployments:detect-stalled';

    protected $description = 'Mark running deployments and agent jobs with no recent activity as failed';

    public function handle(DeploymentStatusAggregator $aggregator): int
    {
        $timeoutMinutes = (int) config('deployment.stalled_deployment_timeout_minutes');
        $cutoff = now()->subMinutes($timeoutMinutes);

        $stalledDeployments = $this->failStalledLegacyDeployments($timeoutMinutes, $cutoff);
        $stalledJobs = $this->failStalledAgentJobs($timeoutMinutes, $cutoff, $aggregator);

        $this->info("Marked {$stalledDeployments} stalled legacy deployment(s) and {$stalledJobs} stalled agent job(s) as failed.");

        return self::SUCCESS;
    }

    /**
     * Root-cause fix (sandogh deployment overhaul, Phase 2): a recipe-driven
     * deployment can legitimately run for many minutes across several
     * intermediate phases (building/migrating/activating/verifying — see
     * Deployment::IN_PROGRESS_STATUSES), so this now also catches a stall
     * in any of those, not just the original `running`.
     *
     * A deployment claimed by the *new*, lease-aware AgentJobService
     * (lease_expires_at IS NOT NULL) is judged purely by whether that
     * lease has expired — the precise signal the lease/heartbeat
     * mechanism exists to provide. A deployment with no lease at all
     * (every row that existed before this column, or a test/legacy row
     * constructed directly) falls back to exactly today's original rule
     * — last_activity_at older than the configured timeout — so no
     * previously-healthy deployment is newly flagged just because this
     * phase added a nullable column to its table.
     *
     * Independently of either check, deployment_job_max_runtime_seconds
     * is a hard ceiling: a deployment still heartbeating right on
     * schedule but running far longer than any real deploy reasonably
     * should is also stopped — the same defense-in-depth
     * StalePublishJobRecoveryService applies for PublishJob.
     */
    private function failStalledLegacyDeployments(int $timeoutMinutes, \Illuminate\Support\Carbon $cutoff): int
    {
        $now = now();
        $maxRuntimeSeconds = (int) config('deployment.deployment_job_max_runtime_seconds');
        $runtimeCutoff = $now->copy()->subSeconds($maxRuntimeSeconds);

        $stalled = Deployment::query()
            ->whereIn('status', Deployment::IN_PROGRESS_STATUSES)
            ->where(function ($query) use ($cutoff, $now, $runtimeCutoff) {
                $query->where(function ($legacy) use ($cutoff) {
                    $legacy->whereNull('lease_expires_at')
                        ->where(function ($q) use ($cutoff) {
                            $q->where('last_activity_at', '<', $cutoff)->orWhereNull('last_activity_at');
                        });
                })->orWhere(function ($leased) use ($now) {
                    $leased->whereNotNull('lease_expires_at')->where('lease_expires_at', '<', $now);
                })->orWhere(function ($runaway) use ($runtimeCutoff) {
                    $runaway->whereNotNull('started_at')->where('started_at', '<', $runtimeCutoff);
                });
            })
            ->get();

        foreach ($stalled as $deployment) {
            $runtimeSeconds = $deployment->started_at?->diffInSeconds($now);
            $exceededMaxRuntime = $runtimeSeconds !== null && $runtimeSeconds >= $maxRuntimeSeconds;
            $hadLease = $deployment->lease_expires_at !== null;

            $failureCode = match (true) {
                $exceededMaxRuntime => Deployment::FAILURE_CODE_MAX_RUNTIME_EXCEEDED,
                $hadLease => Deployment::FAILURE_CODE_LEASE_EXPIRED,
                default => Deployment::FAILURE_CODE_AGENT_HEARTBEAT_LOST,
            };

            $failureMessage = $exceededMaxRuntime
                ? "Exceeded the maximum allowed runtime of {$maxRuntimeSeconds}s; forcibly ended regardless of heartbeat activity."
                : "No activity reported for over {$timeoutMinutes} minutes; assumed the agent lost connectivity or crashed mid-job.";

            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'finished_at' => $now,
                'failure_message' => $failureMessage,
                'failure_code' => $failureCode,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'deployment.marked_stalled',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['timeout_minutes' => $timeoutMinutes, 'failure_code' => $failureCode, 'had_lease' => $hadLease],
            );

            // Phase 4-J Task 2: this path previously updated status with no
            // event at all, so a stalled legacy deployment never produced a
            // notification (unlike an agent-reported failure). Purely
            // additive — the status/audit-log logic above is unchanged.
            event(new DeploymentFailed($deployment, $deployment->failure_message));
        }

        return $stalled->count();
    }

    private function failStalledAgentJobs(int $timeoutMinutes, \Illuminate\Support\Carbon $cutoff, DeploymentStatusAggregator $aggregator): int
    {
        $stalled = AgentJob::query()
            ->where('status', AgentJob::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhereNull('last_activity_at');
            })
            ->with('deploymentTask.deployment')
            ->get();

        foreach ($stalled as $agentJob) {
            $agentJob->update(['status' => AgentJob::STATUS_FAILED, 'finished_at' => now()]);

            $task = $agentJob->deploymentTask;
            $task->update(['status' => DeploymentTask::STATUS_FAILED]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'agent_job.marked_stalled',
                subjectType: AgentJob::class,
                subjectId: $agentJob->id,
                metadata: ['timeout_minutes' => $timeoutMinutes, 'deployment_task_id' => $task->id],
            );

            // Recalculating releases the Environment lock once every
            // sibling task has also reached a terminal state.
            $aggregator->recalculate($task->deployment);
        }

        return $stalled->count();
    }
}
