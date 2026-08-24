<?php

namespace App\Services\Deployment;

use App\Events\DeploymentStarted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Support\Deployment\SourceWorkspacePath;
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
        private readonly ArtifactService $artifacts,
    ) {
    }

    public function createFromWebhook(Project $project, string $commitHash, string $branch, ?string $pusher): Deployment|PublishJob
    {
        $artifact = $this->artifacts->findMatching($project, $commitHash, $branch);
        if ($project->requires_complete_artifact && $artifact === null) {
            return $this->queueRequiredArtifactBuild($project, $commitHash, $branch, [
                'trigger' => Deployment::TRIGGER_WEBHOOK,
                'triggered_by' => $pusher ?? 'github',
            ]);
        }

        $targets = $this->resolver->resolveTargetsFor($project);

        if ($this->isLegacyTargetSet($targets)) {
            return $this->createLegacyFromWebhook($project, $commitHash, $branch, $pusher, $artifact?->id);
        }

        return $this->createMultiTarget(
            project: $project,
            targets: $targets,
            commitHash: $commitHash,
            branch: $branch,
            trigger: Deployment::TRIGGER_WEBHOOK,
            triggeredBy: $pusher ?? 'github',
            autoApprove: $project->auto_deploy,
            artifactId: $artifact?->id,
        );
    }

    /**
     * @param array{release_label?: ?string, release_notes?: ?string}|null $release
     */
    public function createManual(Project $project, string $commitHash, string $triggeredBy, ?array $release = null): Deployment|PublishJob
    {
        $artifact = $this->artifacts->findMatching($project, $commitHash, $project->branch);
        if ($project->requires_complete_artifact && $artifact === null) {
            return $this->queueRequiredArtifactBuild($project, $commitHash, $project->branch, [
                'trigger' => Deployment::TRIGGER_MANUAL,
                'triggered_by' => $triggeredBy,
                'release' => $release,
            ]);
        }

        $targets = $this->resolver->resolveTargetsFor($project);

        $deployment = $this->isLegacyTargetSet($targets)
            ? $this->createLegacyManual($project, $commitHash, $triggeredBy, $artifact?->id)
            : $this->createMultiTarget(
                project: $project,
                targets: $targets,
                commitHash: $commitHash,
                branch: $project->branch,
                trigger: Deployment::TRIGGER_MANUAL,
                triggeredBy: $triggeredBy,
                autoApprove: true, // manual trigger by an authenticated operator is already the approval, exactly as legacy does today
                artifactId: $artifact?->id,
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

    /**
     * Root-cause fix (no-source-change publish lifecycle gap): the one
     * deployment-creation path that is NOT webhook-driven. A publish with
     * no source changes succeeds without a git push, so no GitHub push
     * webhook ever fires; PublishJobService::bridgeNoSourceChangeDeployment()
     * calls this instead. It reuses the exact same legacy/multi-target
     * creation semantics as the webhook path — status/approval follow the
     * project's auto_deploy flag exactly like a push, the matching available
     * Artifact is attached, and the trigger is recorded as
     * TRIGGER_PUBLISH (with publish_job_id in the audit metadata) so the
     * audit trail says why/how this deployment was created.
     */
    public function createFromPublish(Project $project, string $commitHash, string $branch, string $triggeredBy, ?int $publishJobId = null): Deployment|PublishJob
    {
        $artifact = $this->artifacts->findMatching($project, $commitHash, $branch);
        if ($project->requires_complete_artifact && $artifact === null) {
            return $this->queueRequiredArtifactBuild($project, $commitHash, $branch, [
                'trigger' => Deployment::TRIGGER_PUBLISH,
                'triggered_by' => $triggeredBy,
                'publish_job_id' => $publishJobId,
            ]);
        }

        $targets = $this->resolver->resolveTargetsFor($project);

        if ($this->isLegacyTargetSet($targets)) {
            return $this->createLegacyFromPublish($project, $commitHash, $branch, $triggeredBy, $publishJobId, $artifact?->id);
        }

        return $this->createMultiTarget(
            project: $project,
            targets: $targets,
            commitHash: $commitHash,
            branch: $branch,
            trigger: Deployment::TRIGGER_PUBLISH,
            triggeredBy: $triggeredBy,
            autoApprove: $project->auto_deploy,
            auditAction: $project->auto_deploy ? 'deployment.created_from_publish' : 'deployment.created_from_publish_pending_approval',
            auditActorType: AuditLogEntry::ACTOR_AGENT,
            auditMetadata: ['publish_job_id' => $publishJobId],
            artifactId: $artifact?->id,
        );
    }

    /** Complete the deployment request retained on an artifact build job. */
    public function resumeRequiredArtifactDeployment(PublishJob $job): ?Deployment
    {
        if ($job->deployment_id !== null) {
            return $job->deployment;
        }

        $request = $job->deployment_request;
        if (!is_array($request)) {
            return null;
        }

        $deployment = match ($request['trigger'] ?? null) {
            Deployment::TRIGGER_WEBHOOK => $this->createFromWebhook($job->project, (string) $job->reviewed_head_sha, (string) $job->reviewed_branch, $request['triggered_by'] ?? null),
            Deployment::TRIGGER_MANUAL => $this->createManual($job->project, (string) $job->reviewed_head_sha, (string) $request['triggered_by'], $request['release'] ?? null),
            Deployment::TRIGGER_PUBLISH => $this->createFromPublish($job->project, (string) $job->reviewed_head_sha, (string) $job->reviewed_branch, (string) $request['triggered_by'], $request['publish_job_id'] ?? null),
            default => throw new \LogicException('Artifact build has an invalid deferred deployment trigger.'),
        };

        if (!$deployment instanceof Deployment) {
            throw new \LogicException('Artifact build succeeded but no matching available artifact was found.');
        }

        $job->update(['deployment_id' => $deployment->id]);

        return $deployment;
    }

    public function approve(Deployment $deployment, string $approvedBy): Deployment
    {
        abort_unless($deployment->status === Deployment::STATUS_PENDING_APPROVAL, 409, 'Only pending_approval deployments can be approved.');

        // A Local Agent can finish uploading an artifact while this
        // deployment is awaiting human approval. Resolve it at the final
        // pre-queue boundary so the queued job has one deterministic
        // artifact_id before either claim path can make it executable.
        $this->attachArtifactIfAvailable($deployment);

        abort_if(
            $deployment->project->requires_complete_artifact && $deployment->artifact_id === null,
            409,
            'An artifact-required deployment cannot enter the queue without an available artifact.'
        );

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
     * Root-cause fix (customer-site direct-GitHub-dependency removal):
     * called at each pre-queue boundary in this dispatcher. A Project
     * whose Local Agent never uploaded a matching artifact for this
     * exact commit (every Project not using the Local Agent artifact-
     * publish flow, or one where the upload simply hasn't landed yet)
     * leaves artifact_id null — AgentJobService::claimNextJob() then
     * omits `artifact` from the job payload and the agent runs the
     * exact legacy git-based pipeline, unchanged.
     */
    private function attachArtifactIfAvailable(Deployment $deployment): void
    {
        $this->artifacts->attachToDeploymentIfAvailable($deployment);
    }

    /**
     * @param array<int, TargetView> $targets
     */
    private function isLegacyTargetSet(array $targets): bool
    {
        return count($targets) === 1 && $targets[0]->isSynthesized;
    }

    // --- Legacy path: one shared body, three trigger variants -------------
    // The webhook/manual variants below are the exact pre-Phase-3-C.2
    // method bodies, extracted into one shared createLegacy() so the
    // publish bridge reuses them instead of duplicating a third
    // near-identical creation. Both existing variants' behavior is
    // unchanged — each wrapper passes exactly the values its original
    // body hardcoded.

    private function createLegacyFromWebhook(Project $project, string $commitHash, string $branch, ?string $pusher, ?int $artifactId): Deployment
    {
        return $this->createLegacy(
            project: $project,
            commitHash: $commitHash,
            branch: $branch,
            trigger: Deployment::TRIGGER_WEBHOOK,
            triggeredBy: $pusher ?? 'github',
            autoApprove: $project->auto_deploy,
            approvedBy: 'auto_deploy',
            auditAction: $project->auto_deploy ? 'deployment.created_and_auto_approved' : 'deployment.created_pending_approval',
            auditActorType: AuditLogEntry::ACTOR_GITHUB,
            auditActorIdentifier: $pusher,
            auditMetadata: ['project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch],
            artifactId: $artifactId,
        );
    }

    private function createLegacyManual(Project $project, string $commitHash, string $triggeredBy, ?int $artifactId): Deployment
    {
        return $this->createLegacy(
            project: $project,
            commitHash: $commitHash,
            branch: $project->branch,
            trigger: Deployment::TRIGGER_MANUAL,
            triggeredBy: $triggeredBy,
            autoApprove: true,
            approvedBy: $triggeredBy,
            auditAction: 'deployment.created_manual',
            auditActorType: AuditLogEntry::ACTOR_USER,
            auditActorIdentifier: $triggeredBy,
            auditMetadata: ['project_id' => $project->id, 'commit_hash' => $commitHash],
            artifactId: $artifactId,
        );
    }

    private function createLegacyFromPublish(Project $project, string $commitHash, string $branch, string $triggeredBy, ?int $publishJobId, ?int $artifactId): Deployment
    {
        return $this->createLegacy(
            project: $project,
            commitHash: $commitHash,
            branch: $branch,
            trigger: Deployment::TRIGGER_PUBLISH,
            triggeredBy: $triggeredBy,
            autoApprove: $project->auto_deploy,
            approvedBy: 'auto_deploy',
            auditAction: $project->auto_deploy ? 'deployment.created_from_publish' : 'deployment.created_from_publish_pending_approval',
            auditActorType: AuditLogEntry::ACTOR_AGENT,
            auditActorIdentifier: $triggeredBy,
            auditMetadata: ['publish_job_id' => $publishJobId, 'project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch],
            artifactId: $artifactId,
        );
    }

    /**
     * @param array<string, mixed> $auditMetadata
     */
    private function createLegacy(
        Project $project,
        string $commitHash,
        string $branch,
        string $trigger,
        string $triggeredBy,
        bool $autoApprove,
        string $approvedBy,
        string $auditAction,
        string $auditActorType,
        ?string $auditActorIdentifier,
        array $auditMetadata,
        ?int $artifactId,
    ): Deployment {
        return DB::transaction(function () use ($project, $commitHash, $branch, $trigger, $triggeredBy, $autoApprove, $approvedBy, $auditAction, $auditActorType, $auditActorIdentifier, $auditMetadata, $artifactId) {
            $previousCommit = $project->latestSuccessfulDeployment()?->commit_hash;

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'server_id' => $project->server_id,
                'action' => Deployment::ACTION_DEPLOY,
                'status' => $autoApprove ? Deployment::STATUS_QUEUED : Deployment::STATUS_PENDING_APPROVAL,
                'commit_hash' => $commitHash,
                'previous_commit_hash' => $previousCommit,
                'branch' => $branch,
                'trigger' => $trigger,
                'triggered_by' => $triggeredBy,
                'queued_at' => $autoApprove ? now() : null,
                'approved_by' => $autoApprove ? $approvedBy : null,
                'approved_at' => $autoApprove ? now() : null,
                'artifact_id' => $artifactId,
            ]);

            // artifact_id is assigned in the INSERT itself. Required projects
            // never reach this method without one; their build job retains the
            // request and resumes creation only after upload succeeds.

            AuditLogEntry::record(
                actorType: $auditActorType,
                actorIdentifier: $auditActorIdentifier,
                action: $auditAction,
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: $auditMetadata,
            );

            if ($deployment->status === Deployment::STATUS_QUEUED) {
                event(new DeploymentStarted($deployment));
            }

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
        ?string $auditAction = null,
        ?string $auditActorType = null,
        array $auditMetadata = [],
        ?int $artifactId = null,
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

        return DB::transaction(function () use ($project, $targets, $commitHash, $branch, $trigger, $triggeredBy, $autoApprove, $auditAction, $auditActorType, $auditMetadata, $artifactId) {
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
                'approved_by' => $autoApprove ? ($trigger === Deployment::TRIGGER_WEBHOOK || $trigger === Deployment::TRIGGER_PUBLISH ? 'auto_deploy' : $triggeredBy) : null,
                'approved_at' => $autoApprove ? now() : null,
                'artifact_id' => $artifactId,
            ]);

            // artifact_id is assigned before any queued DeploymentTasks exist.
            // Required projects are deferred to an artifact build before this
            // transaction, so no claimable null-artifact task can be created.

            if ($autoApprove && $deployment->status === Deployment::STATUS_QUEUED) {
                $this->createTasksForDeployment($deployment, $targets);
            }

            $action = $auditAction ?? ($autoApprove ? 'deployment.created_multi_target_and_auto_approved' : 'deployment.created_multi_target_pending_approval');
            $actorType = $auditActorType ?? ($trigger === Deployment::TRIGGER_WEBHOOK ? AuditLogEntry::ACTOR_GITHUB : AuditLogEntry::ACTOR_USER);

            AuditLogEntry::record(
                actorType: $actorType,
                actorIdentifier: $triggeredBy,
                action: $action,
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: array_merge(['project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch, 'target_count' => count($targets)], $auditMetadata),
            );

            if ($autoApprove && $deployment->status === Deployment::STATUS_QUEUED) {
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

    /**
     * Queue the prerequisite build without creating any Deployment row.
     * The build report later calls resumeRequiredArtifactDeployment().
     *
     * @param array<string, mixed> $deploymentRequest
     */
    private function queueRequiredArtifactBuild(Project $project, string $commitHash, string $branch, array $deploymentRequest): PublishJob
    {
        $mapping = LocalProjectMapping::query()
            ->where('project_id', $project->id)
            ->whereHas('localAgent', fn ($query) => $query->where('status', LocalAgent::STATUS_APPROVED))
            ->with(['localAgent', 'project'])
            ->orderByDesc('reported_at')
            ->get()
            ->first(function (LocalProjectMapping $candidate) use ($branch): bool {
                $agentVersion = $candidate->localAgent?->agent_version;
                $minimumVersion = (string) config('deployment.min_local_agent_version_for_artifact');

                return $candidate->effectiveBranch() === $branch
                    && is_string($agentVersion)
                    && version_compare($agentVersion, $minimumVersion, '>=')
                    && !SourceWorkspacePath::isProductionLike($candidate->sourceWorkspacePath());
            });

        abort_if($mapping === null, 422, 'This artifact-required project has no approved, artifact-capable Local Agent mapping with an isolated source workspace for the requested branch.');

        $requestedBy = (string) ($deploymentRequest['triggered_by'] ?? 'system');
        $job = PublishJob::create([
            'local_agent_id' => $mapping->local_agent_id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_APPROVED,
            'requested_by' => $requestedBy,
            'approved_by' => $requestedBy,
            'approved_at' => now(),
            'reviewed_head_sha' => $commitHash,
            'reviewed_branch' => $branch,
            'reviewed_working_tree_clean' => true,
            'deployment_request' => $deploymentRequest,
        ]);

        AuditLogEntry::record(
            actorType: ($deploymentRequest['trigger'] ?? null) === Deployment::TRIGGER_WEBHOOK ? AuditLogEntry::ACTOR_GITHUB : AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: 'publish_job.artifact_build_queued_for_deployment',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch, 'trigger' => $deploymentRequest['trigger'] ?? null],
        );

        return $job;
    }
}
