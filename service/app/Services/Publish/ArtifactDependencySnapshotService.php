<?php

namespace App\Services\Publish;

use App\Models\PublishJob;
use App\Models\Release;
use App\Support\Deployment\ArtifactDependencyPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256): owns the ONE
 * moment a Release's pinned upstream dependency commit gets chosen — the
 * first time its dependency contract is actually needed — and freezes the
 * result onto the Release's own `dependency_snapshot` column. Every later
 * poll, retry, or rollback of that exact Release replays the identical,
 * already-verified contract, even after the upstream project ships a
 * newer Release. This is what makes exact-commit evidence and rollback
 * reproducible: a Release's dependency pin is a fact recorded once, never
 * a live query re-evaluated on every request.
 *
 * Never coupled to deployment approval — this runs at Artifact-build time,
 * strictly before NO CONFIRM -> NO DEPLOYMENT's own gate, and never
 * creates, approves, or queues a Deployment itself.
 */
final class ArtifactDependencySnapshotService
{
    public function __construct(
        private readonly SaasDependencyTargetResolver $targets,
    ) {}

    /**
     * Resolves and freezes this Release's dependency snapshot on first
     * need, then always replays the frozen value afterward. Returns []
     * when dependency pinning is not required for this Release's project
     * (the existing rollout switch/legacy-compatible behavior).
     *
     * @return array<string, mixed>
     */
    public function resolveForRelease(Release $release): array
    {
        $projectKey = $release->project->project_key;
        $template = ArtifactDependencyPolicy::template($projectKey);
        if ($template['required'] !== true) {
            return [];
        }

        if (self::isFrozen($release)) {
            return $release->dependency_snapshot;
        }

        return DB::transaction(function () use ($release, $projectKey): array {
            /** @var Release $locked */
            $locked = Release::query()->lockForUpdate()->findOrFail($release->id);

            // A concurrent request may have resolved and frozen this exact
            // Release while this request waited for the lock — reuse it
            // rather than resolve (and potentially disagree) a second time.
            if (self::isFrozen($locked)) {
                return $locked->dependency_snapshot;
            }

            $contract = $this->resolveContractFor($projectKey);
            $locked->forceFill(['dependency_snapshot' => $contract])->save();

            return $contract;
        });
    }

    /**
     * ACTION_PUBLISH builds a persistent Artifact before its pushed commit
     * has a Release. Freeze on the PublishJob so a lost response/retry
     * cannot rebuild that Artifact against a newer dependency target.
     *
     * @return array<string, mixed>
     */
    public function resolveForPublishJob(PublishJob $job): array
    {
        $projectKey = $job->project->project_key;
        $template = ArtifactDependencyPolicy::template($projectKey);
        if ($template['required'] !== true) {
            return [];
        }

        if (is_array($job->dependency_snapshot) && $job->dependency_snapshot !== []) {
            return $job->dependency_snapshot;
        }

        return DB::transaction(function () use ($job, $projectKey): array {
            /** @var PublishJob $locked */
            $locked = PublishJob::query()->lockForUpdate()->findOrFail($job->id);
            if (is_array($locked->dependency_snapshot) && $locked->dependency_snapshot !== []) {
                return $locked->dependency_snapshot;
            }

            $contract = $this->resolveContractFor($projectKey);
            $locked->forceFill(['dependency_snapshot' => $contract])->save();

            return $contract;
        });
    }

    private static function isFrozen(Release $release): bool
    {
        return is_array($release->dependency_snapshot) && $release->dependency_snapshot !== [];
    }

    /** @return array<string, mixed> */
    private function resolveContractFor(string $projectKey): array
    {
        $template = ArtifactDependencyPolicy::template($projectKey);

        $mappings = array_values(array_unique(array_column($template['dependencies'], 'repository_mapping')));
        $branches = array_values(array_unique(array_column($template['dependencies'], 'branch')));
        if (count($mappings) !== 1 || count($branches) !== 1) {
            throw new \LogicException("Artifact dependency policy for '{$projectKey}' must pin exactly one upstream repository/branch per project.");
        }

        $target = $this->targets->resolve($mappings[0], $branches[0]);

        $contract = ArtifactDependencyPolicy::resolveContract($projectKey, $target['commit_sha']);
        $contract['dependency_target'] = $target;

        return $contract;
    }
}
