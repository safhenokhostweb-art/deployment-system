<?php

namespace App\Services\Deployment;

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
                ->oldest('queued_at')
                ->lockForUpdate()
                ->with('project')
                ->first();

            if (!$deployment) {
                return null;
            }

            $deployment->update([
                'status' => Deployment::STATUS_RUNNING,
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $server->public_id,
                action: 'deployment.claimed_by_agent',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
            );

            $job = [
                'id' => $deployment->public_id,
                'project_key' => $deployment->project->project_key,
                'action' => $deployment->action,
                'commit_hash' => $deployment->commit_hash,
                'previous_commit_hash' => $deployment->previous_commit_hash,
                'branch' => $deployment->branch,
                'pipeline' => $deployment->project->pipeline,
                'issued_at' => now()->timestamp,
            ];

            return [
                'job' => $job,
                'signature' => $this->jobPayloadSigner->sign($job, $server->signing_secret),
            ];
        });
    }

    public function updateStatus(Deployment $deployment, string $status, ?string $message = null): Deployment
    {
        abort_unless(
            in_array($status, [Deployment::STATUS_RUNNING, Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED], true),
            422,
            'Invalid status transition from agent.'
        );

        $attributes = ['status' => $status, 'last_activity_at' => now()];

        if ($status === Deployment::STATUS_RUNNING) {
            $attributes['started_at'] ??= now();
        }

        if (in_array($status, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED], true)) {
            $attributes['finished_at'] = now();
            $attributes['failure_message'] = $status === Deployment::STATUS_FAILED ? $message : null;
        }

        $deployment->update($attributes);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $deployment->server->public_id,
            action: 'deployment.status_reported',
            subjectType: Deployment::class,
            subjectId: $deployment->id,
            metadata: ['status' => $status, 'message' => $message],
        );

        return $deployment->refresh();
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
        ]);

        $deployment->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $entry;
    }
}
