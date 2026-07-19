<?php

namespace App\Console\Commands;

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

    private function failStalledLegacyDeployments(int $timeoutMinutes, \Illuminate\Support\Carbon $cutoff): int
    {
        $stalled = Deployment::query()
            ->where('status', Deployment::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhereNull('last_activity_at');
            })
            ->get();

        foreach ($stalled as $deployment) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'finished_at' => now(),
                'failure_message' => "No activity reported for over {$timeoutMinutes} minutes; assumed the agent lost connectivity or crashed mid-job.",
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'deployment.marked_stalled',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['timeout_minutes' => $timeoutMinutes],
            );
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
