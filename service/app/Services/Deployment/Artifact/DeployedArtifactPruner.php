<?php

namespace App\Services\Deployment\Artifact;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for artifacts that a Deployment references. ArtifactService::
 * cleanupOldArtifacts() and artifacts:cleanup skip those entirely, so every
 * deployed artifact used to stay on disk forever.
 *
 * Per project, the newest $keep deployed artifacts (ordered by the latest
 * Deployment that used them) are kept, and these are kept whatever $keep is:
 *  - live: the artifact of the project's latest succeeded deployment, and
 *    of each deployment target's latest succeeded task;
 *  - rollback target: the artifact of the previous succeeded deployment
 *    (what RollbackService::rollbackToPrevious() picks), the newest
 *    succeeded deployment whose artifact differs from the live one, and
 *    the same two for each deployment target;
 *  - in flight: any artifact of a deployment that is not terminal yet.
 *
 * Pruning deletes the file and marks the row STATUS_DELETED instead of
 * deleting it, so deployments keep their artifact_id, checksum and commit.
 */
class DeployedArtifactPruner
{
    public const DECISION_KEEP_NEWEST = 'keep_newest';
    public const DECISION_PROTECTED = 'protected';
    public const DECISION_PRUNE = 'prune';

    /**
     * @return list<array{artifact: Artifact, decision: string, reasons: list<string>, last_deployment_id: int}>
     */
    public function plan(Project $project, int $keep): array
    {
        $keep = max(1, $keep);
        $protected = $this->protectedArtifactIds($project);

        $deployed = Artifact::where('project_id', $project->id)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->whereHas('deployments')
            ->withMax('deployments', 'id')
            ->get()
            ->sortByDesc('deployments_max_id')
            ->values();

        $plan = [];
        foreach ($deployed as $index => $artifact) {
            $reasons = $protected[$artifact->id] ?? [];
            $decision = match (true) {
                $index < $keep => self::DECISION_KEEP_NEWEST,
                $reasons !== [] => self::DECISION_PROTECTED,
                default => self::DECISION_PRUNE,
            };

            $plan[] = [
                'artifact' => $artifact,
                'decision' => $decision,
                'reasons' => $reasons,
                'last_deployment_id' => (int) $artifact->deployments_max_id,
            ];
        }

        return $plan;
    }

    /** Delete the file and mark the row deleted. Returns the bytes removed. */
    public function prune(Artifact $artifact, ?string $actor = null): int
    {
        return DB::transaction(function () use ($artifact, $actor) {
            $artifact = Artifact::whereKey($artifact->id)->lockForUpdate()->first();
            if ($artifact === null || !$artifact->isAvailable()) {
                return 0;
            }

            // Re-check under the lock: a deployment may have started after
            // the plan was made.
            if (isset($this->protectedArtifactIds($artifact->project)[$artifact->id])) {
                return 0;
            }

            $disk = Storage::disk('artifacts');
            $hadFile = $artifact->disk_path !== null && $disk->exists($artifact->disk_path);
            $bytes = $hadFile ? (int) $disk->size($artifact->disk_path) : 0;
            if ($hadFile) {
                $disk->delete($artifact->disk_path);
            }

            $artifact->update(['status' => Artifact::STATUS_DELETED]);

            AuditLogEntry::record(
                actorType: $actor === null ? AuditLogEntry::ACTOR_SYSTEM : AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actor,
                action: 'artifact.deployed_retention_pruned',
                subjectType: Artifact::class,
                subjectId: $artifact->id,
                metadata: [
                    'project_id' => $artifact->project_id,
                    'commit_hash' => $artifact->commit_hash,
                    'checksum_sha256' => $artifact->checksum_sha256,
                    'size_bytes' => $artifact->size_bytes,
                    'file_existed' => $hadFile,
                    'reclaimed_bytes' => $bytes,
                ],
            );

            return $bytes;
        });
    }

    /**
     * Artifact id => why it must never be pruned.
     *
     * @return array<int, list<string>>
     */
    public function protectedArtifactIds(Project $project): array
    {
        $protected = [];
        $mark = function (?int $artifactId, string $reason) use (&$protected): void {
            if ($artifactId !== null && !in_array($reason, $protected[$artifactId] ?? [], true)) {
                $protected[$artifactId][] = $reason;
            }
        };

        $succeeded = Deployment::where('project_id', $project->id)
            ->where('status', Deployment::STATUS_SUCCEEDED)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get(['id', 'artifact_id']);
        $this->markLiveAndRollback($succeeded->pluck('artifact_id')->all(), $mark, '');

        $targetIds = DeploymentTarget::whereHas('environment', fn ($q) => $q->where('project_id', $project->id))->pluck('id');
        foreach ($targetIds as $targetId) {
            $artifactIds = DeploymentTask::where('deployment_target_id', $targetId)
                ->where('status', DeploymentTask::STATUS_SUCCEEDED)
                ->orderByDesc('id')
                ->with('deployment:id,artifact_id')
                ->get()
                ->map(fn (DeploymentTask $task) => $task->deployment?->artifact_id)
                ->all();
            $this->markLiveAndRollback($artifactIds, $mark, " (target {$targetId})");
        }

        Deployment::where('project_id', $project->id)
            ->whereNotNull('artifact_id')
            ->whereNotIn('status', [
                Deployment::STATUS_SUCCEEDED,
                Deployment::STATUS_FAILED,
                Deployment::STATUS_CANCELLED,
                Deployment::STATUS_PARTIAL_SUCCESS,
                Deployment::STATUS_ROLLED_BACK,
            ])
            ->pluck('artifact_id')
            ->each(fn ($id) => $mark((int) $id, 'in_flight'));

        return $protected;
    }

    /**
     * @param list<int|null> $artifactIds artifact ids of succeeded runs, newest first
     * @param \Closure(?int, string): void $mark
     */
    private function markLiveAndRollback(array $artifactIds, \Closure $mark, string $suffix): void
    {
        if ($artifactIds === []) {
            return;
        }

        $live = $artifactIds[0];
        $mark($live, 'live' . $suffix);
        $mark($artifactIds[1] ?? null, 'rollback_target' . $suffix);

        foreach (array_slice($artifactIds, 1) as $id) {
            if ($id !== null && $id !== $live) {
                $mark($id, 'rollback_target' . $suffix);
                break;
            }
        }
    }
}
