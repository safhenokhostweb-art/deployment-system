<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Builds one exact-commit Server Agent (agent/) package — the same
 * fetch/archive/tree-hash-verify core LinuxUpgradePackageMaterializer
 * uses for its own deployment-local-agent/ package (see
 * GitExactCommitSubtreeMaterializer), reused here for the agent/
 * subtree instead. Deliberately its own thin class rather than a
 * parameter bolted onto LinuxUpgradePackageMaterializer's public
 * contract: Server Agent and Local Agent packages have independent
 * required-file shapes and version constants (AGENT_VERSION vs
 * LOCAL_AGENT_VERSION), and conflating them risks exactly the kind of
 * agent-identity confusion this whole self-upgrade contract must avoid.
 */
final class ServerAgentPackageMaterializer
{
    private const SUBTREE = 'agent';

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
            throw new \RuntimeException('The Hub supplied an invalid Server Agent package repository mapping.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $expectedVersion) !== 1) {
            throw new \RuntimeException('The Hub supplied an invalid expected Server Agent version.');
        }

        $mapping = $projects[$repositoryMapping] ?? null;
        if (!is_array($mapping)) {
            throw new \RuntimeException('The deployment-system repository mapping is unavailable for the Server Agent package.');
        }
        $workspacePath = $mapping['source_workspace_path'] ?? $mapping['local_path'] ?? null;
        if (!is_string($workspacePath) || trim($workspacePath) === '') {
            throw new \RuntimeException('A validated deployment-system workspace transport is required for the Server Agent package.');
        }

        return $this->core->withSubtree(
            $mapping,
            $repositoryMapping,
            $branch,
            $expectedCommit,
            self::SUBTREE,
            $this->repositories,
            function (string $packageRoot, string $treeHash, string $sourceCommit) use ($expectedVersion, $use): mixed {
                if (!is_file($packageRoot . '/agent.php')) {
                    throw new \RuntimeException('The Server Agent package is missing agent.php.');
                }
                if (!is_dir($packageRoot . '/lib')) {
                    throw new \RuntimeException('The Server Agent package library directory is missing.');
                }
                if (!is_file($packageRoot . '/lib/PipelineExecutor.php')) {
                    throw new \RuntimeException('The Server Agent package is missing lib/PipelineExecutor.php.');
                }

                $source = (string) file_get_contents($packageRoot . '/agent.php');
                if (preg_match("/const AGENT_VERSION = '([^']+)';/", $source, $matches) !== 1) {
                    throw new \RuntimeException('Could not read the exact-commit Server Agent package version.');
                }
                if (!hash_equals($expectedVersion, $matches[1])) {
                    throw new \RuntimeException("Server Agent package version {$matches[1]} does not match expected version {$expectedVersion}.");
                }

                return $use($packageRoot, $treeHash, $sourceCommit);
            },
        );
    }
}
