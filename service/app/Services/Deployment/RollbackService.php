<?php

namespace App\Services\Deployment;

use App\Events\RollbackStarted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class RollbackService
{
    public function __construct(
        private readonly DeploymentTargetResolver $resolver,
        private readonly DeploymentTaskCreator $taskCreator,
    ) {
    }

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
            $targets = $this->resolver->resolveTargetsFor($project);

            abort_if(
                count($targets) === 0,
                422,
                "Project '{$project->name}' has an Environment configured but no active deployment targets.",
            );

            $usesPersistedTargets = !($targets[0]->isSynthesized ?? false);

            abort_if(
                $project->requires_complete_artifact && $target->artifact_id === null,
                409,
                'An artifact-required rollback must target a deployment with an available artifact.',
            );

            $rollback = Deployment::create([
                'project_id' => $project->id,
                'server_id' => $usesPersistedTargets ? $targets[0]->serverId : $project->server_id,
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
                'artifact_id' => $target->artifact_id,
                'http_health_config' => $project->deploymentHttpHealthConfig(),
                'http_health_status' => $project->requiresHttpHealthVerification()
                    ? Deployment::HTTP_HEALTH_PENDING
                    : Deployment::HTTP_HEALTH_NOT_CONFIGURED,
            ]);

            if ($usesPersistedTargets) {
                $this->taskCreator->createForTargets($rollback, $targets);
            }

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

            // Phase 4-J Task 2: mirrors DeploymentDispatcher::approve()'s own
            // "audit log then event" ordering — purely additive
            // instrumentation, no change to the rollback itself.
            DB::afterCommit(fn () => event(new RollbackStarted($rollback)));

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

    /**
     * Task 4 (Phase 3-D): new-domain, single-target rollback. Creates a
     * new aggregate Deployment with exactly one DeploymentTask — for the
     * same DeploymentTarget as $targetTask, and no other — so sibling
     * targets in the same Environment are never touched. Legacy
     * rollbackTo()/rollbackToPrevious() above are completely unaffected.
     */
    public function rollbackTargetTo(DeploymentTask $targetTask, string $actor): Deployment
    {
        abort_unless($targetTask->status === DeploymentTask::STATUS_SUCCEEDED, 409, 'Can only roll back a target to a previously successful task.');

        return DB::transaction(function () use ($targetTask, $actor) {
            $target = $targetTask->deploymentTarget;
            $rollbackCommit = $targetTask->deployment->commit_hash;

            $currentCommit = $target->deploymentTasks()
                ->where('status', DeploymentTask::STATUS_SUCCEEDED)
                ->latest('id')
                ->first()
                ?->deployment
                ?->commit_hash;

            $project = $target->environment->project;

            $rollbackDeployment = Deployment::create([
                'project_id' => $project->id,
                // Non-authoritative for a multi-target row — same
                // convention as DeploymentDispatcher's multi-target
                // creation (docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md §12).
                'server_id' => $target->server_id,
                'action' => Deployment::ACTION_ROLLBACK,
                'status' => Deployment::STATUS_QUEUED,
                'commit_hash' => $rollbackCommit,
                'previous_commit_hash' => $currentCommit,
                'branch' => $targetTask->deployment->branch,
                'trigger' => Deployment::TRIGGER_ROLLBACK,
                'triggered_by' => $actor,
                'approved_by' => $actor,
                'approved_at' => now(),
                'queued_at' => now(),
                'rollback_of_deployment_id' => $targetTask->deployment_id,
                'http_health_config' => $project->deploymentHttpHealthConfig(),
                'http_health_status' => $project->requiresHttpHealthVerification()
                    ? Deployment::HTTP_HEALTH_PENDING
                    : Deployment::HTTP_HEALTH_NOT_CONFIGURED,
            ]);

            $this->taskCreator->createForTargetIds($rollbackDeployment, [$target->id]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actor,
                action: 'deployment_task.rollback_triggered',
                subjectType: Deployment::class,
                subjectId: $rollbackDeployment->id,
                metadata: [
                    'deployment_target_id' => $target->id,
                    'target_name' => $target->name,
                    'target_commit' => $rollbackCommit,
                    'reverting_from_commit' => $currentCommit,
                ],
            );

            event(new RollbackStarted($rollbackDeployment));

            return $rollbackDeployment;
        });
    }

    /**
     * Convenience: roll a single target back to its last successful task
     * before the current one.
     */
    public function rollbackTargetToPrevious(DeploymentTarget $target, string $actor): Deployment
    {
        $candidates = $target->deploymentTasks()
            ->where('status', DeploymentTask::STATUS_SUCCEEDED)
            ->latest('id')
            ->take(2)
            ->get();

        abort_if($candidates->count() < 2, 409, 'No earlier successful deployment for this target to roll back to.');

        return $this->rollbackTargetTo($candidates->last(), $actor);
    }
}
