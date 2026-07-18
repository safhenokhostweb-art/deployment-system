<?php

namespace App\Services\Deployment;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class RollbackService
{
    /**
     * Roll back to a specific prior successful deployment. Reuses the
     * project's normal pipeline — the agent treats a rollback exactly like
     * any other deploy job, just targeting an older commit.
     */
    public function rollbackTo(Deployment $target, string $actor): Deployment
    {
        abort_unless($target->status === Deployment::STATUS_SUCCEEDED, 409, 'Can only roll back to a previously successful deployment.');

        return DB::transaction(function () use ($target, $actor) {
            $project = $target->project;
            $currentCommit = $project->latestSuccessfulDeployment()?->commit_hash;

            $rollback = Deployment::create([
                'project_id' => $project->id,
                'server_id' => $project->server_id,
                'action' => Deployment::ACTION_ROLLBACK,
                'status' => Deployment::STATUS_QUEUED,
                'commit_hash' => $target->commit_hash,
                'previous_commit_hash' => $currentCommit,
                'branch' => $target->branch,
                'trigger' => Deployment::TRIGGER_ROLLBACK,
                'triggered_by' => $actor,
                'approved_by' => $actor,
                'approved_at' => now(),
                'queued_at' => now(),
                'rollback_of_deployment_id' => $target->id,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actor,
                action: 'deployment.rollback_triggered',
                subjectType: Deployment::class,
                subjectId: $rollback->id,
                metadata: [
                    'project_id' => $project->id,
                    'target_commit' => $target->commit_hash,
                    'target_deployment_id' => $target->id,
                    'reverting_from_commit' => $currentCommit,
                ],
            );

            return $rollback;
        });
    }

    /**
     * Convenience: roll back to the last successful deployment before the
     * project's current one.
     */
    public function rollbackToPrevious(Project $project, string $actor): Deployment
    {
        $candidates = $project->deployments()
            ->where('status', Deployment::STATUS_SUCCEEDED)
            ->latest('finished_at')
            ->take(2)
            ->get();

        abort_if($candidates->count() < 2, 409, 'No earlier successful deployment to roll back to.');

        return $this->rollbackTo($candidates->last(), $actor);
    }
}
