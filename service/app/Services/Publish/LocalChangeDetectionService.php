<?php

namespace App\Services\Publish;

use App\Events\LocalChangeDetectionAwaitingApproval;
use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Support\Publish\CommitMessageSuggester;

/**
 * Phase 5.2: the Local Agent's file-watch reports become one OPEN
 * LocalChangeDetectionJob row per (local_agent_id, project_id) — the
 * watcher keeps updating the same row every cycle instead of flooding
 * one row per poll (see report()). What happens on report() depends on
 * the LocalProjectMapping's deployment_mode: `manual`/`auto` stay quiet
 * (`detected`, no interruption — Auto mode's actual publish is decided
 * later by the AutoPublishStableChanges scheduled command once the
 * change has been stable); `approval` notifies once, on the transition
 * into `waiting_approval`, never on every repeated report while the
 * developer keeps editing.
 */
class LocalChangeDetectionService
{
    public function __construct(
        private readonly PublishJobService $publishJobs,
        private readonly CommitMessageSuggester $suggester,
    ) {
    }

    /**
     * @param array<int, string> $changedFiles
     */
    public function report(LocalAgent $localAgent, Project $project, array $changedFiles): LocalChangeDetectionJob
    {
        $mapping = LocalProjectMapping::where('local_agent_id', $localAgent->id)
            ->where('project_id', $project->id)
            ->first();

        abort_if($mapping === null, 422, 'This local agent has no project mapping for this project.');

        $existing = LocalChangeDetectionJob::where('local_agent_id', $localAgent->id)
            ->where('project_id', $project->id)
            ->whereIn('status', LocalChangeDetectionJob::OPEN_STATUSES)
            ->first();

        $suggestedMessage = $this->suggester->suggest($changedFiles);

        if ($existing === null) {
            $status = $mapping->deployment_mode === LocalProjectMapping::MODE_APPROVAL
                ? LocalChangeDetectionJob::STATUS_WAITING_APPROVAL
                : LocalChangeDetectionJob::STATUS_DETECTED;

            $job = LocalChangeDetectionJob::create([
                'local_agent_id' => $localAgent->id,
                'project_id' => $project->id,
                'changed_files' => $changedFiles,
                'files_changed_count' => count($changedFiles),
                'detected_at' => now(),
                'status' => $status,
                'suggested_commit_message' => $suggestedMessage,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $localAgent->public_id,
                action: 'local_change_detection.detected',
                subjectType: LocalChangeDetectionJob::class,
                subjectId: $job->id,
                metadata: ['project_id' => $project->id, 'files_changed_count' => count($changedFiles)],
            );

            if ($status === LocalChangeDetectionJob::STATUS_WAITING_APPROVAL) {
                event(new LocalChangeDetectionAwaitingApproval($job));
            }

            return $job;
        }

        // Already open — update it in place. If the mapping's mode was
        // switched to 'approval' after this row was created as
        // 'detected', pick that up now and notify exactly once (the
        // transition), rather than leaving it silently stuck.
        $wasWaitingApproval = $existing->status === LocalChangeDetectionJob::STATUS_WAITING_APPROVAL;

        $newStatus = $existing->status;
        if ($existing->status === LocalChangeDetectionJob::STATUS_DETECTED && $mapping->deployment_mode === LocalProjectMapping::MODE_APPROVAL) {
            $newStatus = LocalChangeDetectionJob::STATUS_WAITING_APPROVAL;
        }

        $existing->update([
            'changed_files' => $changedFiles,
            'files_changed_count' => count($changedFiles),
            'detected_at' => now(),
            'status' => $newStatus,
            'suggested_commit_message' => $suggestedMessage,
        ]);

        if ($newStatus === LocalChangeDetectionJob::STATUS_WAITING_APPROVAL && !$wasWaitingApproval) {
            event(new LocalChangeDetectionAwaitingApproval($existing));
        }

        return $existing;
    }

    public function approve(LocalChangeDetectionJob $job, ?string $commitMessage, string $approvedBy, string $triggeredBy = 'approval'): PublishJob
    {
        abort_unless(in_array($job->status, LocalChangeDetectionJob::OPEN_STATUSES, true), 409, 'This change detection is not open.');
        abort_if($job->files_changed_count === 0, 422, 'There are no changed files to publish.');

        $message = $commitMessage !== null && trim($commitMessage) !== ''
            ? $commitMessage
            : ($job->suggested_commit_message ?? $this->suggester->suggest($job->changed_files ?? []));

        $publishJob = $this->publishJobs->queueApprovedPublish($job->localAgent, $job->project, $message, $approvedBy, $triggeredBy);

        $job->update(['status' => LocalChangeDetectionJob::STATUS_APPROVED, 'publish_job_id' => $publishJob->id]);

        AuditLogEntry::record(
            actorType: $triggeredBy === 'auto' ? AuditLogEntry::ACTOR_SYSTEM : AuditLogEntry::ACTOR_USER,
            actorIdentifier: $approvedBy,
            action: 'local_change_detection.approved',
            subjectType: LocalChangeDetectionJob::class,
            subjectId: $job->id,
            metadata: ['publish_job_id' => $publishJob->id, 'triggered_by' => $triggeredBy],
        );

        return $publishJob;
    }

    public function ignore(LocalChangeDetectionJob $job, string $actorEmail): LocalChangeDetectionJob
    {
        abort_unless(in_array($job->status, LocalChangeDetectionJob::OPEN_STATUSES, true), 409, 'This change detection is not open.');

        $job->update(['status' => LocalChangeDetectionJob::STATUS_IGNORED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $actorEmail,
            action: 'local_change_detection.ignored',
            subjectType: LocalChangeDetectionJob::class,
            subjectId: $job->id,
        );

        return $job;
    }
}
