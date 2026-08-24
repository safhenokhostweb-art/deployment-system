<?php

namespace App\Services\Deployment;

use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Events\RollbackCompleted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\Server;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Security\JobPayloadSigner;
use App\Services\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;
use App\Support\Deployment\HttpHealthCheckPayload;

class AgentJobService
{
    public function __construct(
        private readonly JobPayloadSigner $jobPayloadSigner,
        private readonly SecretRedactor $redactor,
        private readonly ArtifactService $artifacts,
    ) {
    }

    /**
     * Atomically claims the oldest queued job for this server (if any) and
     * returns a signed payload for the agent to execute. Claiming flips the
     * job straight to 'running' inside the same locked transaction, so two
     * near-simultaneous polls can never both receive the same job.
     *
     * @return array{job: array, signature: string}|null
     */
    public function claimNextJob(Server $server): ?array
    {
        return DB::transaction(function () use ($server) {
            $deployment = Deployment::query()
                ->where('server_id', $server->id)
                ->where('status', Deployment::STATUS_QUEUED)
                // Phase 3-C.2: a multi-target Deployment aggregate can also
                // reach status=queued, but it must only ever be claimed
                // through its DeploymentTasks (AgentTaskService), never
                // directly — otherwise it would be executed once, whole,
                // against whichever server this row's (non-authoritative)
                // server_id happens to hold, instead of once per target.
                // Every legacy Deployment has zero tasks, always, so this
                // excludes nothing that existed before this phase.
                ->whereDoesntHave('tasks')
                ->oldest('queued_at')
                ->lockForUpdate()
                ->with(['project', 'artifact'])
                ->first();

            if (!$deployment) {
                return null;
            }

            // Root-cause fix (artifact-to-webhook correlation): the Local
            // Agent can finish uploading the exact-commit artifact a few
            // seconds AFTER the webhook created this deployment. Re-attach
            // here, at the last pre-execution boundary, so the job carries
            // the artifact instead of silently falling back to the
            // live-directory git pipeline.
            $this->artifacts->attachToDeploymentIfAvailable($deployment);
            $deployment->load('artifact');

            // Root-cause fix (Laravel release completeness): a project with
            // requires_complete_artifact=true must NEVER fall back to the
            // live-directory git pipeline. No exact-commit AVAILABLE artifact
            // after the re-attach attempt -> fail closed with an explicit code.
            if (
                $deployment->project->requires_complete_artifact
                && $deployment->action === Deployment::ACTION_DEPLOY
                && $deployment->artifact === null
            ) {
                $deployment->update([
                    'status' => Deployment::STATUS_FAILED,
                    'finished_at' => now(),
                    'last_activity_at' => now(),
                    'failure_code' => Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING,
                    'failure_message' => "Project '{$deployment->project->project_key}' requires a complete artifact for commit {$deployment->commit_hash}, but none is available. The live-directory git pipeline is forbidden for this project.",
                ]);

                AuditLogEntry::record(
                    actorType: AuditLogEntry::ACTOR_AGENT,
                    actorIdentifier: $server->public_id,
                    action: 'deployment.rejected_artifact_required_missing',
                    subjectType: Deployment::class,
                    subjectId: $deployment->id,
                    metadata: ['project_key' => $deployment->project->project_key, 'commit_hash' => $deployment->commit_hash],
                );

                return null;
            }

            $now = now();
            $leaseSeconds = (int) config('deployment.deployment_job_lease_seconds');

            $deployment->update([
                'status' => Deployment::STATUS_RUNNING,
                'started_at' => $now,
                'last_activity_at' => $now,
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $server->public_id,
                action: 'deployment.claimed_by_agent',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['lease_expires_at' => $deployment->lease_expires_at->toIso8601String()],
            );

            $project = $deployment->project;
            $usesRecipe = $project->usesDeploymentRecipe();

            // Phase: Laravel Release Completeness — the new fail-closed
            // pre-activation release gate is enforced by the Server Agent
            // itself (agent/lib/ReleaseValidator.php). A release that
            // requires that contract must NEVER be dispatched to an agent
            // too old to enforce it: fail the deployment here, at claim
            // time, before the artifact is ever handed to the agent, instead
            // of trusting a gate the installed agent build does not run.
            if ($deployment->artifact !== null && !$this->serverAgentEnforcesReleaseGate($server)) {
                $reportedVersion = $server->agents()->first()?->agent_version;
                $requiredVersion = (string) config('deployment.min_server_agent_version_for_artifact');

                $deployment->update([
                    'status' => Deployment::STATUS_FAILED,
                    'finished_at' => now(),
                    'last_activity_at' => now(),
                    'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
                    'failure_message' => "The server agent (version " . ($reportedVersion ?? 'unknown') . ") is too old to enforce the Laravel release-completeness gate. Upgrade the agent to at least {$requiredVersion} before artifact-based deployments.",
                ]);

                AuditLogEntry::record(
                    actorType: AuditLogEntry::ACTOR_AGENT,
                    actorIdentifier: $server->public_id,
                    action: 'deployment.rejected_agent_incompatible',
                    subjectType: Deployment::class,
                    subjectId: $deployment->id,
                    metadata: ['agent_version' => $reportedVersion, 'required_version' => $requiredVersion],
                );

                return null;
            }

            if ($deployment->hasHttpHealthCheck() && !$this->serverAgentEnforcesHttpHealth($server)) {
                $reportedVersion = $server->agents()->first()?->agent_version;
                $requiredVersion = (string) config('deployment.min_server_agent_version_for_http_health');
                $deployment->update([
                    'status' => Deployment::STATUS_FAILED,
                    'finished_at' => now(),
                    'last_activity_at' => now(),
                    'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
                    'failure_message' => "The server agent (version " . ($reportedVersion ?? 'unknown') . ") cannot execute customer-facing HTTP health verification. Upgrade the agent to at least {$requiredVersion} before deploying this project.",
                ]);

                return null;
            }

            $job = [
                'id' => $deployment->public_id,
                'project_key' => $project->project_key,
                'action' => $deployment->action,
                'commit_hash' => $deployment->commit_hash,
                'previous_commit_hash' => $deployment->previous_commit_hash,
                'branch' => $deployment->branch,
                // Always present, unchanged shape — an agent that has no
                // idea what deployment_recipe means keeps working exactly
                // as it does today. Only consulted by the agent when
                // deployment_recipe is null/absent below.
                'pipeline' => $project->pipeline,
                // Root-cause fix (sandogh deployment overhaul): takes
                // precedence over `pipeline` on the agent side whenever
                // non-null — see Project::usesDeploymentRecipe(). Already
                // fully normalized (defaults filled in) by RecipeValidator
                // at save time, so the agent never has to guess a default.
                'deployment_recipe' => $usesRecipe
                    ? HttpHealthCheckPayload::appendToRecipe($project->deployment_recipe, $deployment->http_health_config)
                    : null,
                'http_health_check' => $deployment->http_health_config,
                // A rollback of a recipe-driven project never re-runs the
                // build recipe against an old commit (slow, and the whole
                // point of keeping releases around is to avoid rebuilding)
                // — it tells the agent to run its fixed, built-in
                // "reactivate the previous release" sequence instead. The
                // agent resolves *which* release is previous itself from
                // its own release directory (the Hub still never learns
                // server file paths). A plain pipeline project's rollback
                // is completely unaffected: recipe_rollback is simply
                // absent/false and RollbackService's legacy full-pipeline
                // re-run behavior continues exactly as before.
                'recipe_rollback' => $usesRecipe && $deployment->action === Deployment::ACTION_ROLLBACK,
                // Root-cause fix (customer-site direct-GitHub-dependency
                // removal): present only when the Local Agent already
                // uploaded a matching artifact for this exact commit (see
                // DeploymentDispatcher::attachArtifactIfAvailable()) — the
                // agent downloads it from THIS Hub (a connection it
                // already needs for polling) instead of ever fetching
                // from GitHub. null for every project not using the
                // Local Agent artifact-publish flow; the agent then runs
                // the exact legacy git-based pipeline, unchanged.
                'artifact' => $deployment->artifact !== null ? [
                    'public_id' => $deployment->artifact->public_id,
                    'checksum_sha256' => $deployment->artifact->checksum_sha256,
                    'size_bytes' => $deployment->artifact->size_bytes,
                    // Root-cause fix (Laravel release completeness): the
                    // Server Agent's pre-activation gate needs the exact
                    // commit and required runtime paths the artifact was
                    // built from. Before this fix the legacy claim path
                    // omitted them, so ArtifactManager received an empty
                    // required_paths list and silently skipped its
                    // completeness check (fail-open). Mirror
                    // AgentTaskService's task payload.
                    'commit_hash' => $deployment->artifact->commit_hash,
                    'required_paths' => $deployment->artifact->required_paths ?? [],
                ] : null,
                'issued_at' => now()->timestamp,
            ];

            return [
                'job' => $job,
                'signature' => $this->jobPayloadSigner->sign($job, $server->signing_secret),
            ];
        });
    }

    /**
     * @param string|null $failureCode one of Deployment::FAILURE_CODE_* —
     *     only meaningful (and only ever set) alongside `failed` or
     *     `rolled_back`.
     */
    public function updateStatus(Deployment $deployment, string $status, ?string $message = null, ?string $failureCode = null): Deployment
    {
        abort_unless(in_array($status, self::AGENT_REPORTABLE_STATUSES, true), 422, 'Invalid status transition from agent.');

        $now = now();
        $leaseSeconds = (int) config('deployment.deployment_job_lease_seconds');

        // Any status report is evidence the agent is alive — renew the
        // lease here too, not only on the dedicated heartbeat() call, so
        // a recipe with many short/fast steps doesn't need a heartbeat
        // tick between every single status transition.
        $attributes = [
            'status' => $status,
            'last_activity_at' => $now,
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
        ];

        if ($status === Deployment::STATUS_RUNNING) {
            $attributes['started_at'] ??= $now;
        }

        $isTerminalSuccess = $status === Deployment::STATUS_SUCCEEDED;
        $isTerminalFailure = $status === Deployment::STATUS_FAILED;
        $isRolledBack = $status === Deployment::STATUS_ROLLED_BACK;

        $healthLog = $deployment->hasHttpHealthCheck()
            ? $deployment->logEntries()->where('step_type', 'http_health_check')->reorder()->latest('sequence')->first()
            : null;

        // A configured health check is a fail-closed protocol contract. An
        // old or faulty Agent cannot claim success without first recording
        // the actual HTTP step and its successful exit code.
        if ($isTerminalSuccess && $deployment->hasHttpHealthCheck()
            && ($healthLog === null || $healthLog->exit_code !== 0 || $healthLog->timed_out)) {
            $status = Deployment::STATUS_FAILED;
            $message = 'The Agent reported success without a successful customer-facing HTTP health-check result.';
            $failureCode = Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED;
            $isTerminalSuccess = false;
            $isTerminalFailure = true;
            $attributes['status'] = $status;
        }

        if ($isTerminalSuccess || $isTerminalFailure || $isRolledBack) {
            $attributes['finished_at'] = $now;
            $attributes['failure_message'] = $isTerminalSuccess ? null : $message;
            $attributes['failure_code'] = $isTerminalSuccess ? null : $failureCode;
        }

        if ($isTerminalSuccess || ($isTerminalFailure && $failureCode === Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED)) {
            $attributes['execution_status'] = Deployment::EXECUTION_SUCCEEDED;
            $attributes['execution_finished_at'] = $now;
        } elseif ($isTerminalFailure) {
            $attributes['execution_status'] = Deployment::EXECUTION_FAILED;
            $attributes['execution_finished_at'] = $now;
        }

        if ($deployment->hasHttpHealthCheck() && $isTerminalSuccess) {
            $attributes['http_health_status'] = Deployment::HTTP_HEALTH_VERIFIED;
            $attributes['http_health_verified_at'] = $now;
            $attributes['http_health_details'] = [
                'step_name' => $healthLog?->step_name,
                'exit_code' => $healthLog?->exit_code,
                'duration_ms' => $healthLog?->duration_ms,
                'output' => $healthLog?->output,
            ];
        } elseif ($deployment->hasHttpHealthCheck() && $isTerminalFailure
            && $failureCode === Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED) {
            $attributes['http_health_status'] = Deployment::HTTP_HEALTH_FAILED;
            $attributes['http_health_details'] = [
                'step_name' => $healthLog?->step_name,
                'exit_code' => $healthLog?->exit_code,
                'duration_ms' => $healthLog?->duration_ms,
                'output' => $healthLog?->output ?? $message,
            ];
        }

        if ($isTerminalSuccess) {
            // Phase 5's strong success signal — see
            // Deployment::isVerifiedDeployed(). By the time the agent
            // reports `succeeded` for a recipe-driven deployment, every
            // http_health_check/verify_service_active step it contains
            // has already passed (the agent stops and reports `failed`
            // the instant any step fails) — so this is genuinely
            // "verified deployed", not just "commands exited 0", for
            // both recipe and plain-pipeline projects alike.
            $attributes['deployed_at'] = $now;
        }

        if ($isRolledBack) {
            $attributes['rolled_back_at'] = $now;
        }

        $deployment->update($attributes);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $deployment->server->public_id,
            action: 'deployment.status_reported',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
            metadata: ['status' => $status, 'message' => $message, 'failure_code' => $failureCode],
        );

        // Task 7: notification foundation — no listener registered yet,
        // so dispatching changes no behavior.
        if ($isTerminalSuccess) {
            event($deployment->action === Deployment::ACTION_ROLLBACK
                ? new RollbackCompleted($deployment)
                : new DeploymentSucceeded($deployment));
        } elseif ($isTerminalFailure) {
            event(new DeploymentFailed($deployment, $message));
        } elseif ($isRolledBack) {
            event(new RollbackCompleted($deployment));
        }

        return $deployment->refresh();
    }

    /**
     * A distinct, lightweight lease renewal for the ONE deployment a
     * server is actively executing — called by the agent mid-step
     * (during a long `npm ci` or Next.js build), independent of step
     * boundaries. Mirrors PublishJobService::heartbeat(). A stray
     * heartbeat for a deployment that has already reached a terminal
     * state is a no-op (the agent's report() and a heartbeat tick can
     * race harmlessly), not an error the agent has to handle specially.
     */
    public function heartbeat(Deployment $deployment): Deployment
    {
        if ($deployment->isTerminal()) {
            return $deployment;
        }

        abort_unless($deployment->isInProgress(), 409, 'Only an in-progress deployment accepts a heartbeat.');

        $now = now();
        $leaseSeconds = (int) config('deployment.deployment_job_lease_seconds');

        $deployment->update([
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            'last_activity_at' => $now,
        ]);

        return $deployment;
    }

    public function recordLog(Deployment $deployment, array $payload): DeploymentLogEntry
    {
        $entry = DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
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

        $now = now();
        $deployment->forceFill([
            'last_activity_at' => $now,
            'heartbeat_at' => $now,
        ])->saveQuietly();

        return $entry;
    }

    private const AGENT_REPORTABLE_STATUSES = [
        Deployment::STATUS_RUNNING,
        Deployment::STATUS_BUILDING,
        Deployment::STATUS_MIGRATING,
        Deployment::STATUS_ACTIVATING,
        Deployment::STATUS_VERIFYING,
        Deployment::STATUS_SUCCEEDED,
        Deployment::STATUS_FAILED,
        Deployment::STATUS_ROLLED_BACK,
    ];

    /**
     * Whether the claiming server's installed agent build is known to
     * enforce the Laravel release-completeness gate (agent/lib/
     * ReleaseValidator.php). Version is taken from the Agent identity the
     * server reports on every heartbeat (never the stale legacy
     * Server.agent_version column), and an unknown/absent version is
     * treated as incompatible — never assumed current.
     */
    private function serverAgentEnforcesReleaseGate(Server $server): bool
    {
        $agentVersion = $server->agents()->first()?->agent_version;

        if (!is_string($agentVersion) || $agentVersion === '') {
            return false;
        }

        return version_compare($agentVersion, (string) config('deployment.min_server_agent_version_for_artifact'), '>=');
    }

    private function serverAgentEnforcesHttpHealth(Server $server): bool
    {
        $agentVersion = $server->agents()->first()?->agent_version;

        return is_string($agentVersion)
            && $agentVersion !== ''
            && version_compare($agentVersion, (string) config('deployment.min_server_agent_version_for_http_health'), '>=');
    }
}
