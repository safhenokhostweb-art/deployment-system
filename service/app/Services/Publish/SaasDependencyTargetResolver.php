<?php

namespace App\Services\Publish;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256): resolves the
 * authoritative upstream commit a pinned Composer path dependency should
 * point at — mirrors ServerAgentUpgradeTargetResolver's exact reasoning
 * (the project's last successful Deployment, backed by a matching ready
 * Release, is the one trusted "current production state" source) applied
 * to an arbitrary upstream project instead of deployment-system's own
 * Server Agent runtime.
 *
 * Deliberately never reads origin/main HEAD, a mutable persistent
 * workspace's checked-out HEAD, or any GitHub-specific API — this is a
 * fully Hub-owned, DB-backed resolution that fails closed (409) the
 * moment no eligible target exists, so a dependency can never be pinned
 * to an unverified or in-progress upstream state.
 */
final class SaasDependencyTargetResolver
{
    /** @return array{commit_sha: string, branch: string, repository_mapping: string} */
    public function resolve(string $repositoryMapping, string $branch): array
    {
        $project = Project::query()
            ->where('project_key', $repositoryMapping)
            ->where('is_active', true)
            ->first();
        abort_unless($project instanceof Project, 409, "The '{$repositoryMapping}' project is unavailable as a dependency pin authority.");

        $deployment = $project->latestSuccessfulDeployment();
        abort_unless($deployment instanceof Deployment, 409, "No successful '{$repositoryMapping}' deployment is available to pin a dependency commit.");
        abort_unless(preg_match('/^[0-9a-f]{40}$/', (string) $deployment->commit_hash) === 1, 409, "The authoritative '{$repositoryMapping}' deployment has an invalid commit.");
        abort_unless(is_string($deployment->branch) && hash_equals($branch, $deployment->branch), 409, "The authoritative '{$repositoryMapping}' deployment is not on the expected branch ('{$branch}').");

        $release = $deployment->release;
        abort_unless(
            $release instanceof Release
                && $release->isReady()
                && hash_equals((string) $deployment->commit_hash, (string) $release->commit_hash)
                && hash_equals((string) $deployment->branch, (string) $release->branch),
            409,
            "The authoritative '{$repositoryMapping}' deployment is not backed by a matching ready Release.",
        );

        return [
            'commit_sha' => (string) $deployment->commit_hash,
            'branch' => (string) $deployment->branch,
            'repository_mapping' => $repositoryMapping,
        ];
    }
}
