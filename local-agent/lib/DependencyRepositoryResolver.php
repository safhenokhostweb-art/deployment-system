<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Resolves a dependency repository mapping to the credential transport
 * already verified for that mapped project's persistent source workspace.
 * The workspace's files and HEAD are never dependency inputs; only its
 * configured remote URL is read, and that URL must identify the same
 * repository the Hub authorized.
 */
final class DependencyRepositoryResolver
{
    public function __construct(private readonly GitManager $git)
    {
    }

    /** @param array<string, mixed> $mapping */
    public function resolve(array $mapping, string $mappingName, string $branch): string
    {
        $configuredUrl = $mapping['repository_url'] ?? null;
        if (!is_string($configuredUrl) || trim($configuredUrl) === '') {
            throw $this->failure($mappingName, $branch, 'configured_url_missing');
        }

        $workspacePath = $mapping['source_workspace_path'] ?? $mapping['local_path'] ?? null;
        if ($workspacePath === null) {
            return $configuredUrl;
        }
        if (!is_string($workspacePath) || $workspacePath === '' || SafetyChecker::productionSourcePathForbidden($workspacePath)) {
            throw $this->failure($mappingName, $branch, 'workspace_path_invalid');
        }
        if (isset($mapping['branch']) && (!is_string($mapping['branch']) || !hash_equals($mapping['branch'], $branch))) {
            throw $this->failure($mappingName, $branch, 'workspace_branch_mismatch');
        }

        $remoteName = $mapping['remote_name'] ?? 'origin';
        if (!is_string($remoteName) || preg_match('/^[A-Za-z0-9._-]+$/', $remoteName) !== 1) {
            throw $this->failure($mappingName, $branch, 'remote_name_invalid');
        }

        $inside = $this->git->run(['git', 'rev-parse', '--is-inside-work-tree'], $workspacePath, $this->git->timeoutFor('status'));
        if ($inside['exitCode'] !== 0 || trim($inside['output']) !== 'true') {
            throw $this->failure($mappingName, $branch, 'workspace_not_git');
        }

        $remote = $this->git->run(['git', 'config', '--get', 'remote.' . $remoteName . '.url'], $workspacePath, $this->git->timeoutFor('status'));
        $workspaceUrl = trim($remote['output']);
        if ($remote['exitCode'] !== 0 || $workspaceUrl === '') {
            throw $this->failure($mappingName, $branch, 'workspace_remote_missing');
        }
        if (!hash_equals(self::normalizeRepository($configuredUrl), self::normalizeRepository($workspaceUrl))) {
            throw $this->failure($mappingName, $branch, 'repository_identity_mismatch');
        }

        return $workspaceUrl;
    }

    private static function normalizeRepository(string $repository): string
    {
        $normalized = trim(str_replace('\\', '/', $repository));
        if (preg_match('/^git@([^:]+):(.+)$/i', $normalized, $matches) === 1) {
            $host = strtolower($matches[1]);
            if ($host === 'github.com' || $host === 'github-safhe') {
                return 'github.com/' . strtolower(preg_replace('#\.git/?$#i', '', $matches[2]) ?? $matches[2]);
            }
            $normalized = $host . '/' . $matches[2];
        }

        $parts = parse_url($normalized);
        if (is_array($parts) && in_array(strtolower((string) ($parts['host'] ?? '')), ['github.com', 'ssh.github.com'], true)) {
            $path = ltrim((string) ($parts['path'] ?? ''), '/');

            return 'github.com/' . strtolower(preg_replace('#\.git/?$#i', '', $path) ?? $path);
        }

        return preg_replace('#\.git/?$#i', '', rtrim($normalized, '/')) ?? $normalized;
    }

    private function failure(string $mappingName, string $branch, string $category): LocalBuildFailedException
    {
        return new LocalBuildFailedException(
            'local_workspace_prepare_failed',
            "Dependency repository mapping could not resolve a trusted fetch transport [mapping={$mappingName}, branch={$branch}, category={$category}].",
        );
    }
}
