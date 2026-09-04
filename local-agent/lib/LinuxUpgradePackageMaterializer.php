<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Builds the Linux upgrade package from one exact commit in an isolated
 * repository. The fetch/archive/tree-hash-verify core lives in
 * GitExactCommitSubtreeMaterializer (shared with
 * ServerAgentPackageMaterializer, which reuses it for the agent/
 * subtree instead of deployment-local-agent/) — this class keeps its
 * exact original public contract and adds only what's specific to the
 * Local Agent's own package: the deployment-local-agent subtree name,
 * its required-files shape, and its LOCAL_AGENT_VERSION contract.
 */
final class LinuxUpgradePackageMaterializer
{
    private const SUBTREE = 'deployment-local-agent';

    private readonly DependencyRepositoryResolver $repositories;
    private readonly GitExactCommitSubtreeMaterializer $core;

    public function __construct(private readonly GitManager $git, ?DependencyRepositoryResolver $repositories = null)
    {
        $this->repositories = $repositories ?? new DependencyRepositoryResolver($git);
        $this->core = new GitExactCommitSubtreeMaterializer($git);
    }

    /**
     * @param array<string, array<string, mixed>> $projects
     * @param callable(string, string, string): mixed $use package root, package tree SHA, source commit
     */
    public function withPackage(
        array $projects,
        string $repositoryMapping,
        string $branch,
        string $expectedCommit,
        string $expectedVersion,
        callable $use,
    ): mixed {
        if ($repositoryMapping !== 'deployment-system') {
            throw new \RuntimeException('The Hub supplied an invalid Linux upgrade repository mapping.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $expectedVersion) !== 1) {
            throw new \RuntimeException('The Hub supplied an invalid expected Local Agent version.');
        }

        $mapping = $projects[$repositoryMapping] ?? null;
        if (!is_array($mapping)) {
            throw new \RuntimeException('The deployment-system repository mapping is unavailable for the Linux upgrade.');
        }
        $workspacePath = $mapping['source_workspace_path'] ?? $mapping['local_path'] ?? null;
        if (!is_string($workspacePath) || trim($workspacePath) === '') {
            throw new \RuntimeException('A validated deployment-system workspace transport is required for the Linux upgrade.');
        }

        return $this->core->withSubtree(
            $mapping,
            $repositoryMapping,
            $branch,
            $expectedCommit,
            self::SUBTREE,
            $this->repositories,
            function (string $packageRoot, string $treeHash, string $sourceCommit) use ($expectedVersion, $use): mixed {
                foreach (['upgrade-linux.sh', 'agent/agent.php', 'agent/heartbeat-watchdog.php'] as $required) {
                    if (!is_file($packageRoot . '/' . $required)) {
                        throw new \RuntimeException("The official Linux upgrade package is missing {$required}.");
                    }
                }
                if (!is_dir($packageRoot . '/agent/lib')) {
                    throw new \RuntimeException('The official Linux upgrade package library directory is missing.');
                }

                $source = (string) file_get_contents($packageRoot . '/agent/agent.php');
                if (preg_match("/const LOCAL_AGENT_VERSION = '([^']+)';/", $source, $matches) !== 1) {
                    throw new \RuntimeException('Could not read the exact-commit Linux upgrade package version.');
                }
                if (!hash_equals($expectedVersion, $matches[1])) {
                    throw new \RuntimeException("Upgrade package version {$matches[1]} does not match expected version {$expectedVersion}.");
                }

                return $use($packageRoot, $treeHash, $sourceCommit);
            },
        );
    }
}
