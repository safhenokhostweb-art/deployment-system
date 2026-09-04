<?php

namespace App\Services\Publish;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ServerAgentPackage;
use App\Support\Deployment\ServerAgentVersionResolver;

/**
 * Resolves the Server Agent upgrade authority from the Hub's last
 * verified deployment-system deployment — mirrors
 * LocalAgentUpgradeTargetResolver exactly (same trusted commit source,
 * same "self-hosted so this deployment's commit is the exact tree
 * currently running this Hub instance" reasoning), but resolves
 * agent_version from ServerAgentVersionResolver::canonical() instead of
 * LocalAgentVersionResolver::canonical() — the two are independent
 * version contracts for independent pieces of software.
 */
final class ServerAgentUpgradeTargetResolver
{
    public function __construct(
        private readonly ServerAgentPackageService $packages,
    ) {
    }

    /** @return array{commit_hash:string,branch:string,repository_mapping:string,agent_version:string,package:?ServerAgentPackage} */
    public function resolve(): array
    {
        $project = Project::query()
            ->where('project_key', 'deployment-system')
            ->where('is_active', true)
            ->first();
        abort_unless($project instanceof Project, 409, 'The deployment-system project is unavailable as the Server Agent upgrade authority.');

        $deployment = $project->latestSuccessfulDeployment();
        abort_unless($deployment instanceof Deployment, 409, 'No successful deployment-system deployment is available for an exact-commit Server Agent upgrade.');
        abort_unless(preg_match('/^[0-9a-f]{40}$/', (string) $deployment->commit_hash) === 1, 409, 'The authoritative Server Agent upgrade deployment has an invalid commit.');
        abort_unless(is_string($deployment->branch) && $deployment->branch !== '', 409, 'The authoritative Server Agent upgrade deployment has no branch.');

        $release = $deployment->release;
        abort_unless(
            $release instanceof Release
                && $release->isReady()
                && hash_equals((string) $deployment->commit_hash, (string) $release->commit_hash)
                && hash_equals((string) $deployment->branch, (string) $release->branch),
            409,
            'The authoritative Server Agent upgrade deployment is not backed by a matching ready Release.',
        );

        $commitHash = (string) $deployment->commit_hash;
        $branch = (string) $deployment->branch;

        return [
            'commit_hash' => $commitHash,
            'branch' => $branch,
            'repository_mapping' => 'deployment-system',
            'agent_version' => ServerAgentVersionResolver::canonical(),
            'package' => $this->packages->findAvailable($commitHash, 'deployment-system'),
        ];
    }
}
