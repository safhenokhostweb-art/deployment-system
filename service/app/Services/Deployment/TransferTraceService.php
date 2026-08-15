<?php

namespace App\Services\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\PublishJob;

/** Read-only SHA correlation over the existing lifecycle records. */
class TransferTraceService
{
    /** @return array<string, mixed> */
    public function forProject(Project $project): array
    {
        $publish = $project->publishJobs()->where('action', PublishJob::ACTION_PUBLISH)->latest('id')->first();
        $local = $project->localProjectMappings()->whereNotNull('reported_at')->latest('reported_at')->first();
        $artifact = $project->artifacts()->latest('id')->first();
        $deployment = $project->deployments()->latest('id')->first();
        $production = $project->deployments()->whereNotNull('deployed_at')->where('status', Deployment::STATUS_SUCCEEDED)->latest('deployed_at')->first();

        $publishedSha = $publish?->status === PublishJob::STATUS_SUCCEEDED ? $publish->result_commit_hash : null;
        $artifactSha = $artifact?->isAvailable() ? $artifact->commit_hash : null;
        $deploymentSha = $deployment?->commit_hash;
        $productionSha = $production?->commit_hash;
        $state = $this->state($local?->local_head_sha, $local?->remote_tracking_sha, (bool) $local?->working_tree_dirty, $publish, $artifact, $deployment, $productionSha);

        return [
            // The Agent reports result_commit_hash only after a successful
            // push, so we deliberately do not claim an unreported local SHA.
            'pushed_sha' => $publishedSha,
            'local_head_sha' => $local?->local_head_sha,
            'local_branch' => $local?->reported_branch,
            'working_tree_dirty' => $local?->working_tree_dirty,
            'remote_tracking_sha' => $local?->remote_tracking_sha,
            'local_reported_at' => $local?->reported_at?->toAtomString(),
            'publish_status' => $publish?->status,
            'publish_failure_code' => $publish?->failure_code,
            'artifact_sha' => $artifactSha,
            'artifact_status' => $artifact?->status,
            'artifact_checksum' => $artifact?->checksum_sha256,
            'deployment_sha' => $deploymentSha,
            'deployment_status' => $deployment?->status,
            'deployment_failure_code' => $deployment?->failure_code,
            'production_sha' => $productionSha,
            'last_successful_deployment_sha' => $productionSha,
            'pending_transfer_sha' => $this->pendingSha($publishedSha, $artifactSha, $deploymentSha, $productionSha),
            'failure_stage' => $this->failureStage($publish, $artifact, $deployment),
            'state' => $state,
            'warnings' => $this->warnings($local?->local_head_sha, $local?->remote_tracking_sha, (bool) $local?->working_tree_dirty, $publishedSha, $artifactSha, $deployment, $productionSha),
            'timestamps' => [
                'published_at' => $publish?->finished_at?->toAtomString(),
                'artifact_at' => $artifact?->created_at?->toAtomString(),
                'deployment_at' => $deployment?->finished_at?->toAtomString(),
                'production_at' => $production?->deployed_at?->toAtomString(),
            ],
        ];
    }

    private function pendingSha(?string $published, ?string $artifact, ?string $deployment, ?string $production): ?string
    {
        foreach ([$published, $artifact, $deployment] as $sha) {
            if ($sha !== null && $sha !== $production) return $sha;
        }
        return null;
    }

    private function failureStage(?PublishJob $publish, ?Artifact $artifact, ?Deployment $deployment): ?string
    {
        if ($publish !== null && in_array($publish->status, [PublishJob::STATUS_FAILED, PublishJob::STATUS_TIMED_OUT, PublishJob::STATUS_ABANDONED], true)) return 'publish';
        if ($artifact?->status === Artifact::STATUS_FAILED) return 'artifact_upload';
        if ($deployment?->status === Deployment::STATUS_FAILED) return 'deployment';
        return null;
    }

    /** @return array<int, string> */
    private function state(?string $local, ?string $remote, bool $dirty, ?PublishJob $publish, ?Artifact $artifact, ?Deployment $deployment, ?string $production): string
    {
        if ($dirty) return 'WORKTREE_DIRTY';
        if ($local !== null && $remote !== null && $local !== $remote) return 'LOCAL_ONLY';
        if ($publish !== null && in_array($publish->status, [PublishJob::STATUS_QUEUED, PublishJob::STATUS_AWAITING_APPROVAL, PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING], true)) return 'PUBLISH_PENDING';
        if ($artifact?->status === Artifact::STATUS_UPLOADING) return 'ARTIFACT_BUILDING';
        if ($deployment !== null && $deployment->isInProgress()) return 'DEPLOYING';
        if ($deployment !== null && in_array($deployment->status, [Deployment::STATUS_QUEUED, Deployment::STATUS_PENDING_APPROVAL], true)) return 'DEPLOY_PENDING';
        if ($deployment?->status === Deployment::STATUS_FAILED || $artifact?->status === Artifact::STATUS_FAILED) return 'FAILED';
        if ($artifact?->isAvailable() && ($deployment === null || $deployment->deployed_at === null)) return 'ARTIFACT_AVAILABLE';
        if ($production !== null && ($remote === null || $production === $remote)) return 'DEPLOYED';
        if ($production !== null && $remote !== null && $production !== $remote) return 'DRIFTED';
        return 'UNKNOWN';
    }

    private function warnings(?string $local, ?string $remote, bool $dirty, ?string $published, ?string $artifact, ?Deployment $deployment, ?string $production): array
    {
        $warnings = [];
        if ($dirty) $warnings[] = 'WORKTREE_DIRTY';
        if ($local !== null && $remote !== null && $local !== $remote) $warnings[] = 'LOCAL_NOT_PUSHED';
        if ($published !== null && $artifact === null) $warnings[] = 'PUSHED_BUT_NOT_PUBLISHED';
        if ($artifact !== null && ($deployment === null || $deployment->commit_hash !== $artifact || $deployment->deployed_at === null)) $warnings[] = 'ARTIFACT_READY_BUT_NOT_DEPLOYED';
        if ($deployment?->status === Deployment::STATUS_FAILED) $warnings[] = 'DEPLOYMENT_FAILED';
        if ($published !== null && $production !== null && $published !== $production) $warnings[] = 'PRODUCTION_BEHIND_REMOTE';
        if ($production === null) $warnings[] = 'PRODUCTION_SHA_UNKNOWN';
        return $warnings;
    }
}
