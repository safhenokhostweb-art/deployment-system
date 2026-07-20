<?php

namespace App\Services\Deployment;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\AgentJobLogEntry;
use App\Models\AuditLogEntry;
use App\Models\DeploymentTask;
use App\Services\Security\JobPayloadSigner;
use App\Services\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;

/**
 * New-domain analog of AgentJobService — claims DeploymentTask rows
 * (instead of legacy Deployment rows) and records AgentJob attempts.
 * Deliberately a separate class so the legacy claim path in
 * AgentJobService is never touched by this integration (see
 * docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md §7).
 *
 * Only reachable for Agent-authenticated callers (never the legacy
 * Server-only auth path) — a DeploymentTarget always needs a real Agent
 * to record as the claimer on its AgentJob rows, and the new domain's
 * whole premise (capabilities, lifecycle) depends on Agent existing.
 */
class AgentTaskService
{
    public function __construct(
        private readonly JobPayloadSigner $jobPayloadSigner,
        private readonly SecretRedactor $redactor,
        private readonly DeploymentStatusAggregator $aggregator,
    ) {
    }

    /**
     * @return array{job: array, signature: string}|null
     */
    public function claimNextTask(Agent $agent): ?array
    {
        return DB::transaction(function () use ($agent) {
            $candidates = DeploymentTask::query()
                ->where('status', DeploymentTask::STATUS_QUEUED)
                ->where(function ($query) {
                    // Task 3: a task awaiting its retry backoff window
                    // isn't claimable yet, even though it's still "queued".
                    $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
                })
                ->whereHas('deploymentTarget', fn ($query) => $query->where('server_id', $agent->server_id))
                ->oldest('created_at')
                ->lockForUpdate()
                ->with(['deploymentTarget.environment', 'deployment.project'])
                ->get();

            foreach ($candidates as $task) {
                $environment = $task->deploymentTarget->environment;

                // Task 7: an Environment already locked by a *different*
                // in-flight Deployment is not claimable — try the next
                // candidate rather than blocking the whole poll.
                if ($environment->isLockedByAnotherDeployment($task->deployment_id)) {
                    continue;
                }

                if ($environment->locked_by_deployment_id === null) {
                    $environment->update([
                        'locked_by_deployment_id' => $task->deployment_id,
                        'locked_at' => now(),
                    ]);
                }

                return $this->claim($task, $agent);
            }

            return null;
        });
    }

    private function claim(DeploymentTask $task, Agent $agent): array
    {
        $task->update(['status' => DeploymentTask::STATUS_RUNNING, 'next_retry_at' => null]);

        $attemptNumber = $task->agentJobs()->count() + 1;

        $agentJob = AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => $attemptNumber,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $agent->public_id,
            action: 'deployment_task.claimed_by_agent',
            subjectType: DeploymentTask::class,
            subjectId: $task->id,
            metadata: ['attempt' => $attemptNumber],
        );

        // Same wire shape as the legacy job payload — including the
        // "project_key" field name — so the existing, unmodified agent
        // code (which only ever knows how to read that field to resolve
        // its local working directory) needs no changes to run a
        // new-domain job. The DeploymentTarget's name fills that role.
        $job = [
            'id' => $agentJob->public_id,
            'project_key' => $task->deploymentTarget->name,
            'action' => $task->deployment->action,
            'commit_hash' => $task->deployment->commit_hash,
            'previous_commit_hash' => $task->deployment->previous_commit_hash,
            'branch' => $task->deployment->branch,
            'pipeline' => $task->deployment->project->pipeline,
            'issued_at' => now()->timestamp,
        ];

        return [
            'job' => $job,
            'signature' => $this->jobPayloadSigner->sign($job, $agent->signing_secret),
        ];
    }

    public function updateStatus(AgentJob $agentJob, string $status, ?string $message = null): AgentJob
    {
        abort_unless(
            in_array($status, [AgentJob::STATUS_RUNNING, AgentJob::STATUS_SUCCEEDED, AgentJob::STATUS_FAILED], true),
            422,
            'Invalid status transition from agent.'
        );

        $attributes = ['status' => $status, 'last_activity_at' => now()];

        if (in_array($status, [AgentJob::STATUS_SUCCEEDED, AgentJob::STATUS_FAILED], true)) {
            $attributes['finished_at'] = now();
        }

        $agentJob->update($attributes);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $agentJob->agent->public_id,
            action: 'agent_job.status_reported',
            subjectType: AgentJob::class,
            subjectId: $agentJob->id,
            metadata: ['status' => $status, 'message' => $message],
        );

        if ($status === AgentJob::STATUS_SUCCEEDED) {
            $task = $agentJob->deploymentTask;
            $task->update(['status' => DeploymentTask::STATUS_SUCCEEDED]);
            $this->aggregator->recalculate($task->deployment);
        } elseif ($status === AgentJob::STATUS_FAILED) {
            $this->handleFailedAttempt($agentJob);
        }

        return $agentJob->refresh();
    }

    /**
     * Task 3: retry policy. Off by default (task_max_attempts = 1) — see
     * config/deployment.php for why blind auto-retry isn't the default.
     * When enabled, the task goes back to `queued` with a backoff delay
     * instead of straight to `failed`, so the next eligible poll (after
     * the delay) creates a brand-new AgentJob attempt — never a retried
     * copy of the old one, preserving full independent history per try.
     */
    private function handleFailedAttempt(AgentJob $agentJob): void
    {
        $task = $agentJob->deploymentTask;
        $maxAttempts = (int) config('deployment.task_max_attempts');

        if ($agentJob->attempt < $maxAttempts) {
            $backoff = config('deployment.task_retry_backoff_seconds');
            $delaySeconds = $backoff[min($agentJob->attempt - 1, count($backoff) - 1)];

            $task->update([
                'status' => DeploymentTask::STATUS_QUEUED,
                'next_retry_at' => now()->addSeconds($delaySeconds),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'deployment_task.retry_scheduled',
                subjectType: DeploymentTask::class,
                subjectId: $task->id,
                metadata: ['failed_attempt' => $agentJob->attempt, 'next_retry_in_seconds' => $delaySeconds],
            );

            return;
        }

        $task->update(['status' => DeploymentTask::STATUS_FAILED]);
        $this->aggregator->recalculate($task->deployment);
    }

    public function recordLog(AgentJob $agentJob, array $payload): AgentJobLogEntry
    {
        $entry = AgentJobLogEntry::create([
            'agent_job_id' => $agentJob->id,
            'sequence' => $payload['sequence'],
            'step_name' => $payload['step_name'],
            'command' => $this->redactor->redact($payload['command']),
            'output' => $this->redactor->redact($payload['output'] ?? ''),
            'exit_code' => $payload['exit_code'] ?? null,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'timed_out' => $payload['timed_out'] ?? false,
        ]);

        $agentJob->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $entry;
    }
}
