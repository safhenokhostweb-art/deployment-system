<?php

namespace App\Services\Deployment;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\AgentJobLogEntry;
use App\Models\AuditLogEntry;
use App\Models\DeploymentTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Security\JobPayloadSigner;
use App\Services\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;
use App\Support\Deployment\HttpHealthCheckPayload;

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
        private readonly ArtifactService $artifacts,
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

                // Root-cause fix (artifact-to-webhook correlation): the Local
                // Agent can finish uploading the exact-commit artifact a few
                // seconds AFTER the webhook created this deployment. Re-attach
                // here, at the last pre-execution boundary, so the task
                // carries the artifact instead of silently falling back to the
                // live-directory git pipeline.
                $this->artifacts->attachToDeploymentIfAvailable($task->deployment);
                $task->load('deployment.artifact');

                // Root-cause fix (Laravel release completeness): never
                // dispatch an artifact-based task to a Server Agent too old to
                // enforce the fail-closed release gate — fail the task before
                // execution instead of trusting a gate the agent build doesn't
                // run.
                if ($task->deployment->artifact !== null && !$this->agentEnforcesReleaseGate($agent)) {
                    $this->rejectIncompatibleAgentTask($task, $agent);

                    continue;
                }

                if ($task->deployment->hasHttpHealthCheck() && !$this->agentEnforcesHttpHealth($agent)) {
                    $this->rejectIncompatibleHealthAgentTask($task, $agent);

                    continue;
                }

                // Root-cause fix (Laravel release completeness): a project
                // with requires_complete_artifact=true must NEVER fall back to
                // the live-directory git pipeline. No exact-commit AVAILABLE
                // artifact after the re-attach attempt → fail closed.
                if (
                    $task->deployment->project->requires_complete_artifact
                    && $task->deployment->action === Deployment::ACTION_DEPLOY
                    && $task->deployment->artifact === null
                ) {
                    $this->rejectMissingArtifactTask($task);

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

    /**
     * A Server-authenticated legacy runtime can execute legacy Deployment
     * rows, but cannot safely claim a DeploymentTask because there is no
     * Agent identity to bind to the resulting AgentJob.  Fail the affected
     * task explicitly instead of leaving a deployment silently queued. The
     * normal Agent-authenticated path never reaches this method.
     */
    public function rejectLegacyTaskClaim(Server $server): ?DeploymentTask
    {
        return DB::transaction(function () use ($server) {
            $task = DeploymentTask::query()
                ->where('status', DeploymentTask::STATUS_QUEUED)
                ->whereHas('deploymentTarget', fn ($query) => $query->where('server_id', $server->id))
                ->oldest('created_at')
                ->lockForUpdate()
                ->with('deployment')
                ->first();

            if ($task === null) {
                return null;
            }

            $task->update(['status' => DeploymentTask::STATUS_FAILED]);
            $task->deployment->update([
                'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
                'failure_message' => 'This deployment requires an Agent-authenticated runtime. Reissue the existing Agent credentials and update its config before retrying.',
            ]);
            $this->aggregator->recalculate($task->deployment);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'deployment_task.rejected_legacy_agent_protocol',
                subjectType: DeploymentTask::class,
                subjectId: $task->id,
                metadata: ['server_id' => $server->id],
            );

            return $task->fresh();
        });
    }

    /**
     * Whether the claiming Server Agent's installed build is known to
     * enforce the Laravel release-completeness gate (agent/lib/
     * ReleaseValidator.php). Mirrors AgentJobService's gate; an unknown or
     * missing version is treated as incompatible, never assumed current.
     */
    private function agentEnforcesReleaseGate(Agent $agent): bool
    {
        $agentVersion = $agent->agent_version;

        if (!is_string($agentVersion) || $agentVersion === '') {
            return false;
        }

        return version_compare($agentVersion, (string) config('deployment.min_server_agent_version_for_artifact'), '>=');
    }

    private function agentEnforcesHttpHealth(Agent $agent): bool
    {
        return is_string($agent->agent_version)
            && $agent->agent_version !== ''
            && version_compare($agent->agent_version, (string) config('deployment.min_server_agent_version_for_http_health'), '>=');
    }

    private function rejectIncompatibleHealthAgentTask(DeploymentTask $task, Agent $agent): void
    {
        $requiredVersion = (string) config('deployment.min_server_agent_version_for_http_health');
        $message = "Server Agent {$agent->public_id} cannot execute customer-facing HTTP health verification; version {$requiredVersion} or newer is required.";
        $task->update([
            'status' => DeploymentTask::STATUS_FAILED,
            'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
            'failure_message' => $message,
        ]);
        $task->deployment->update([
            'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
            'failure_message' => $message,
        ]);
        $this->aggregator->recalculate($task->deployment);
    }

    /** Fails the task + deployment with an explicit incompatible-agent reason before any execution. */
    private function rejectIncompatibleAgentTask(DeploymentTask $task, Agent $agent): void
    {
        $reportedVersion = $agent->agent_version;
        $requiredVersion = (string) config('deployment.min_server_agent_version_for_artifact');

        $task->update(['status' => DeploymentTask::STATUS_FAILED]);
        $task->deployment->update([
            'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
            'failure_message' => "The server agent (version " . ($reportedVersion ?? 'unknown') . ") is too old to enforce the Laravel release-completeness gate. Upgrade the agent to at least {$requiredVersion} before artifact-based deployments.",
        ]);
        $this->aggregator->recalculate($task->deployment);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'deployment_task.rejected_incompatible_agent',
            subjectType: DeploymentTask::class,
            subjectId: $task->id,
            metadata: ['agent_version' => $reportedVersion, 'required_version' => $requiredVersion],
        );
    }

    /**
     * Fail-closed for requires_complete_artifact=true projects: no
     * AVAILABLE exact-commit artifact means the task is failed with an
     * explicit reason - the live-directory git pipeline is never used.
     */
    private function rejectMissingArtifactTask(DeploymentTask $task): void
    {
        $task->update(['status' => DeploymentTask::STATUS_FAILED]);
        $task->deployment->update([
            'failure_code' => Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING,
            'failure_message' => "Project '{$task->deployment->project->project_key}' requires a complete artifact for commit {$task->deployment->commit_hash}, but none is available. The live-directory git pipeline is forbidden for this project.",
        ]);
        $this->aggregator->recalculate($task->deployment);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'deployment_task.rejected_artifact_required_missing',
            subjectType: DeploymentTask::class,
            subjectId: $task->id,
            metadata: ['project_key' => $task->deployment->project->project_key, 'commit_hash' => $task->deployment->commit_hash],
        );
    }

    private function claim(DeploymentTask $task, Agent $agent): array
    {
        $now = now();

        $task->update(['status' => DeploymentTask::STATUS_RUNNING, 'next_retry_at' => null]);

        $attemptNumber = $task->agentJobs()->count() + 1;

        $agentJob = AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => $attemptNumber,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => $now,
            'last_activity_at' => $now,
        ]);

        // Root-cause fix (deployment details observability): the
        // deployment-level started_at was never stamped on the multi-target
        // path — the legacy claim path (AgentJobService::claimNextJob) sets
        // it, but this new-domain path only set AgentJob.started_at and
        // flipped the task to running, so a fully successful multi-target
        // deployment reached finished_at with started_at still null and the
        // details page could not show a start time or a duration. This claim
        // IS the deployment's authoritative start event (an agent actually
        // picked the job up), so stamp it here exactly once: the whereNull
        // guard is atomic, so two near-simultaneous claims of different
        // targets of the same rollout cannot double-write, and a later
        // retry attempt never rewrites the original start time.
        Deployment::whereKey($task->deployment_id)->whereNull('started_at')->update(['started_at' => $now]);

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
        // code resolves its configured local working directory by the
        // Project's canonical key, never by a human-facing target name.
        $job = [
            'id' => $agentJob->public_id,
            'project_key' => $task->deployment->project->project_key,
            'action' => $task->deployment->action,
            'commit_hash' => $task->deployment->commit_hash,
            'previous_commit_hash' => $task->deployment->previous_commit_hash,
            'branch' => $task->deployment->branch,
            'pipeline' => $task->deployment->project->pipeline,
            // Keep the new AgentTask wire payload at parity with the
            // legacy deployment payload. In particular, PipelineExecutor
            // can enforce artifact-only source acquisition only when this
            // exact matching artifact descriptor is present.
            'deployment_recipe' => $task->deployment->project->usesDeploymentRecipe()
                ? HttpHealthCheckPayload::appendToRecipe($task->deployment->project->deployment_recipe, $task->deployment->http_health_config)
                : null,
            'http_health_check' => $task->deployment->http_health_config,
            'recipe_rollback' => $task->deployment->project->usesDeploymentRecipe()
                && $task->deployment->action === Deployment::ACTION_ROLLBACK,
            'artifact' => $task->deployment->artifact !== null ? [
                'public_id' => $task->deployment->artifact->public_id,
                'checksum_sha256' => $task->deployment->artifact->checksum_sha256,
                'size_bytes' => $task->deployment->artifact->size_bytes,
                // Root-cause fix (Laravel release completeness): the Server
                // Agent's pre-activation gate needs the exact artifact commit
                // to pin the release. Mirrors AgentJobService's payload.
                'commit_hash' => $task->deployment->artifact->commit_hash,
                'required_paths' => $task->deployment->artifact->required_paths ?? [],
            ] : null,
            'issued_at' => now()->timestamp,
        ];

        return [
            'job' => $job,
            'signature' => $this->jobPayloadSigner->sign($job, $agent->signing_secret),
        ];
    }

    public function updateStatus(AgentJob $agentJob, string $status, ?string $message = null, ?string $failureCode = null): AgentJob
    {
        abort_unless(
            in_array($status, [AgentJob::STATUS_RUNNING, AgentJob::STATUS_SUCCEEDED, AgentJob::STATUS_FAILED], true),
            422,
            'Invalid status transition from agent.'
        );

        $attributes = ['status' => $status, 'last_activity_at' => now()];
        $deployment = $agentJob->deploymentTask->deployment;
        $healthLog = $deployment->hasHttpHealthCheck()
            ? $agentJob->logEntries()->where('step_type', 'http_health_check')->reorder()->latest('sequence')->first()
            : null;

        if ($status === AgentJob::STATUS_SUCCEEDED && $deployment->hasHttpHealthCheck()
            && ($healthLog === null || $healthLog->exit_code !== 0 || $healthLog->timed_out)) {
            $status = AgentJob::STATUS_FAILED;
            $attributes['status'] = $status;
            $message = 'The Agent reported success without a successful customer-facing HTTP health-check result.';
            $failureCode = Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED;
        }

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
            metadata: ['status' => $status, 'message' => $message, 'failure_code' => $failureCode],
        );

        if ($status === AgentJob::STATUS_SUCCEEDED) {
            $task = $agentJob->deploymentTask;
            $task->update(['status' => DeploymentTask::STATUS_SUCCEEDED]);
            $this->aggregator->recalculate($task->deployment);
        } elseif ($status === AgentJob::STATUS_FAILED) {
            $agentJob->deploymentTask->update(['failure_code' => $failureCode, 'failure_message' => $message]);
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
            'status' => $payload['status'] ?? null,
            'step_type' => $payload['step_type'] ?? null,
            'failure_code' => $payload['failure_code'] ?? null,
        ]);

        $agentJob->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $entry;
    }
}
