<?php

namespace App\Services\Publish;

use App\Events\PublishJobAwaitingApproval;
use App\Events\PublishJobFailed;
use App\Events\PublishJobSucceeded;
use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Security\SecretRedactor;

/**
 * Phase 5: queue/claim/report lifecycle for a Local Agent Publish or
 * Rollback request — the same shape as OperationJobService, adapted for
 * PublishJob's extra approval step (status_check -> awaiting_approval ->
 * approved -> running -> succeeded/failed). A rollback skips the
 * approval-with-commit-message step (queue() immediately followed by
 * approveRollback()) since there is nothing to preview before reverting.
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

    public function claimNext(LocalAgent $localAgent): ?PublishJob
    {
        $job = PublishJob::query()
            ->where('local_agent_id', $localAgent->id)
            ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED])
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => PublishJob::STATUS_RUNNING, 'started_at' => now()]);

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
        $job->update([
            'status' => PublishJob::STATUS_SUCCEEDED,
            'result_commit_hash' => $commitHash,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
            'finished_at' => now(),
        ]);

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

    public function reportFailure(PublishJob $job, string $message, ?string $output = null): PublishJob
    {
        $job->update([
            'status' => PublishJob::STATUS_FAILED,
            'error_message' => $this->redactor->redact($message),
            'output' => $output !== null ? $this->redactor->redact($output) : $job->output,
            'finished_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: $job->action === PublishJob::ACTION_ROLLBACK ? 'publish_job.rollback_failed' : 'publish_job.failed',
            subjectType: PublishJob::class,
            subjectId: $job->id,
            metadata: ['message' => $job->error_message],
        );

        event(new PublishJobFailed($job->refresh()));

        return $job;
    }
}
