<?php

namespace App\Services\Publish;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\Release;
use App\Services\Releases\ReleaseRecoveryEligibility;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256): resolves the
 * authoritative upstream commit a pinned Composer path dependency should
 * point at. The upstream project's current configured-branch Release is
 * the target even before deployment. Any newer Release row supersedes an
 * older one, and the current one must itself be ready with a matching
 * available Artifact; resolution never skips backward to stale success.
 *
 * Deliberately never reads origin/main HEAD, a mutable persistent
 * workspace's checked-out HEAD, or any GitHub-specific API — this is a
 * fully Hub-owned, DB-backed resolution that fails closed (409) the
 * moment no eligible target exists, so a dependency can never be pinned
 * to an unverified or in-progress upstream state.
 */
final class SaasDependencyTargetResolver
{
    public function __construct(
        private readonly ReleaseRecoveryEligibility $releaseEligibility,
    ) {}

    /** @return array{commit_sha: string, branch: string, repository_mapping: string, release_id: string, artifact_id: string, selection_rule: string} */
    public function resolve(string $repositoryMapping, string $branch): array
    {
        $project = Project::query()
            ->where('project_key', $repositoryMapping)
            ->where('is_active', true)
            ->first();
        abort_unless($project instanceof Project, 409, "The '{$repositoryMapping}' project is unavailable as a dependency pin authority.");

        abort_unless(hash_equals((string) $project->branch, $branch), 409, "The '{$repositoryMapping}' project does not currently track the expected branch ('{$branch}').");

        $release = $this->releaseEligibility->currentReleaseFor($project);
        abort_unless(
            $release instanceof Release
                && $release->isReady()
                && preg_match('/^[0-9a-f]{40}$/', (string) $release->commit_hash) === 1
                && hash_equals($branch, (string) $release->branch),
            409,
            "The current '{$repositoryMapping}' Release on '{$branch}' is not ready to be pinned.",
        );

        $artifact = $release->artifacts()
            ->where('project_id', $project->id)
            ->where('commit_hash', $release->commit_hash)
            ->where('branch', $release->branch)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->latest('id')
            ->first();
        abort_unless($artifact instanceof Artifact, 409, "The current ready '{$repositoryMapping}' Release has no matching available Artifact.");

        return [
            'commit_sha' => (string) $release->commit_hash,
            'branch' => (string) $release->branch,
            'repository_mapping' => $repositoryMapping,
            'release_id' => (string) $release->public_id,
            'artifact_id' => (string) $artifact->public_id,
            'selection_rule' => 'current_project_branch_release_v1',
        ];
    }
}
