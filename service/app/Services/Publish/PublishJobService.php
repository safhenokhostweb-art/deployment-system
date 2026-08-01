<?php

namespace App\Services\Publish;

use App\Events\PublishJobAbandoned;
use App\Events\PublishJobAwaitingApproval;
use App\Events\PublishJobFailed;
use App\Events\PublishJobSucceeded;
use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Security\SecretRedactor;
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
    public function queueApprovedPublish(LocalAgent $localAgent, Project $project, string $commitMessage, string $approvedBy, string $triggeredBy = 'approval'): PublishJob
    {
        $job = PublishJob::create([
            'local_agent_id' => $localAgent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
            'commit_message' => $commitMessage,
            'requested_by' => $approvedBy,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
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
    public function reportStatusCheck(PublishJob $job, array $changedFiles, array $blockedFiles = []): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_STATUS_CHECK, 422, 'Only a status_check job can report changed files.');

        if ($job->isTerminal()) {
            $this->recordLateCallback($job, 'status_check_report');

            return $job;
        }

        $job->update([
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'changed_files' => $changedFiles,
            'blocked_files' => $blockedFiles,
            'files_changed_count' => count($changedFiles),
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'publish_job.status_reported',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['files_changed_count' => count($changedFiles), 'blocked_files_count' => count($blockedFiles)],
        );

        event(new PublishJobAwaitingApproval($job->refresh()));

        return $job;
    }

    public function approve(PublishJob $job, string $commitMessage, string $approvedBy): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_STATUS_CHECK, 422, 'Only a status_check job can be approved for publish.');
        abort_unless($job->status === PublishJob::STATUS_AWAITING_APPROVAL, 409, 'This publish job is not awaiting approval.');
        abort_if($job->files_changed_count === 0, 422, 'There are no changed files to publish.');

        $job->update([
            'status' => PublishJob::STATUS_APPROVED,
            'action' => PublishJob::ACTION_PUBLISH,
            'commit_message' => $commitMessage,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'publish_job.approved',
            subjectType: PublishJob::class,
            subjectId: $job->id,
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

        $job->update([
            'status' => PublishJob::STATUS_SUCCEEDED,
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
            action: $job->action === PublishJob::ACTION_ROLLBACK ? 'publish_job.rollback_succeeded' : 'publish_job.succeeded',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['commit_hash' => $commitHash],
        );

        event(new PublishJobSucceeded($job->refresh()));

        return $job;
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

        return $job;
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

        return $job;
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
