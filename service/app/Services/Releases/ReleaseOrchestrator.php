<?php

namespace App\Services\Releases;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

class ReleaseOrchestrator
{
    /** @param array<string, mixed> $metadata */
    public function recordMetadata(Project $project, array $metadata): Release
    {
        return DB::transaction(function () use ($project, $metadata): Release {
            $release = Release::query()->firstOrCreate(
                ['project_id' => $project->id, 'commit_hash' => $metadata['commit_sha']],
                [
                    'branch' => $metadata['branch'],
                    'status' => Release::STATUS_SOURCE_RECEIVED,
                    'source' => 'project_contract',
                    'source_received_at' => now(),
                ],
            );
            if ($release->branch !== null && $release->branch !== $metadata['branch']) {
                abort(409, 'Release metadata branch does not match the recorded release.');
            }
            if (isset($release->metadata['base_sha']) && $release->metadata['base_sha'] !== $metadata['base_sha']) {
                abort(409, 'RELEASE METADATA MISMATCH: base SHA changed for an existing release.');
            }
            $release->forceFill(['branch' => $metadata['branch'], 'metadata' => $metadata])->save();
            if ($metadata['release_ready'] !== true) {
                return $this->markFailed($release, 'contract_not_ready', 'Project release gates did not pass.');
            }
            return $this->syncArtifactState($release);
        });
    }

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
