<?php

namespace App\Services\Deployment;

use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Events\RollbackCompleted;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\Server;
use App\Services\Security\JobPayloadSigner;
use App\Services\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;

class AgentJobService
{
    public function __construct(
        private readonly JobPayloadSigner $jobPayloadSigner,
        private readonly SecretRedactor $redactor,
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
                ->with('project')
                ->first();

            if (!$deployment) {
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
                'deployment_recipe' => $usesRecipe ? $project->deployment_recipe : null,
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

        if ($isTerminalSuccess || $isTerminalFailure || $isRolledBack) {
            $attributes['finished_at'] = $now;
            $attributes['failure_message'] = $isTerminalSuccess ? null : $message;
            $attributes['failure_code'] = $isTerminalSuccess ? null : $failureCode;
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
}
