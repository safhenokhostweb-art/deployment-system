<?php

namespace App\Services\Publish;

use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\ServerAgentControlJob;

/**
 * Queue/claim/report/verify lifecycle for a Server Agent exact-commit
 * self-upgrade — deliberately its own service, never
 * LocalAgentControlJobService, so Server Agent (Agent) and Local/Build
 * Agent (LocalAgent) identity can never be conflated. Mirrors
 * LocalAgentControlJobService's proven shape (queue -> claim -> report
 * started -> await heartbeat -> verify) closely, but every fact this
 * upgrade converges on — target version, target commit, expected
 * package hash — comes from ServerAgentUpgradeTargetResolver /
 * ServerAgentPackageService, never from a free-form caller-supplied
 * value, so a Server Agent upgrade can never be pointed at an arbitrary
 * command or unverified package (see ServerAgentPackage's own
 * hash-verified provenance).
 */
class ServerAgentControlJobService
{
    public function __construct(
        private readonly ServerAgentUpgradeTargetResolver $upgradeTargets,
        private readonly ServerAgentPackageService $packages,
    ) {
    }

    public function queueUpgrade(Agent $agent, ?string $requestedBy): ServerAgentControlJob
    {
        abort_unless($agent->isApproved(), 409, 'Only an approved Server Agent can be upgraded.');

        $target = $this->upgradeTargets->resolve();
        $expectedVersion = $target['agent_version'];

        abort_unless(
            is_string($agent->agent_version) && version_compare($agent->agent_version, $expectedVersion, '<'),
            409,
            'This Server Agent is already at the latest version.',
        );

        abort_if(
            ServerAgentControlJob::query()
                ->where('agent_id', $agent->id)
                ->whereIn('status', [
                    ServerAgentControlJob::STATUS_QUEUED,
                    ServerAgentControlJob::STATUS_RUNNING,
                    ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT,
                ])
                ->exists(),
            409,
            'An upgrade is already active for this Server Agent.',
        );

        $package = $target['package'];

        if ($package === null) {
            $project = Project::query()->where('project_key', 'deployment-system')->where('is_active', true)->first();
            $builder = $project?->authoritativeBuildAgent;

            abort_if($builder === null, 409, 'No authoritative build Local Agent is configured for deployment-system; a Server Agent package cannot be materialized.');

            // Root-cause fix (commit 9f7adfd shipped the Server Agent
            // package-build poller without a matching Local Agent
            // version bump): fail closed here, BEFORE queueing anything,
            // rather than leaving a ServerAgentPackageBuildJob sitting
            // queued forever against a builder that has no idea the
            // endpoint exists. Deliberately never consulted by
            // queueUpgrade() itself (this class's own upgrade action) or
            // by LocalAgentControlJobService — a stale builder must
            // always still be reachable through its own exact-commit
            // self-upgrade regardless of this gate.
            if (!$builder->supportsServerAgentPackageBuild()) {
                $minimum = (string) config('deployment.min_local_agent_version_for_server_agent_package_build');
                abort(
                    409,
                    'The authoritative build Local Agent (version ' . ($builder->agent_version ?? 'unknown')
                        . ") does not support Server Agent package builds. Upgrade it to at least {$minimum} with the server_agent_package_build capability before a Server Agent package can be materialized.",
                );
            }

            $this->packages->queueBuildIfMissing(
                $target['commit_hash'],
                $target['branch'],
                $expectedVersion,
                $target['repository_mapping'],
                $builder,
            );

            abort(409, 'No verified Server Agent package exists yet for the exact target commit; a build has been queued — retry shortly.');
        }

        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => $expectedVersion,
            'target_commit_sha' => $target['commit_hash'],
            'target_branch' => $target['branch'],
            'repository_mapping' => $target['repository_mapping'],
            'server_agent_package_id' => $package->id,
            'expected_package_hash' => $package->checksum_sha256,
            'requested_by' => $requestedBy,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $requestedBy,
            action: 'server_agent.upgrade_requested',
            subjectType: ServerAgentControlJob::class,
            subjectId: $job->id,
            metadata: [
                'agent_id' => $agent->id,
                'target_version' => $job->target_version,
                'target_commit_sha' => $job->target_commit_sha,
                'server_agent_package_id' => $job->server_agent_package_id,
            ],
        );

        return $job;
    }

    public function claimNext(Agent $agent): ?ServerAgentControlJob
    {
        $job = ServerAgentControlJob::query()
            ->where('agent_id', $agent->id)
            ->where('status', ServerAgentControlJob::STATUS_QUEUED)
            ->oldest('created_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $job->update(['status' => ServerAgentControlJob::STATUS_RUNNING]);

        return $job;
    }

    public function reportUpgradeStarted(
        ServerAgentControlJob $job,
        string $output,
        ?string $sourceCommit = null,
        ?string $packageHash = null,
    ): ServerAgentControlJob {
        abort_unless($job->status === ServerAgentControlJob::STATUS_RUNNING, 409, 'Only a running upgrade can begin activation.');
        abort_unless(is_string($sourceCommit) && preg_match('/^[0-9a-f]{40}$/', $sourceCommit) === 1, 422, 'Exact-commit upgrade evidence is required before activation.');
        abort_unless(is_string($packageHash) && preg_match('/^[0-9a-f]{64}$/', $packageHash) === 1, 422, 'Package checksum evidence is required before activation.');
        abort_unless(hash_equals((string) $job->target_commit_sha, $sourceCommit), 422, 'Upgrade source commit does not match the exact job target.');
        abort_unless(hash_equals((string) $job->expected_package_hash, $packageHash), 422, 'Upgrade package checksum does not match the expected, hash-verified package.');

        $job->update([
            'status' => ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT,
            'output' => $output,
            'error_message' => null,
            'reported_commit_hash' => $sourceCommit,
            'reported_package_hash' => $packageHash,
            'upgrade_started_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'server_agent.upgrade_started_awaiting_heartbeat',
            subjectType: ServerAgentControlJob::class,
            subjectId: $job->id,
            metadata: [
                'target_version' => $job->target_version,
                'target_commit_sha' => $job->target_commit_sha,
                'reported_commit_hash' => $sourceCommit,
                'reported_package_hash' => $packageHash,
            ],
        );

        return $job->refresh();
    }

    public function verifyUpgradeHeartbeat(Agent $agent, ?string $reportedVersion): int
    {
        if (!is_string($reportedVersion) || $reportedVersion === '') {
            return 0;
        }

        $verified = 0;
        $jobs = ServerAgentControlJob::query()
            ->where('agent_id', $agent->id)
            ->where('status', ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT)
            ->get();

        foreach ($jobs as $job) {
            if (!is_string($job->target_version) || !hash_equals($job->target_version, $reportedVersion)) {
                continue;
            }

            $job->update([
                'status' => ServerAgentControlJob::STATUS_SUCCEEDED,
                'reported_version' => $reportedVersion,
                'upgrade_verified_at' => now(),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $agent->public_id,
                action: 'server_agent.upgrade_verified_by_heartbeat',
                subjectType: ServerAgentControlJob::class,
                subjectId: $job->id,
                metadata: [
                    'target_version' => $job->target_version,
                    'reported_version' => $reportedVersion,
                    'target_commit_sha' => $job->target_commit_sha,
                    'reported_commit_hash' => $job->reported_commit_hash,
                ],
            );
            $verified++;
        }

        return $verified;
    }

    public function reportFailure(ServerAgentControlJob $job, string $message): ServerAgentControlJob
    {
        $job->update([
            'status' => ServerAgentControlJob::STATUS_FAILED,
            'error_message' => $message,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $job->agent->public_id,
            action: 'server_agent.upgrade_failed',
            subjectType: ServerAgentControlJob::class,
            subjectId: $job->id,
            metadata: [
                'message' => $job->error_message,
                'target_version' => $job->target_version,
                'target_commit_sha' => $job->target_commit_sha,
            ],
        );

        return $job;
    }
}
