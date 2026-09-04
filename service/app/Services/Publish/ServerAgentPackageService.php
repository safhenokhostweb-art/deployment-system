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
     * Idempotent: a build already queued/running for the exact same
     * commit is reused rather than duplicated, mirroring
     * PublishJobService's own dedupe reasoning for artifact builds.
     */
    public function queueBuildIfMissing(string $commitHash, string $branch, string $agentVersion, string $repositoryMapping, LocalAgent $builder): ServerAgentPackageBuildJob
    {
        return DB::transaction(function () use ($commitHash, $branch, $agentVersion, $repositoryMapping, $builder): ServerAgentPackageBuildJob {
            $existing = ServerAgentPackageBuildJob::query()
                ->where('commit_hash', $commitHash)
                ->where('repository_mapping', $repositoryMapping)
                ->whereIn('status', [ServerAgentPackageBuildJob::STATUS_QUEUED, ServerAgentPackageBuildJob::STATUS_RUNNING])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ServerAgentPackageBuildJob::create([
                'local_agent_id' => $builder->id,
                'commit_hash' => $commitHash,
                'branch' => $branch,
                'agent_version' => $agentVersion,
                'repository_mapping' => $repositoryMapping,
                'status' => ServerAgentPackageBuildJob::STATUS_QUEUED,
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
