<?php

namespace App\Services\Deployment;

use App\Events\DeploymentStarted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Creates and moves deployment jobs through: pending_approval -> queued ->
 * (running/succeeded/failed handled by AgentJobService once the agent picks
 * the job up) -> cancelled (terminal, only reachable pre-run).
 *
 * Phase 3-C.2: every entry point now routes through DeploymentTargetResolver
 * first. A legacy Project (no Environment rows — true for every Project that
 * existed before this phase) always resolves to exactly one synthesized
 * target, and createLegacyFromWebhook()/createLegacyManual() below are the
 * *exact, unmodified* bodies of the pre-Phase-3-C.2 createFromWebhook()/
 * createManual() methods — only extracted into private methods, not
 * rewritten — so legacy behavior is unchanged by construction, not by
 * hope. A Project with real Environment/DeploymentTarget rows takes the new
 * multi-target path instead, and never falls back to the legacy one even
 * if it currently has zero active targets (see createMultiTarget()).
 *
 * Phase 3-D: the one deliberate addition to the legacy bodies is a single
 * `event(new DeploymentStarted(...))` call when a deployment reaches
 * `queued` — dispatching an event with no registered listener changes no
 * behavior (Task 7's notification foundation; see
 * docs/PHASE_3D_PRODUCTION_READINESS_REPORT.md §8).
 */
class DeploymentDispatcher
{
    public function __construct(
        private readonly DeploymentTargetResolver $resolver,
    ) {
    }

    public function createFromWebhook(Project $project, string $commitHash, string $branch, ?string $pusher): Deployment
    {
        $targets = $this->resolver->resolveTargetsFor($project);

        if ($this->isLegacyTargetSet($targets)) {
            return $this->createLegacyFromWebhook($project, $commitHash, $branch, $pusher);
        }

        return $this->createMultiTarget(
            project: $project,
            targets: $targets,
            commitHash: $commitHash,
            branch: $branch,
            trigger: Deployment::TRIGGER_WEBHOOK,
            triggeredBy: $pusher ?? 'github',
            autoApprove: $project->auto_deploy,
        );
    }

    /**
     * @param array{release_label?: ?string, release_notes?: ?string}|null $release
     */
    public function createManual(Project $project, string $commitHash, string $triggeredBy, ?array $release = null): Deployment
    {
        $targets = $this->resolver->resolveTargetsFor($project);

        $deployment = $this->isLegacyTargetSet($targets)
            ? $this->createLegacyManual($project, $commitHash, $triggeredBy)
            : $this->createMultiTarget(
                project: $project,
                targets: $targets,
                commitHash: $commitHash,
                branch: $project->branch,
                trigger: Deployment::TRIGGER_MANUAL,
                triggeredBy: $triggeredBy,
                autoApprove: true, // manual trigger by an authenticated operator is already the approval, exactly as legacy does today
            );

        // Phase 4-I Task 7: a deliberate follow-up update, not a change to
        // either creation path above — createLegacyManual()'s body stays
        // the exact, unmodified pre-3-C.2 method, and createMultiTarget()
        // is untouched too. A caller that never sends a release label (any
        // pre-4-I caller, including every existing test) triggers no extra
        // write at all.
        if (!empty($release['release_label']) || !empty($release['release_notes'])) {
            $deployment->update([
                'release_label' => $release['release_label'] ?? null,
                'release_notes' => $release['release_notes'] ?? null,
            ]);
        }

        return $deployment;
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

        // New-domain deployments create their DeploymentTasks at the exact
        // moment they become queued, not at creation time — a
        // pending_approval deployment must have nothing claimable sitting
        // around before a human has actually approved it. Legacy
        // deployments never have tasks, so this is a no-op for them.
        if ($deployment->tasks()->doesntExist()) {
            $targets = $this->resolver->resolveTargetsFor($deployment->project);
            if (!$this->isLegacyTargetSet($targets)) {
                $this->createTasksForDeployment($deployment, $targets);
            }
        }

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'deployment.approved',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
        );

        event(new DeploymentStarted($deployment));

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

        // Multi-target: cancelling the parent also cancels any of its
        // tasks that never got claimed. A legacy deployment has no tasks.
        $deployment->tasks()->where('status', DeploymentTask::STATUS_QUEUED)
            ->update(['status' => DeploymentTask::STATUS_CANCELLED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $cancelledBy,
            action: 'deployment.cancelled',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
        );

        return $deployment->refresh();
    }

    /**
     * @param array<int, TargetView> $targets
     */
    private function isLegacyTargetSet(array $targets): bool
    {
        return count($targets) === 1 && $targets[0]->isSynthesized;
    }

    // --- Legacy path: unmodified bodies of the pre-3-C.2 methods --------

    private function createLegacyFromWebhook(Project $project, string $commitHash, string $branch, ?string $pusher): Deployment
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

            if ($deployment->status === Deployment::STATUS_QUEUED) {
                event(new DeploymentStarted($deployment));
            }

            return $deployment;
        });
    }

    private function createLegacyManual(Project $project, string $commitHash, string $triggeredBy): Deployment
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

            event(new DeploymentStarted($deployment));

            return $deployment;
        });
    }

    // --- New domain path --------------------------------------------------

    /**
     * @param array<int, TargetView> $targets
     */
    private function createMultiTarget(
        Project $project,
        array $targets,
        string $commitHash,
        string $branch,
        string $trigger,
        string $triggeredBy,
        bool $autoApprove,
    ): Deployment {
        if (count($targets) === 0) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: $triggeredBy,
                action: 'deployment.rejected_no_active_targets',
                subjectType: Project::class,
                subjectId: $project->id,
            );

            // Fail-closed: a project with a real Environment but no active
            // DeploymentTarget must never silently fall back to the legacy
            // single-target path (Task 3's explicit rule).
            abort(422, "Project '{$project->name}' has an Environment configured but no active deployment targets.");
        }

        return DB::transaction(function () use ($project, $targets, $commitHash, $branch, $trigger, $triggeredBy, $autoApprove) {
            $previousCommit = $project->latestSuccessfulDeployment()?->commit_hash;

            $deployment = Deployment::create([
                'project_id' => $project->id,
                // Not authoritative for a multi-target deployment — the
                // per-target truth lives in DeploymentTask ->
                // DeploymentTarget -> Server. Populated only because
                // `deployments.server_id` remains a required column (never
                // relaxed — see the Phase 3-C.2 report on why altering it
                // was deliberately avoided).
                'server_id' => $targets[0]->serverId,
                'action' => Deployment::ACTION_DEPLOY,
                'status' => $autoApprove ? Deployment::STATUS_QUEUED : Deployment::STATUS_PENDING_APPROVAL,
                'commit_hash' => $commitHash,
                'previous_commit_hash' => $previousCommit,
                'branch' => $branch,
                'trigger' => $trigger,
                'triggered_by' => $triggeredBy,
                'queued_at' => $autoApprove ? now() : null,
                'approved_by' => $autoApprove ? ($trigger === Deployment::TRIGGER_WEBHOOK ? 'auto_deploy' : $triggeredBy) : null,
                'approved_at' => $autoApprove ? now() : null,
            ]);

            if ($autoApprove) {
                $this->createTasksForDeployment($deployment, $targets);
            }

            AuditLogEntry::record(
                actorType: $trigger === Deployment::TRIGGER_WEBHOOK ? AuditLogEntry::ACTOR_GITHUB : AuditLogEntry::ACTOR_USER,
                actorIdentifier: $triggeredBy,
                action: $autoApprove ? 'deployment.created_multi_target_and_auto_approved' : 'deployment.created_multi_target_pending_approval',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch, 'target_count' => count($targets)],
            );

            if ($autoApprove) {
                event(new DeploymentStarted($deployment));
            }

            return $deployment;
        });
    }

    /**
     * @param array<int, TargetView> $targets
     */
    private function createTasksForDeployment(Deployment $deployment, array $targets): void
    {
        foreach ($targets as $target) {
            DeploymentTask::create([
                'deployment_id' => $deployment->id,
                'deployment_target_id' => $target->deploymentTargetId,
                'status' => DeploymentTask::STATUS_QUEUED,
            ]);
        }
    }
}
