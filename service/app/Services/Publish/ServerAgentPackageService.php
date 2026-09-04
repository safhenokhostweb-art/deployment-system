<?php

namespace App\Services\Publish;

use App\Models\LocalAgent;
use App\Models\ServerAgentPackage;
use App\Models\ServerAgentPackageBuildJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Owns the exact-commit ServerAgentPackage lifecycle: which Local Agent
 * builds one (queue/claim), what the Local Agent uploads (record), and
 * what's currently available (findAvailable). Deliberately separate
 * from ArtifactService — a ServerAgentPackage is Server Agent runtime
 * code, never an application deployment Artifact, and must never be
 * reachable through the Release/Deployment/NO CONFIRM -> NO DEPLOYMENT
 * approval machinery.
 */
class ServerAgentPackageService
{
    public function findAvailable(string $commitHash, string $repositoryMapping): ?ServerAgentPackage
    {
        return ServerAgentPackage::query()
            ->where('commit_hash', $commitHash)
            ->where('repository_mapping', $repositoryMapping)
            ->where('status', ServerAgentPackage::STATUS_AVAILABLE)
            ->first();
    }

    /**
     * Idempotent AND recoverable for the exact (commit_hash,
     * repository_mapping) key the table's own unique constraint
     * enforces: a build already QUEUED/RUNNING is reused unchanged; a
     * FAILED build (e.g. Production ServerAgentPackageBuildJob #1,
     * "Class SafeFilesystem not found") is atomically reset back to
     * QUEUED on THAT SAME ROW rather than left stranded — a naive
     * second create() for the same key would hit the unique constraint
     * and throw an uncaught QueryException. lockForUpdate() inside this
     * transaction is what makes a concurrent double-click (two
     * "Upgrade Server Agent" clicks racing) resolve to exactly one
     * outcome rather than a duplicate-key 500 for the loser.
     *
     * The caller (ServerAgentControlJobService::queueUpgrade()) has
     * already verified $builder->supportsServerAgentPackageBuild()
     * before this method is ever reached — never re-checked here, so
     * there is exactly one place that guard can be bypassed from, and
     * this isn't it.
     */
    public function queueBuildIfMissing(string $commitHash, string $branch, string $agentVersion, string $repositoryMapping, LocalAgent $builder): ServerAgentPackageBuildJob
    {
        return DB::transaction(function () use ($commitHash, $branch, $agentVersion, $repositoryMapping, $builder): ServerAgentPackageBuildJob {
            $existing = ServerAgentPackageBuildJob::query()
                ->where('commit_hash', $commitHash)
                ->where('repository_mapping', $repositoryMapping)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== ServerAgentPackageBuildJob::STATUS_FAILED) {
                    return $existing;
                }

                // Reuse the SAME row — never a second create() for this
                // exact-commit key. commit_hash/repository_mapping/
                // agent_version(target)/branch are the immutable target
                // identity and are left untouched; only what genuinely
                // changed between attempts (which builder, its
                // point-in-time version, and the stale failure) is reset.
                $existing->update([
                    'status' => ServerAgentPackageBuildJob::STATUS_QUEUED,
                    'local_agent_id' => $builder->id,
                    'builder_agent_version' => $builder->agent_version,
                    'error_message' => null,
                ]);

                return $existing->fresh();
            }

            return ServerAgentPackageBuildJob::create([
                'local_agent_id' => $builder->id,
                'commit_hash' => $commitHash,
                'branch' => $branch,
                'agent_version' => $agentVersion,
                'repository_mapping' => $repositoryMapping,
                'status' => ServerAgentPackageBuildJob::STATUS_QUEUED,
                // Point-in-time audit fact only — the caller is
                // responsible for having already verified this builder
                // is eligible (see LocalAgent::supportsServerAgentPackageBuild()
                // / ServerAgentControlJobService::queueUpgrade()); this
                // is not itself an eligibility check.
                'builder_agent_version' => $builder->agent_version,
            ]);
        });
    }

    public function claimNextBuildJob(LocalAgent $agent): ?ServerAgentPackageBuildJob
    {
        return DB::transaction(function () use ($agent): ?ServerAgentPackageBuildJob {
            $job = ServerAgentPackageBuildJob::query()
                ->where('local_agent_id', $agent->id)
                ->where('status', ServerAgentPackageBuildJob::STATUS_QUEUED)
                ->oldest('created_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->update(['status' => ServerAgentPackageBuildJob::STATUS_RUNNING]);

            return $job;
        });
    }

    /**
     * @param array{commit_hash:string,repository_mapping:string,agent_version:string,tree_hash:string,checksum_sha256:string,size_bytes:int,disk_path:string} $payload
     */
    public function recordPackageUploaded(ServerAgentPackageBuildJob $job, array $payload): ServerAgentPackage
    {
        abort_unless($job->status === ServerAgentPackageBuildJob::STATUS_RUNNING, 409, 'Only a running package build job can report an uploaded package.');
        abort_unless(hash_equals($job->commit_hash, $payload['commit_hash']), 422, 'Uploaded package commit does not match the exact job target.');
        abort_unless(hash_equals($job->repository_mapping, $payload['repository_mapping']), 422, 'Uploaded package repository mapping does not match the job.');

        $package = ServerAgentPackage::create([
            'commit_hash' => $payload['commit_hash'],
            'agent_version' => $payload['agent_version'],
            'repository_mapping' => $payload['repository_mapping'],
            'tree_hash' => $payload['tree_hash'],
            'checksum_sha256' => $payload['checksum_sha256'],
            'size_bytes' => $payload['size_bytes'],
            'disk_path' => $payload['disk_path'],
            'status' => ServerAgentPackage::STATUS_AVAILABLE,
        ]);

        $job->update(['status' => ServerAgentPackageBuildJob::STATUS_SUCCEEDED, 'server_agent_package_id' => $package->id]);

        return $package;
    }

    public function reportBuildFailure(ServerAgentPackageBuildJob $job, string $message): ServerAgentPackageBuildJob
    {
        $job->update(['status' => ServerAgentPackageBuildJob::STATUS_FAILED, 'error_message' => $message]);

        return $job;
    }

    public function deletePackageFile(ServerAgentPackage $package): void
    {
        Storage::disk('server-agent-packages')->delete($package->disk_path);
    }
}
