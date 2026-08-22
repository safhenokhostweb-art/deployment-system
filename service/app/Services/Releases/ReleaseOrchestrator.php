<?php

namespace App\Services\Releases;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

class ReleaseOrchestrator
{
    public function createForCommit(
        Project $project,
        string $commitHash,
        ?string $branch = null,
        string $source = 'manual',
        array $metadata = []
    ): Release {
        return DB::transaction(function () use ($project, $commitHash, $branch, $source, $metadata): Release {
            $release = Release::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'commit_hash' => $commitHash,
                ],
                [
                    'branch' => $branch,
                    'status' => Release::STATUS_SOURCE_RECEIVED,
                    'source' => $source,
                    'source_received_at' => now(),
                    'metadata' => $metadata === [] ? null : $metadata,
                ]
            );

            if (! $release->wasRecentlyCreated) {
                return $release;
            }

            return $this->syncArtifactState($release);
        });
    }

    public function syncArtifactState(Release $release): Release
    {
        return DB::transaction(function () use ($release): Release {
            $artifact = Artifact::query()
                ->where('project_id', $release->project_id)
                ->where('commit_hash', $release->commit_hash)
                ->where('status', Artifact::STATUS_AVAILABLE)
                ->lockForUpdate()
                ->first();

            if ($artifact !== null) {
                $artifact->update(['release_id' => $release->id]);

                $release->forceFill([
                    'status' => Release::STATUS_READY,
                    'artifact_checked_at' => now(),
                    'ready_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();

                return $release->refresh();
            }

            $release->forceFill([
                'status' => Release::STATUS_AWAITING_ARTIFACT,
                'artifact_checked_at' => now(),
                'ready_at' => null,
            ])->save();

            return $release->refresh();
        });
    }

    public function markArtifactBuilding(Release $release): Release
    {
        $release->forceFill([
            'status' => Release::STATUS_ARTIFACT_BUILDING,
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return $release->refresh();
    }

    public function markFailed(Release $release, string $failureCode, ?string $failureMessage = null): Release
    {
        $release->forceFill([
            'status' => Release::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
        ])->save();

        return $release->refresh();
    }
}
