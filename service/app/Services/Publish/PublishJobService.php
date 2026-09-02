<?php

namespace App\Services\Publish;

use App\Events\PublishJobAbandoned;
use App\Events\PublishJobAwaitingApproval;
use App\Events\PublishJobFailed;
use App\Events\PublishJobSucceeded;
use App\Models\AuditLogEntry;
use App\Models\Artifact;
use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Releases\ReleaseEvidenceService;
use App\Services\Releases\ReleaseOrchestrator;
use App\Services\Security\SecretRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5: queue/claim/report lifecycle for a Local Agent Publish or
 * Rollback request — the same shape as OperationJobService, adapted for
 * PublishJob's extra approval step (status_check -> awaiting_approval ->
 * approved -> running -> succeeded/failed). A rollback skips the
 * approval-with-commit-message step (queue() immediately followed by
 * approveRollback()) since there is nothing to preview before reverting.
 *
 * Root-cause fix (stuck "running" publishes): every transition into or
 * out of `running` now goes through this one service — claimNext()
 * establishes a lease and (for publish/rollback only) a per-Project
 * publish lock, heartbeat() is the only way either is renewed, and
 * reportResult()/reportFailure()/markAbandoned() are the only ways out —
 * each releasing the lock it may hold. No controller, job, or Blade view
 * writes PublishJob::status directly.
 */
class PublishJobService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly DeploymentDispatcher $deployments,
        private readonly AuthoritativeBuildAgentResolver $buildAgents,
        private readonly ReleaseEvidenceService $releaseEvidence,
        private readonly ReleaseOrchestrator $releases,
    ) {
    }

    public function queue(LocalAgent $localAgent, Project $project, string $action, ?string $requestedBy): PublishJob
    {
        abort_unless(
            in_array($action, [PublishJob::ACTION_STATUS_CHECK, PublishJob::ACTION_ROLLBACK], true),
            422,
            "A publish job can only be queued as '" . PublishJob::ACTION_STATUS_CHECK . "' or '" . PublishJob::ACTION_ROLLBACK . "' — 'publish' is reached via approve(), never queued directly."
        );

        $job = PublishJob::create([
            'local_agent_id' => $localAgent->id,
            'project_id' => $project->id,
            'action' => $action,
            'status' => PublishJob::STATUS_QUEUED,
            'requested_by' => $requestedBy,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: 'publish_job.queued',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['local_agent_id' => $localAgent->id, 'project_id' => $project->id, 'action' => $action],
        );

        return $job;
    }

    public function queueArtifactBuild(LocalAgent $localAgent, Project $project, string $commitHash, string $branch, string $requestedBy, ?Release $release = null): PublishJob
    {
        abort_unless(preg_match('/^[0-9a-f]{40}$/', $commitHash) === 1, 422, 'Artifact build requires a valid 40-character commit SHA.');
        abort_unless($branch !== '', 422, 'Artifact build requires a branch.');
        $mapping = $this->buildAgents->resolve($project, $branch);
        abort_unless($mapping->local_agent_id === $localAgent->id, 409, 'Artifact builds must use the project authoritative production build Agent.');
        // An exact-commit Artifact build is the boundary at which the Hub
        // must have one authoritative Release identity. Webhook builds
        // already pass their Release; operator-queued Dashboard builds may
        // legitimately arrive before webhook/project metadata, so resolve
        // or create that same project+commit contract here. The database
        // unique key on releases(project_id, commit_hash) makes concurrent
        // Dashboard/webhook resolution unambiguous.
        $release ??= $this->releases->createForCommit($project, $commitHash, $branch);
        $this->assertArtifactBuildRelease($release, $project, $commitHash, $branch);

        $activeBuildKey = "artifact:{$project->id}:{$commitHash}";

        try {
            $job = DB::transaction(function () use ($localAgent, $project, $commitHash, $branch, $requestedBy, $release, $activeBuildKey): PublishJob {
            $release = Release::query()->lockForUpdate()->find($release->id);
            abort_unless($release !== null, 409, 'Artifact build Release no longer exists.');
            $this->assertArtifactBuildRelease($release, $project, $commitHash, $branch);

            // Concurrency: a build for this exact project+commit may already
            // be in flight (a double click, a webhook-triggered auto-build,
            // or a racing request) — reuse it instead of racing the same
            // Agent through two isolated build workspaces for an identical
            // commit. Locked so two simultaneous requests can't both read
            // "none exists yet" and both insert.
            $existingBuild = PublishJob::query()
                ->where('project_id', $project->id)
                ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
                ->where('reviewed_head_sha', $commitHash)
                ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING])
                ->lockForUpdate()
                ->first();
            if ($existingBuild !== null) {
                if ($existingBuild->release_id === null || $existingBuild->evidence_contract === null) {
                    abort_if($existingBuild->release_id !== null && $existingBuild->release_id !== $release->id, 409, 'Active artifact build belongs to a different Release.');
                    $existingBuild->release()->associate($release);
                    $existingBuild->evidence_contract = $this->releaseEvidence->issue($existingBuild, $release, $localAgent);
                    $existingBuild->save();
                }
                return $existingBuild;
            }

            $job = PublishJob::create([
                'local_agent_id' => $localAgent->id,
                'project_id' => $project->id,
                'release_id' => $release->id,
                'active_build_key' => $activeBuildKey,
                'action' => PublishJob::ACTION_BUILD_ARTIFACT,
                'status' => PublishJob::STATUS_APPROVED,
                'requested_by' => $requestedBy,
                'approved_by' => $requestedBy,
                'approved_at' => now(),
                'reviewed_head_sha' => $commitHash,
                'reviewed_branch' => $branch,
                'reviewed_working_tree_clean' => true,
            ]);

            $job->update(['evidence_contract' => $this->releaseEvidence->issue($job, $release, $localAgent)]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $requestedBy,
                action: 'publish_job.artifact_build_queued',
                subjectType: PublishJob::class,
                subjectId: $job->id,
                metadata: ['local_agent_id' => $localAgent->id, 'project_id' => $project->id, 'commit_hash' => $commitHash, 'branch' => $branch],
            );

            return $job;
            });
            $this->releases->markArtifactBuilding($release);
            return $job;
        } catch (QueryException $e) {
            $existing = PublishJob::query()->where('active_build_key', $activeBuildKey)->first();
            if ($existing !== null) {
                abort_if($existing->release_id !== null && $existing->release_id !== $release->id, 409, 'Active artifact build belongs to a different Release.');
                if ($existing->release_id === null || $existing->evidence_contract === null) {
                    $existing->release()->associate($release);
                    $existing->evidence_contract = $this->releaseEvidence->issue($existing, $release, $localAgent);
                    $existing->save();
                }
                $this->releases->markArtifactBuilding($release);
                return $existing;
            }
            throw $e;
        }
    }

    private function assertArtifactBuildRelease(Release $release, Project $project, string $commitHash, string $branch): void
    {
        abort_unless(
            $release->project_id === $project->id
                && hash_equals($release->commit_hash, $commitHash)
                && $release->branch === $branch,
            409,
            'Artifact build Release does not match the exact project, commit, and branch contract.',
        );
    }

    /**
     * Phase 5.2: the LocalChangeDetectionJob path — files are already
     * known (the agent's own watcher reported them), so this skips
     * straight to `approved` instead of going through
     * queue(status_check) -> reportStatusCheck() -> approve(). Used both
     * for a human's one-click "Approve Publish" (triggeredBy: approval)
     * and the AutoPublishStableChanges scheduled command
     * (triggeredBy: auto, $approvedBy is a system identifier, not a real
     * user — logged as ACTOR_SYSTEM accordingly).
     */
    public function queueApprovedPublish(LocalAgent $localAgent, Project $project, string $commitMessage, string $approvedBy, string $triggeredBy = 'approval', ?array $noSourceEvidence = null): PublishJob
    {
        if ($noSourceEvidence !== null) {
            abort_unless(isset($noSourceEvidence['head_sha'], $noSourceEvidence['branch'], $noSourceEvidence['mapping_id']), 422, 'A no-source-change retry requires complete verified evidence.');
        }

        $job = PublishJob::create([
            'local_agent_id' => $localAgent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
            'commit_message' => $commitMessage,
            'requested_by' => $approvedBy,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'no_source_change' => $noSourceEvidence !== null,
            'reviewed_head_sha' => $noSourceEvidence['head_sha'] ?? null,
            'reviewed_branch' => $noSourceEvidence['branch'] ?? null,
            'reviewed_mapping_id' => $noSourceEvidence['mapping_id'] ?? null,
            'reviewed_working_tree_clean' => $noSourceEvidence !== null ? true : null,
        ]);

        AuditLogEntry::record(
            actorType: $triggeredBy === 'auto' ? AuditLogEntry::ACTOR_SYSTEM : AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'publish_job.queued',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['local_agent_id' => $localAgent->id, 'project_id' => $project->id, 'action' => PublishJob::ACTION_PUBLISH, 'triggered_by' => $triggeredBy],
        );

        return $job;
    }

    /**
     * Atomic: wrapped in a transaction with row locks on every candidate
     * so two concurrent claim attempts (a genuine bug — a Local Agent
     * only ever polls one at a time by construction — or a future
     * multi-agent scenario) can never both claim the same row. A
     * publish/rollback candidate whose Project is currently
     * publish-locked by another agent's in-flight job is skipped (not
     * failed) in favor of the next queued candidate — status_check never
     * touches the shared remote, so it is never subject to this lock.
     */
    public function claimNext(LocalAgent $localAgent): ?PublishJob
    {
        return DB::transaction(function () use ($localAgent) {
            $candidates = PublishJob::query()
                ->where('local_agent_id', $localAgent->id)
                ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED])
                ->oldest('created_at')
                ->lockForUpdate()
            ->get();

            foreach ($candidates as $job) {
                if ($job->action === PublishJob::ACTION_BUILD_ARTIFACT) {
                    $minimum = (string) config('deployment.min_local_agent_version_for_assigned_artifact');
                    abort_unless(
                        $localAgent->supportsAssignedArtifactUploads(),
                        409,
                        "Assigned artifact builds require Local Agent {$minimum} or newer with assigned_artifact_publish_job_id capability; upgrade required before artifact work begins.",
                    );
                }

                if ($job->no_source_change && !$this->noSourceChangeEvidenceIsStillValid($job)) {
                    $job->update([
                        'status' => PublishJob::STATUS_REJECTED,
                        'error_message' => 'Verified no-source-change mapping evidence is no longer valid.',
                        'finished_at' => now(),
                    ]);

                    AuditLogEntry::record(
                        actorType: AuditLogEntry::ACTOR_SYSTEM,
                        actorIdentifier: null,
                        action: 'publish_job.no_source_change_rejected',
                        subjectType: PublishJob::class,
                        subjectId: $job->id,
                    );

                    continue;
                }

                $touchesSharedRemote = $job->action !== PublishJob::ACTION_STATUS_CHECK;
                $project = $touchesSharedRemote ? Project::query()->lockForUpdate()->find($job->project_id) : null;

                if ($project !== null && $project->isPublishLockedByAnotherJob($job->id)) {
                    continue;
                }

                $now = now();
                $leaseSeconds = (int) config('deployment.publish_job_lease_seconds');

                $job->update([
                    'status' => PublishJob::STATUS_RUNNING,
                    'started_at' => now(),
                    'heartbeat_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                ]);

                if ($project !== null) {
                    $project->update([
                        'locked_by_publish_job_id' => $job->id,
                        'locked_by_local_agent_id' => $localAgent->id,
                        'locked_at' => $now,
                        'lock_expires_at' => $now->copy()->addSeconds((int) config('deployment.publish_lock_ttl_seconds')),
                    ]);

                    AuditLogEntry::record(
                        actorType: AuditLogEntry::ACTOR_AGENT,
                        actorIdentifier: $localAgent->public_id,
                        action: 'project.publish_lock_acquired',
                        subjectType: Project::class,
                        subjectId: $project->id,
                        metadata: ['publish_job_id' => $job->id],
                    );
                }

                AuditLogEntry::record(
                    actorType: AuditLogEntry::ACTOR_AGENT,
                    actorIdentifier: $localAgent->public_id,
                    action: 'publish_job.claimed',
                    subjectType: PublishJob::class,
                    subjectId: $job->id,
                    metadata: ['lease_expires_at' => $job->lease_expires_at->toIso8601String()],
                );

                return $job;
            }

            return null;
        });
    }

    /**
     * The Job-heartbeat channel (distinct from the Local Agent's general
     * online heartbeat — see LocalAgentJobController::heartbeat() vs
     * heartbeatJob()): the only thing that renews a running job's lease
     * and, if it holds one, its Project's publish lock. A stray heartbeat
     * for a job that has already reached a terminal state (succeeded,
     * failed, timed_out, abandoned — e.g. the agent's report() and its
     * next heartbeat raced) is a no-op, audited as a late callback rather
     * than silently accepted or a hard error the agent has to handle.
     */
    public function heartbeat(PublishJob $job, LocalAgent $localAgent): PublishJob
    {
        abort_unless($job->local_agent_id === $localAgent->id, 403, 'This publish job does not belong to the authenticated Local Agent.');

        if ($job->isTerminal()) {
            $this->recordLateCallback($job, 'heartbeat');

            return $job;
        }

        abort_unless($job->status === PublishJob::STATUS_RUNNING, 409, 'Only a running publish job accepts a heartbeat.');

        $now = now();
        $leaseSeconds = (int) config('deployment.publish_job_lease_seconds');

        $job->update([
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
        ]);

        if ($job->project->locked_by_publish_job_id === $job->id) {
            $job->project->update([
                'lock_expires_at' => $now->copy()->addSeconds((int) config('deployment.publish_lock_ttl_seconds')),
            ]);
        }

        return $job;
    }

    /**
     * @param array<int, string> $changedFiles
     * @param array<int, string> $blockedFiles files the agent discarded via
     *     IgnoreRules before ever staging them (Phase 5.5) — reported for
     *     operator visibility only, never affects approval/publish logic
     */
    public function reportStatusCheck(PublishJob $job, array $changedFiles, array $blockedFiles = [], ?string $headSha = null, ?string $branch = null, ?bool $workingTreeClean = null): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_STATUS_CHECK, 422, 'Only a status_check job can report changed files.');

        if ($job->isTerminal()) {
            $this->recordLateCallback($job, 'status_check_report');

            return $job;
        }

        $mapping = null;
        if ($headSha !== null || $branch !== null || $workingTreeClean !== null) {
            abort_unless($headSha !== null && preg_match('/^[0-9a-f]{40}$/', $headSha) === 1, 422, 'A status check must include a valid current HEAD SHA.');
            abort_unless(is_string($branch) && $branch !== '', 422, 'A status check must include the current branch.');
            abort_unless(is_bool($workingTreeClean), 422, 'A status check must state whether the working tree is clean.');

            $mapping = LocalProjectMapping::query()->where('local_agent_id', $job->local_agent_id)->where('project_id', $job->project_id)->first();
            abort_unless($mapping !== null && $mapping->effectiveBranch() === $branch, 422, 'The status-check branch does not match the active Local Agent mapping.');
            abort_unless($workingTreeClean === ($changedFiles === [] && $blockedFiles === []), 422, 'Status-check cleanliness evidence does not match the reported files.');
        }

        $job->update([
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'changed_files' => $changedFiles,
            'blocked_files' => $blockedFiles,
            'files_changed_count' => count($changedFiles),
            'reviewed_head_sha' => $headSha,
            'reviewed_branch' => $branch,
            'reviewed_mapping_id' => $mapping?->id,
            'reviewed_working_tree_clean' => $workingTreeClean,
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'publish_job.status_reported',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['files_changed_count' => count($changedFiles), 'blocked_files_count' => count($blockedFiles), 'head_sha' => $headSha, 'branch' => $branch, 'working_tree_clean' => $workingTreeClean],
        );

        // This review is now the current one for its local agent + project —
        // any older awaiting_approval review is superseded so it can never
        // be approved from stale evidence.
        $this->supersedeOlderReviews($job);

        event(new PublishJobAwaitingApproval($job->refresh()));

        return $job;
    }

    public function approve(PublishJob $job, ?string $commitMessage, string $approvedBy): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_STATUS_CHECK, 422, 'Only a status_check job can be approved for publish.');
        abort_unless($job->status === PublishJob::STATUS_AWAITING_APPROVAL, 409, 'This publish job is not awaiting approval.');

        $noSourceChange = $job->files_changed_count === 0;

        // Zero-change reviews keep their existing refreshable guard: the
        // captured evidence must be complete, clean, and still in sync with
        // the remote. It throws 422 and leaves the review awaiting so an
        // admin can refresh it (refreshNoSourceChangeReviewEvidence) rather
        // than permanently finalizing it.
        if ($noSourceChange) {
            $this->assertNoSourceChangeEvidence($job);
        } else {
            abort_unless(is_string($commitMessage) && $commitMessage !== '', 422, 'A commit message is required when publishing changed files.');
        }

        // Every review — changed or zero-change — is only approvable while
        // its captured evidence still matches the current authoritative
        // LocalProjectMapping. A review of a HEAD/branch/cleanliness that
        // has since moved must fail safely and be finalized so it can never
        // be approved later from a stale page or a recovered row.
        if (!$this->reviewEvidenceIsStillValid($job)) {
            $this->finalizeReviewAsRejected(
                $job,
                'The review evidence is no longer current. Start a new change review.',
                'publish_job.review_evidence_stale_rejected',
                [
                    'attempted_by' => $approvedBy,
                    'reviewed_mapping_id' => $job->reviewed_mapping_id,
                    'reviewed_branch' => $job->reviewed_branch,
                    'reviewed_head_sha' => $job->reviewed_head_sha,
                ],
                AuditLogEntry::ACTOR_USER,
                $approvedBy,
            );

            abort(409, 'The review evidence is no longer current — start a new change review before approving.');
        }

        $job->update([
            'status' => PublishJob::STATUS_APPROVED,
            'action' => PublishJob::ACTION_PUBLISH,
            'commit_message' => $commitMessage,
            'no_source_change' => $noSourceChange,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: $noSourceChange ? 'publish_job.no_source_change_approved' : 'publish_job.approved',
            subjectType: PublishJob::class,
            subjectId: $job->id,
        );

        return $job;
    }

    public function refreshNoSourceChangeReviewEvidence(PublishJob $job, string $refreshedBy): PublishJob
    {
        return DB::transaction(function () use ($job, $refreshedBy) {
            $job = PublishJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless($job->action === PublishJob::ACTION_STATUS_CHECK, 422, 'Only a status_check job can refresh review evidence.');
            abort_unless($job->status === PublishJob::STATUS_AWAITING_APPROVAL, 409, 'Only an awaiting-approval job can refresh review evidence.');
            abort_unless($job->files_changed_count === 0 && $job->changed_files === [] && $job->blocked_files === [], 422, 'Only a clean zero-change review can refresh evidence.');

            $mapping = LocalProjectMapping::query()->where('local_agent_id', $job->local_agent_id)->where('project_id', $job->project_id)->lockForUpdate()->first();
            abort_unless($mapping !== null && $mapping->localAgent->status === LocalAgent::STATUS_APPROVED, 422, 'The job no longer has an approved Local Agent mapping.');
            abort_unless($mapping->working_tree_dirty === false, 422, 'The Local Agent working tree is not clean.');
            abort_unless(is_string($mapping->local_head_sha) && preg_match('/^[0-9a-f]{40}$/', $mapping->local_head_sha) === 1, 422, 'The Local Agent has not reported a valid current HEAD.');
            abort_unless($mapping->remote_tracking_sha === $mapping->local_head_sha, 422, 'The Local Agent current HEAD does not match its remote tracking SHA.');
            abort_unless($mapping->effectiveBranch() !== '', 422, 'The Local Agent mapping has no effective branch.');
            abort_if(Artifact::query()->where('project_id', $job->project_id)->where('commit_hash', $mapping->local_head_sha)->exists(), 409, 'An artifact already exists for this reviewed commit.');
            abort_if(PublishJob::query()->where('project_id', $job->project_id)->where('local_agent_id', $job->local_agent_id)->whereKeyNot($job->id)->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING])->exists(), 409, 'Another active publish job conflicts with this evidence refresh.');

            $job->update(['reviewed_head_sha' => $mapping->local_head_sha, 'reviewed_branch' => $mapping->effectiveBranch(), 'reviewed_mapping_id' => $mapping->id, 'reviewed_working_tree_clean' => true]);
            AuditLogEntry::record(actorType: AuditLogEntry::ACTOR_USER, actorIdentifier: $refreshedBy, action: 'publish_job.review_evidence_refreshed', subjectType: PublishJob::class, subjectId: $job->id, metadata: ['head_sha' => $mapping->local_head_sha, 'branch' => $mapping->effectiveBranch(), 'mapping_id' => $mapping->id]);

            return $job->refresh();
        });
    }

    private function assertNoSourceChangeEvidence(PublishJob $job): void
    {
        abort_unless($job->blocked_files === [] && $job->reviewed_working_tree_clean === true, 422, 'A no-source-change publish requires a clean working tree.');
        abort_unless(is_string($job->reviewed_head_sha) && preg_match('/^[0-9a-f]{40}$/', $job->reviewed_head_sha) === 1, 422, 'A no-source-change publish requires a verified current HEAD.');
        abort_unless(is_string($job->reviewed_branch) && $job->reviewed_branch !== '' && $job->reviewed_mapping_id !== null, 422, 'A no-source-change publish requires verified mapping and branch evidence.');

        abort_unless($this->noSourceChangeEvidenceIsStillValid($job), 422, 'The verified Local Agent state is no longer valid.');
    }

    /**
     * A status_check review is only still approvable while every piece of
     * evidence it captured still matches the current authoritative
     * LocalProjectMapping: mapping identity, effective branch, local HEAD,
     * and working-tree cleanliness. Fail-closed: a review with missing
     * evidence (legacy rows / reports that carried no head/branch/
     * cleanliness) or a vanished mapping is never "still valid".
     */
    public function reviewEvidenceIsStillValid(PublishJob $job): bool
    {
        if ($job->reviewed_mapping_id === null
            || !is_string($job->reviewed_branch) || $job->reviewed_branch === ''
            || !is_string($job->reviewed_head_sha)
            || $job->reviewed_working_tree_clean === null) {
            return false;
        }

        $mapping = LocalProjectMapping::query()
            ->whereKey($job->reviewed_mapping_id)
            ->where('local_agent_id', $job->local_agent_id)
            ->where('project_id', $job->project_id)
            ->first();

        if ($mapping === null || $mapping->effectiveBranch() !== $job->reviewed_branch) {
            return false;
        }

        if (preg_match('/^[0-9a-f]{40}$/', (string) $mapping->local_head_sha) !== 1
            || !hash_equals((string) $job->reviewed_head_sha, (string) $mapping->local_head_sha)) {
            return false;
        }

        // The mapping's live cleanliness must match what the review captured
        // (a clean review requires a clean tree; a changed-files review
        // requires the tree to still be dirty — otherwise the developer
        // committed or reverted in the meantime).
        return $mapping->working_tree_dirty === ($job->reviewed_working_tree_clean === false);
    }

    private function noSourceChangeEvidenceIsStillValid(PublishJob $job): bool
    {
        if (!$this->reviewEvidenceIsStillValid($job)) {
            return false;
        }

        $mapping = LocalProjectMapping::query()->whereKey($job->reviewed_mapping_id)->first();

        // A zero-change publish builds an artifact for the current commit
        // without creating or pushing anything, so the local HEAD must still
        // be exactly the reviewed commit AND in sync with its remote
        // tracking branch (nothing locally-ahead that would be silently
        // dropped by the artifact build).
        return $mapping !== null && $mapping->remote_tracking_sha === $mapping->local_head_sha;
    }

    /**
     * The single terminal path for a review that can no longer be approved:
     * superseded by a newer review, expired by the review TTL, or rejected
     * at approval time because its evidence is stale. Reuses the existing
     * `rejected` terminal status (semantically "this review will not proceed
     * to publish"); the audit action distinguishes the reason. Never deletes
     * the history row, and releases a project publish lock only if THIS job
     * happens to hold one (reviews never acquire one).
     */
    private function finalizeReviewAsRejected(PublishJob $job, string $message, string $auditAction, array $metadata, string $actorType, ?string $actorIdentifier): void
    {
        if ($job->isTerminal()) {
            return;
        }

        $job->update([
            'status' => PublishJob::STATUS_REJECTED,
            'error_message' => $message,
            'recovery_metadata' => $metadata,
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        AuditLogEntry::record(
            actorType: $actorType,
            actorIdentifier: $actorIdentifier,
            action: $auditAction,
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: $metadata,
        );
    }

    /**
     * Supersede: whenever a status_check successfully reaches
     * awaiting_approval, any OLDER awaiting_approval review for the same
     * (local_agent_id, project_id) is finalized as rejected — the newer
     * review is the only one an operator should be able to approve. Scoped
     * per mapping/local agent so two developers' machines reviewing the same
     * project do not invalidate each other's reviews.
     */
    private function supersedeOlderReviews(PublishJob $newer): void
    {
        $older = PublishJob::query()
            ->where('local_agent_id', $newer->local_agent_id)
            ->where('project_id', $newer->project_id)
            ->where('action', PublishJob::ACTION_STATUS_CHECK)
            ->where('status', PublishJob::STATUS_AWAITING_APPROVAL)
            ->whereKeyNot($newer->id)
            ->get();

        foreach ($older as $job) {
            $this->finalizeReviewAsRejected(
                $job,
                'Superseded by a newer review for the same local agent and project.',
                'publish_job.review_superseded',
                ['superseded_by_publish_job_id' => $newer->id, 'superseded_by_reviewed_head_sha' => $newer->reviewed_head_sha],
                AuditLogEntry::ACTOR_SYSTEM,
                null,
            );
        }
    }

    /**
     * deployments:recover-stale's review-expiry write path: an
     * awaiting_approval review older than publish_review_ttl_minutes is
     * finalized as rejected (publish_job.review_expired). Reviews never hold
     * a project publish lock, so no lock state is involved.
     */
    public function expireReview(PublishJob $job): PublishJob
    {
        abort_if($job->isTerminal(), 409, 'Cannot expire a review that has already reached a terminal state.');

        $this->finalizeReviewAsRejected(
            $job,
            'The review expired without approval — start a new change review.',
            'publish_job.review_expired',
            ['review_ttl_minutes' => (int) config('deployment.publish_review_ttl_minutes')],
            AuditLogEntry::ACTOR_SYSTEM,
            null,
        );

        return $job;
    }

    public function approveRollback(PublishJob $job, string $approvedBy): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_ROLLBACK, 422, 'approveRollback() only applies to a rollback job.');
        abort_unless($job->status === PublishJob::STATUS_QUEUED, 409, 'This rollback job is not queued.');

        $job->update([
            'status' => PublishJob::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'publish_job.rollback_approved',
            subjectType: PublishJob::class,
            subjectId: $job->id,
        );

        return $job;
    }

    public function reportResult(PublishJob $job, ?string $commitHash, string $output): PublishJob
    {
        if ($job->isTerminal()) {
            $this->recordLateCallback($job, 'result');

            return $job;
        }

        if ($job->no_source_change) {
            abort_unless($commitHash !== null && hash_equals((string) $job->reviewed_head_sha, $commitHash), 422, 'A no-source-change publish must report its verified reviewed HEAD exactly.');
        }

        if ($job->action === PublishJob::ACTION_BUILD_ARTIFACT) {
            abort_unless($commitHash !== null && hash_equals((string) $job->reviewed_head_sha, $commitHash), 422, 'An artifact build job must report the requested commit exactly.');

            if ($job->release_id !== null) {
                abort_unless($job->evidence_verified_at !== null, 422, 'A release-linked artifact build cannot succeed without validated exact-commit evidence.');
                $verifiedArtifact = Artifact::query()
                    ->where('publish_job_id', $job->id)
                    ->where('release_id', $job->release_id)
                    ->where('project_id', $job->project_id)
                    ->where('commit_hash', $commitHash)
                    ->where('branch', $job->reviewed_branch)
                    ->where('status', Artifact::STATUS_AVAILABLE)
                    ->where('semantic_status', 'verified')
                    ->first();
                abort_if($verifiedArtifact === null, 409, 'The release build cannot succeed until its exact job-bound Artifact is semantically verified.');
            }

            if ($job->deployment_request !== null) {
                $artifact = Artifact::query()
                    ->where('project_id', $job->project_id)
                    ->where('commit_hash', $commitHash)
                    ->where('branch', $job->reviewed_branch)
                    ->where('status', Artifact::STATUS_AVAILABLE)
                    ->first();

                abort_if($artifact === null, 409, 'The artifact build cannot complete its deployment request until the exact commit and branch artifact is available.');

                $this->deployments->resumeRequiredArtifactDeployment($job);
            }
        }

        $job->update([
            'status' => PublishJob::STATUS_SUCCEEDED,
            'active_build_key' => null,
            'result_commit_hash' => $commitHash,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        // Phase 5.2: if this PublishJob originated from a LocalChangeDetectionJob
        // (see LocalChangeDetectionService::approve()), close the loop on
        // that row too — a direct model update rather than injecting
        // LocalChangeDetectionService here, since this is the only thing
        // PublishJobService needs from it.
        LocalChangeDetectionJob::where('publish_job_id', $job->id)->update(['status' => LocalChangeDetectionJob::STATUS_PUBLISHED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: $job->action === PublishJob::ACTION_ROLLBACK ? 'publish_job.rollback_succeeded' : ($job->action === PublishJob::ACTION_BUILD_ARTIFACT ? 'publish_job.artifact_build_succeeded' : 'publish_job.succeeded'),
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['commit_hash' => $commitHash],
        );

        // No-source-change publish bridge: this publish succeeded WITHOUT a
        // git push (the commit already existed remotely), so no GitHub push
        // webhook will ever fire and no Deployment would be created by the
        // normal webhook-driven path. Create the one intended Deployment
        // attempt here, idempotently, and correlate it to this publish.
        $this->bridgeNoSourceChangeDeployment($job);

        event(new PublishJobSucceeded($job->refresh()));

        if ($job->action === PublishJob::ACTION_BUILD_ARTIFACT && $job->release_id !== null) {
            $this->releases->syncArtifactState($job->release);
        }

        return $job;
    }

    /**
     * The explicit, idempotent publish-to-deployment bridge for the one
     * case the webhook path cannot cover: a `publish` that succeeded with
     * `no_source_change` (verified existing commit, no git commit/push, no
     * GitHub push webhook). Reuses DeploymentDispatcher::createFromPublish()
     * — the same legacy/multi-target creation semantics as a push — with
     * trigger `publish`, auto_deploy/approval semantics identical to the
     * webhook path, and the matching available Artifact attached.
     *
     * The matching AVAILABLE artifact is REQUIRED: for a no-source-change
     * publish the uploaded artifact IS the deployable payload (no push
     * happened, so the git path the server agent would otherwise fall back
     * to is exactly the GitHub dependency this system exists to eliminate,
     * and it could deploy content not represented by the reviewed artifact
     * build). If no artifact matches, the bridge skips deterministically and
     * records publish_job.deployment_bridge_failed (reason
     * artifact_unavailable) — no polling, no timing race, no git fallback.
     *
     * Exactly one Deployment is created per PublishJob: the row is locked
     * and deployment_id re-checked inside a transaction, and a repeated
     * success report for an already-terminal job never reaches this bridge
     * at all. A webhook that fires for the same commit remains a separate,
     * legitimate event (a real push) and is never touched by this bridge.
     */
    private function bridgeNoSourceChangeDeployment(PublishJob $job): void
    {
        if ($job->action !== PublishJob::ACTION_PUBLISH || !$job->no_source_change || $job->deployment_id !== null) {
            return;
        }

        $result = DB::transaction(function () use ($job) {
            $locked = PublishJob::query()->lockForUpdate()->find($job->id);

            if ($locked === null
                || $locked->deployment_id !== null
                || $locked->action !== PublishJob::ACTION_PUBLISH
                || !$locked->no_source_change) {
                return null;
            }

            $artifact = Artifact::query()
                ->where('project_id', $locked->project_id)
                ->where('commit_hash', $locked->result_commit_hash)
                ->where('status', Artifact::STATUS_AVAILABLE)
                ->first();

            if ($artifact === null) {
                $this->recordDeploymentBridgeFailure(
                    $locked,
                    'No available artifact matches the reviewed commit — the no-source-change publish bridge requires the uploaded artifact and will not fall back to the legacy git pipeline.',
                    'artifact_unavailable',
                );

                return null;
            }

            try {
                $deployment = $this->deployments->createFromPublish(
                    $locked->project,
                    (string) $locked->result_commit_hash,
                    (string) $locked->reviewed_branch,
                    (string) ($locked->localAgent?->public_id ?? 'local-agent'),
                    $locked->id,
                );
            } catch (\Throwable $e) {
                // The publish itself succeeded — only the deployment bridge
                // failed (e.g. a multi-target project with no active
                // targets). Record why, keep the publish terminal, and let
                // the UI show that no deployment attempt exists.
                $reason = str_contains($e->getMessage(), 'explicit deployment confirmation')
                    ? 'explicit_confirm_required'
                    : null;
                $this->recordDeploymentBridgeFailure($locked, $e->getMessage(), $reason);

                return null;
            }

            $locked->update(['deployment_id' => $deployment->id]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $locked->localAgent?->public_id,
                action: 'publish_job.deployment_bridged',
                subjectType: PublishJob::class,
                subjectId: $locked->id,
                metadata: ['deployment_id' => $deployment->id, 'commit_hash' => $locked->result_commit_hash, 'branch' => $locked->reviewed_branch],
            );

            return $deployment;
        });

        if ($result === null) {
            return;
        }
    }

    private function recordDeploymentBridgeFailure(PublishJob $job, string $error, ?string $reason = null): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'publish_job.deployment_bridge_failed',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: array_filter([
                'project_id' => $job->project_id,
                'commit_hash' => $job->result_commit_hash,
                'branch' => $job->reviewed_branch,
                'reason' => $reason,
                'error' => $error,
            ]),
        );
    }

    public function reportFailure(PublishJob $job, string $message, ?string $output = null, ?string $failureCode = null): PublishJob
    {
        if ($job->isTerminal()) {
            $this->recordLateCallback($job, 'failure');

            return $job;
        }

        // A self-reported timed_out (the agent's own command exceeded its
        // configured timeout — see failureCode) is a distinct status from
        // a plain failed, even though both arrive via this same method:
        // both are "the agent observed and told us", unlike abandoned
        // (Hub-inferred from silence, see markAbandoned()).
        $status = $failureCode === PublishJob::FAILURE_CODE_PROCESS_TIMEOUT
            ? PublishJob::STATUS_TIMED_OUT
            : PublishJob::STATUS_FAILED;

        $job->update([
            'status' => $status,
            'active_build_key' => null,
            'error_message' => $this->redactor->redact($message),
            'output' => $output !== null ? $this->redactor->redact($output) : $job->output,
            'failure_code' => $failureCode,
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: $job->action === PublishJob::ACTION_ROLLBACK ? 'publish_job.rollback_failed' : 'publish_job.failed',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['message' => $job->error_message, 'failure_code' => $failureCode],
        );

        event(new PublishJobFailed($job->refresh()));

        $this->syncFailedBuildRelease($job, $status, $failureCode, $message);

        return $job;
    }

    /**
     * deployments:recover-stale's only write path into PublishJob — never
     * called from a controller or the agent-facing API. Distinguishes a
     * Hub-inferred "this job's owner went silent" from every
     * agent-reported outcome above.
     *
     * @param array<string, mixed> $recoveryMetadata
     */
    public function markAbandoned(PublishJob $job, string $failureCode, string $message, array $recoveryMetadata = []): PublishJob
    {
        abort_if($job->isTerminal(), 409, 'Cannot abandon a publish job that has already reached a terminal state.');

        $job->update([
            'status' => PublishJob::STATUS_ABANDONED,
            'active_build_key' => null,
            'error_message' => $message,
            'failure_code' => $failureCode,
            'recovery_metadata' => $recoveryMetadata,
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'publish_job.abandoned',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['failure_code' => $failureCode] + $recoveryMetadata,
        );

        event(new PublishJobAbandoned($job->refresh()));

        $this->syncFailedBuildRelease($job, PublishJob::STATUS_ABANDONED, $failureCode, $message);

        return $job;
    }

    /**
     * Root-cause fix (historical-release-recovery regression):
     * releases:reconcile-obsolete-artifact-builds' only write path into
     * PublishJob — invalidates a build_artifact job that automatic
     * recovery queued for a Release since superseded by a newer push to
     * the same project+branch, before the Agent ever claimed it.
     * Deliberately does NOT call syncFailedBuildRelease()/markFailed(): a
     * superseded Release was never actually attempted and failed, so
     * recording it as "failed" would misstate history. The caller
     * separately re-runs ReleaseOrchestrator::syncArtifactState() on the
     * Release once its job is no longer active, which reconciles status
     * back to whatever is actually true (typically awaiting_artifact) —
     * the exact same reconciliation any other job completion already
     * relies on, not a new state invented for this path.
     *
     * Deliberately does not fire PublishJobAbandoned: that event notifies
     * the project's publish audience ("your publish was abandoned"), which
     * would misrepresent this as something going wrong with a legitimate
     * publish rather than silent cleanup of a job automatic recovery
     * should never have created. The AuditLogEntry alone is the record.
     */
    public function invalidateObsoleteArtifactBuild(PublishJob $job, string $reason): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_BUILD_ARTIFACT, 422, 'Only a build_artifact job can be invalidated as obsolete.');
        abort_unless(
            in_array($job->status, [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED], true),
            409,
            'Only a not-yet-started Artifact build can be invalidated as obsolete; a running or already-terminal job must be left alone.',
        );

        $job->update([
            'status' => PublishJob::STATUS_ABANDONED,
            'active_build_key' => null,
            'error_message' => $reason,
            'failure_code' => 'obsolete_release_superseded',
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'publish_job.invalidated_obsolete_artifact_build',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['release_id' => $job->release_id, 'reason' => $reason],
        );

        return $job->refresh();
    }

    /**
     * deployments:recover-stale's other write path: a job whose total
     * elapsed runtime exceeds publish_job_max_runtime_seconds — a hard
     * Hub-enforced ceiling that applies even if the agent's own
     * heartbeats kept arriving on schedule (defense in depth for a
     * runaway command whose own per-command timeout didn't catch it, or
     * one that's genuinely alive but has run far longer than any publish
     * reasonably should). Distinct from markAbandoned(): this is "ran too
     * long", not "went silent".
     *
     * @param array<string, mixed> $recoveryMetadata
     */
    public function markTimedOut(PublishJob $job, string $failureCode, string $message, array $recoveryMetadata = []): PublishJob
    {
        abort_if($job->isTerminal(), 409, 'Cannot time out a publish job that has already reached a terminal state.');

        $job->update([
            'status' => PublishJob::STATUS_TIMED_OUT,
            'active_build_key' => null,
            'error_message' => $message,
            'failure_code' => $failureCode,
            'recovery_metadata' => $recoveryMetadata,
            'finished_at' => now(),
        ]);

        $this->releaseProjectLock($job);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'publish_job.timed_out',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['failure_code' => $failureCode] + $recoveryMetadata,
        );

        // Reuses the existing PublishJobFailed notification wiring rather
        // than adding a third near-identical event/listener pair — from
        // the operator's point of view a forcibly-ended runaway job
        // deserves the same alert as a plain failure.
        event(new PublishJobFailed($job->refresh()));

        $this->syncFailedBuildRelease($job, PublishJob::STATUS_TIMED_OUT, $failureCode, $message);

        return $job;
    }

    private function syncFailedBuildRelease(PublishJob $job, string $status, ?string $failureCode, string $message): void
    {
        if ($job->action !== PublishJob::ACTION_BUILD_ARTIFACT || $job->release_id === null) {
            return;
        }
        $this->releases->markFailed(
            $job->release,
            'artifact_build_' . ($failureCode ?: $status),
            $this->redactor->redact($message),
        );
    }

    /**
     * A report/heartbeat arriving for a job that has already reached a
     * terminal state — most often the agent's own report() and a
     * deployments:recover-stale sweep racing (the agent finally comes
     * back right after being marked abandoned). Never re-opens or
     * mutates the job; only makes the occurrence visible.
     */
    private function recordLateCallback(PublishJob $job, string $callbackType): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'publish_job.late_callback_rejected',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['callback_type' => $callbackType, 'status' => $job->status],
        );
    }

    private function releaseProjectLock(PublishJob $job): void
    {
        $released = Project::where('id', $job->project_id)
            ->where('locked_by_publish_job_id', $job->id)
            ->update([
                'locked_by_publish_job_id' => null,
                'locked_by_local_agent_id' => null,
                'locked_at' => null,
                'lock_expires_at' => null,
            ]);

        if ($released > 0) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'project.publish_lock_released',
                subjectType: Project::class,
                subjectId: $job->project_id,
                metadata: ['publish_job_id' => $job->id],
            );
        }
    }
}
