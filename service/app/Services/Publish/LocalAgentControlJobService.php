<?php

namespace App\Services\Publish;

use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Services\Security\SecretRedactor;

/**
 * Phase 5.3: queue/claim/report lifecycle for a remote-control request
 * against a Local Agent (restart, fetch recent logs) — the same shape as
 * OperationJobService, the established pattern for "a Hub-initiated
 * action an agent picks up on its next poll."
 */
class LocalAgentControlJobService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly LocalAgentUpgradeTargetResolver $upgradeTargets,
    ) {
    }

    /** @param array{commit_hash:string,branch:string,repository_mapping:string,release_id:int}|null $upgradeTarget */
    public function queue(LocalAgent $localAgent, string $action, ?string $requestedBy, ?array $upgradeTarget = null): LocalAgentControlJob
    {
        abort_unless(
            in_array($action, [
                LocalAgentControlJob::ACTION_RESTART,
                LocalAgentControlJob::ACTION_FETCH_LOGS,
                LocalAgentControlJob::ACTION_RELOAD_CONFIG,
                LocalAgentControlJob::ACTION_UPGRADE,
            ], true),
            422,
            "Unknown local agent control action '{$action}'."
        );

        abort_if($action === LocalAgentControlJob::ACTION_UPGRADE && $upgradeTarget === null, 409, 'An exact-commit package target is required for a Local Agent upgrade.');
        $job = LocalAgentControlJob::create([
            'local_agent_id' => $localAgent->id,
            'action' => $action,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
            'requested_by' => $requestedBy,
            'expected_version' => $action === LocalAgentControlJob::ACTION_UPGRADE
                ? (string) config('deployment.latest_local_agent_version')
                : null,
            'expected_commit_hash' => $upgradeTarget['commit_hash'] ?? null,
            'expected_branch' => $upgradeTarget['branch'] ?? null,
            'repository_mapping' => $upgradeTarget['repository_mapping'] ?? null,
            'upgrade_release_id' => $upgradeTarget['release_id'] ?? null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: self::requestedAuditAction($action),
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
            metadata: array_filter([
                'local_agent_id' => $localAgent->id,
                'expected_version' => $job->expected_version,
                'expected_commit_hash' => $job->expected_commit_hash,
                'expected_branch' => $job->expected_branch,
                'repository_mapping' => $job->repository_mapping,
            ], static fn ($value): bool => $value !== null),
        );

        return $job;
    }

    public function queueUpgrade(LocalAgent $localAgent, ?string $requestedBy): LocalAgentControlJob
    {
        abort_unless(strcasecmp((string) $localAgent->operating_system, 'Linux') === 0, 422, 'Official remote upgrades are supported only for Linux Local Agents.');
        abort_unless(($localAgent->capabilities['linux_self_upgrade'] ?? false) === true, 409, 'This Linux Local Agent predates remote upgrade support. Run the official upgrade-linux.sh once to bootstrap it.');
        $exactCommitMinimum = (string) config('deployment.min_local_agent_version_for_exact_commit_upgrade');
        abort_unless(
            is_string($localAgent->agent_version) && version_compare($localAgent->agent_version, $exactCommitMinimum, '>='),
            409,
            "SERVER BOOTSTRAP REQUIRED — Agent versions below {$exactCommitMinimum} cannot validate exact-commit upgrade jobs. Run the official one-time 1.5.2 bootstrap before using Dashboard upgrades.",
        );

        $expected = (string) config('deployment.latest_local_agent_version');
        abort_unless(is_string($localAgent->agent_version) && version_compare($localAgent->agent_version, $expected, '<'), 409, 'This Local Agent is already at the latest version.');
        abort_if(
            LocalAgentControlJob::query()
                ->where('local_agent_id', $localAgent->id)
                ->where('action', LocalAgentControlJob::ACTION_UPGRADE)
                ->whereIn('status', [LocalAgentControlJob::STATUS_QUEUED, LocalAgentControlJob::STATUS_RUNNING, LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT])
                ->exists(),
            409,
            'An upgrade is already active for this Local Agent.'
        );

        return $this->queue($localAgent, LocalAgentControlJob::ACTION_UPGRADE, $requestedBy, $this->upgradeTargets->resolve());
    }

    /**
     * @param array<int, string>|null $onlyActions restricts which action(s)
     *     this call may claim — used by heartbeat-watchdog.php via the
     *     dedicated GET /control-jobs/next-restart route (see
     *     LocalAgentControlJobController::nextRestartOnly()) so it can
     *     poll the same queue as the main agent without ever claiming a
     *     fetch_logs/reload_config job it has no idea how to execute,
     *     which would otherwise leave that job claimed-but-never-reported
     *     — exactly the "stuck running forever" failure mode this whole
     *     effort exists to eliminate. null (the main agent's own call via
     *     GET /control-jobs/next) preserves the original unfiltered FIFO
     *     behavior.
     */
    public function claimNext(LocalAgent $localAgent, ?array $onlyActions = null): ?LocalAgentControlJob
    {
        $job = LocalAgentControlJob::query()
            ->where('local_agent_id', $localAgent->id)
            ->where('status', LocalAgentControlJob::STATUS_QUEUED)
            ->when($onlyActions !== null, fn ($query) => $query->whereIn('action', $onlyActions))
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => LocalAgentControlJob::STATUS_RUNNING]);

        return $job;
    }

    public function reportResult(LocalAgentControlJob $job, string $output): LocalAgentControlJob
    {
        if ($job->action === LocalAgentControlJob::ACTION_UPGRADE) {
            return $this->reportUpgradeStarted($job, $output);
        }

        $job->update([
            'status' => LocalAgentControlJob::STATUS_SUCCEEDED,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: self::completedAuditAction($job->action),
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
        );

        return $job;
    }

    public function reportUpgradeStarted(
        LocalAgentControlJob $job,
        string $output,
        ?string $sourceCommit = null,
        ?string $packageTreeHash = null,
        ?string $repositoryMapping = null,
    ): LocalAgentControlJob
    {
        abort_unless($job->action === LocalAgentControlJob::ACTION_UPGRADE, 422, 'Only an upgrade job can report upgrade start.');
        abort_unless($job->status === LocalAgentControlJob::STATUS_RUNNING, 409, 'Only a running upgrade can begin activation.');
        abort_unless(is_string($sourceCommit) && preg_match('/^[0-9a-f]{40}$/', $sourceCommit) === 1, 422, 'Exact-commit upgrade evidence is required before activation.');
        abort_unless(is_string($packageTreeHash) && preg_match('/^[0-9a-f]{40}$/', $packageTreeHash) === 1, 422, 'Exact package-tree evidence is required before activation.');
        abort_unless(is_string($repositoryMapping) && hash_equals((string) $job->repository_mapping, $repositoryMapping), 422, 'Upgrade repository mapping evidence does not match the job.');
        abort_unless(hash_equals((string) $job->expected_commit_hash, $sourceCommit), 422, 'Upgrade source commit does not match the exact job target.');

        $job->update([
            'status' => LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT,
            'output' => $this->redactor->redact($output),
            'error_message' => null,
            'reported_commit_hash' => $sourceCommit,
            'package_tree_hash' => $packageTreeHash,
            'upgrade_started_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'local_agent.upgrade_started_awaiting_heartbeat',
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
            metadata: [
                'expected_version' => $job->expected_version,
                'expected_commit_hash' => $job->expected_commit_hash,
                'reported_commit_hash' => $sourceCommit,
                'package_tree_hash' => $packageTreeHash,
                'repository_mapping' => $job->repository_mapping,
            ],
        );

        return $job->refresh();
    }

    public function verifyUpgradeHeartbeat(LocalAgent $localAgent, ?string $reportedVersion): int
    {
        if (!is_string($reportedVersion) || $reportedVersion === '') {
            return 0;
        }

        $verified = 0;
        $jobs = LocalAgentControlJob::query()
            ->where('local_agent_id', $localAgent->id)
            ->where('action', LocalAgentControlJob::ACTION_UPGRADE)
            ->where('status', LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT)
            ->get();

        foreach ($jobs as $job) {
            if (!is_string($job->expected_version) || !hash_equals($job->expected_version, $reportedVersion)) {
                continue;
            }

            $job->update([
                'status' => LocalAgentControlJob::STATUS_SUCCEEDED,
                'reported_version' => $reportedVersion,
                'upgrade_verified_at' => now(),
            ]);
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $localAgent->public_id,
                action: 'local_agent.upgrade_verified_by_heartbeat',
                subjectType: LocalAgentControlJob::class,
                subjectId: $job->id,
                metadata: [
                    'expected_version' => $job->expected_version,
                    'reported_version' => $reportedVersion,
                    'expected_commit_hash' => $job->expected_commit_hash,
                    'reported_commit_hash' => $job->reported_commit_hash,
                    'package_tree_hash' => $job->package_tree_hash,
                    'repository_mapping' => $job->repository_mapping,
                ],
            );
            $verified++;
        }

        return $verified;
    }

    public function reportFailure(LocalAgentControlJob $job, string $message): LocalAgentControlJob
    {
        $job->update([
            'status' => LocalAgentControlJob::STATUS_FAILED,
            'error_message' => $this->redactor->redact($message),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->localAgent->public_id,
            action: 'local_agent.control_job_failed',
            subjectType: LocalAgentControlJob::class,
            subjectId: $job->id,
            metadata: [
                'action' => $job->action,
                'message' => $job->error_message,
                'expected_version' => $job->expected_version,
                'expected_commit_hash' => $job->expected_commit_hash,
                'repository_mapping' => $job->repository_mapping,
            ],
        );

        return $job;
    }

    private static function requestedAuditAction(string $action): string
    {
        return match ($action) {
            LocalAgentControlJob::ACTION_RESTART => 'local_agent.restart_requested',
            LocalAgentControlJob::ACTION_RELOAD_CONFIG => 'local_agent.reload_config_requested',
            LocalAgentControlJob::ACTION_UPGRADE => 'local_agent.upgrade_requested',
            default => 'local_agent.logs_requested',
        };
    }

    private static function completedAuditAction(string $action): string
    {
        return match ($action) {
            LocalAgentControlJob::ACTION_RESTART => 'local_agent.restarted',
            LocalAgentControlJob::ACTION_RELOAD_CONFIG => 'local_agent.reload_config_completed',
            LocalAgentControlJob::ACTION_UPGRADE => 'local_agent.upgraded',
            default => 'local_agent.logs_fetched',
        };
    }
}
