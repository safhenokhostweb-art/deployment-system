<?php

namespace App\Services\Publish;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Support\Deployment\LocalAgentVersionResolver;

/**
 * Resolves the Agent package authority from the Hub's last verified
 * deployment — and, since deployment-system is self-hosted, that
 * deployment's commit is by construction the exact tree currently running
 * this Hub instance. agent_version therefore comes from
 * LocalAgentVersionResolver::canonical() (this release's own packaged
 * agent.php), never from config('deployment.latest_local_agent_version'):
 * that value is a soft, operator-overridable notification setting, and
 * using it to gate/verify an actual upgrade let a stale override there
 * block the exact-commit upgrade path too, not just the dashboard badge
 * (see LocalAgentControlJobServiceTest's bootstrap-deadlock regression
 * guard).
 */
final class LocalAgentUpgradeTargetResolver
{
    /** @return array{commit_hash:string,branch:string,repository_mapping:string,release_id:int,agent_version:string} */
    public function resolve(): array
    {
        $project = Project::query()
            ->where('project_key', 'deployment-system')
            ->where('is_active', true)
            ->first();
        abort_unless($project instanceof Project, 409, 'The deployment-system project is unavailable as the Local Agent upgrade authority.');

        $deployment = $project->latestSuccessfulDeployment();
        abort_unless($deployment instanceof Deployment, 409, 'No successful deployment-system deployment is available for an exact-commit Local Agent upgrade.');
        abort_unless(preg_match('/^[0-9a-f]{40}$/', (string) $deployment->commit_hash) === 1, 409, 'The authoritative Local Agent upgrade deployment has an invalid commit.');
        abort_unless(is_string($deployment->branch) && $deployment->branch !== '', 409, 'The authoritative Local Agent upgrade deployment has no branch.');

        $release = $deployment->release;
        abort_unless(
            $release instanceof Release
                && $release->isReady()
                && hash_equals((string) $deployment->commit_hash, (string) $release->commit_hash)
                && hash_equals((string) $deployment->branch, (string) $release->branch),
            409,
            'The authoritative Local Agent upgrade deployment is not backed by a matching ready Release.',
        );

        return [
            'commit_hash' => (string) $deployment->commit_hash,
            'branch' => (string) $deployment->branch,
            'repository_mapping' => 'deployment-system',
            'release_id' => $release->id,
            'agent_version' => LocalAgentVersionResolver::canonical(),
        ];
    }
}
