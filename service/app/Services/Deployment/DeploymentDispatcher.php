<?php

namespace App\Services\Deployment;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Creates and moves deployment jobs through: pending_approval -> queued ->
 * (running/succeeded/failed handled by AgentJobService once the agent picks
 * the job up) -> cancelled (terminal, only reachable pre-run).
 */
class DeploymentDispatcher
{
    public function createFromWebhook(Project $project, string $commitHash, string $branch, ?string $pusher): Deployment
    {
        return DB::transaction(function () use ($project, $commitHash, $branch, $pusher) {
            $previousCommit = $project->latestSuccessfulDeployment()?->commit_hash;

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'server_id' => $project->server_id,
                'action' => Deployment::ACTION_DEPLOY,
                'status' => $project->auto_deploy ? Deployment::STATUS_QUEUED : Deployment::STATUS_PENDING_APPROVAL,
                'commit_hash' => $commitHash,
                'previous_commit_hash' => $previousCommit,
                'branch' => $branch,
                'trigger' => Deployment::TRIGGER_WEBHOOK,
                'triggered_by' => $pusher ?? 'github',
                'queued_at' => $project->auto_deploy ? now() : null,
                'approved_by' => $project->auto_deploy ? 'auto_deploy' : null,
                'approved_at' => $project->auto_deploy ? now() : null,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_GITHUB,
                actorIdentifier: $pusher,
                action: $project->auto_deploy ? 'deployment.created_and_auto_approved' : 'deployment.created_pending_approval',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch],
            );

            return $deployment;
        });
    }

    public function createManual(Project $project, string $commitHash, string $triggeredBy): Deployment
    {
        return DB::transaction(function () use ($project, $commitHash, $triggeredBy) {
            $previousCommit = $project->latestSuccessfulDeployment()?->commit_hash;

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'server_id' => $project->server_id,
                'action' => Deployment::ACTION_DEPLOY,
                'status' => Deployment::STATUS_QUEUED,
                'commit_hash' => $commitHash,
                'previous_commit_hash' => $previousCommit,
                'branch' => $project->branch,
                'trigger' => Deployment::TRIGGER_MANUAL,
                'triggered_by' => $triggeredBy,
                'approved_by' => $triggeredBy,
                'approved_at' => now(),
                'queued_at' => now(),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $triggeredBy,
                action: 'deployment.created_manual',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['project_id' => $project->id, 'commit_hash' => $commitHash],
            );

            return $deployment;
        });
    }

    public function approve(Deployment $deployment, string $approvedBy): Deployment
    {
        abort_unless($deployment->status === Deployment::STATUS_PENDING_APPROVAL, 409, 'Only pending_approval deployments can be approved.');

        $deployment->update([
            'status' => Deployment::STATUS_QUEUED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'queued_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'deployment.approved',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
        );

        return $deployment->refresh();
    }

    public function cancel(Deployment $deployment, string $cancelledBy): Deployment
    {
        abort_unless(
            in_array($deployment->status, [Deployment::STATUS_PENDING_APPROVAL, Deployment::STATUS_QUEUED], true),
            409,
            'Only pending_approval or queued deployments can be cancelled.'
        );

        $deployment->update([
            'status' => Deployment::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $cancelledBy,
            action: 'deployment.cancelled',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
        );

        return $deployment->refresh();
    }
}
