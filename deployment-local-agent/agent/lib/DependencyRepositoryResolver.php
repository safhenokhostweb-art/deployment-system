<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Resolves an authorized repository mapping to a validated fetch transport.
 * A persistent workspace contributes identity/transport evidence only: its
 * files, refs, and HEAD are never materialization inputs. GitHub mappings use
 * the deployment agent's canonical SSH endpoint after the stored remote and
 * Hub-authorized repository identities have been proven equal.
 */
final class DependencyRepositoryResolver
{
    public function __construct(private readonly GitManager $git)
    {
    }

    /** @param array<string, mixed> $mapping */
    public function resolve(array $mapping, string $mappingName, string $branch): string
    {
        return $this->resolveTransport($mapping, $mappingName, $branch)['url'];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array{url: string, identity: string, category: string}
     */
    public function resolveTransport(array $mapping, string $mappingName, string $branch): array
    {
        $configuredUrl = $mapping['repository_url'] ?? null;
        if (!is_string($configuredUrl) || trim($configuredUrl) === '') {
            throw $this->failure($mappingName, $branch, 'configured_url_missing');
        }

        $configuredIdentity = self::normalizeRepository($configuredUrl);

        $workspacePath = $mapping['source_workspace_path'] ?? $mapping['local_path'] ?? null;
        if ($workspacePath === null) {
            return self::transport($configuredUrl, $configuredIdentity);
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
        if (!hash_equals($configuredIdentity, self::normalizeRepository($workspaceUrl))) {
            throw $this->failure($mappingName, $branch, 'repository_identity_mismatch');
        }

        $canonicalSsh = self::canonicalGithubSsh($configuredIdentity);

        return self::transport($canonicalSsh ?? $workspaceUrl, $configuredIdentity);
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

        // Provider-neutral URL identity deliberately excludes user-info,
        // query strings, and fragments so credentials or signed parameters
        // can neither influence repository identity nor enter diagnostics.
        if (is_array($parts) && (string) ($parts['host'] ?? '') !== '') {
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = ltrim((string) ($parts['path'] ?? ''), '/');

            return $host . $port . '/' . (preg_replace('#\.git/?$#i', '', $path) ?? $path);
        }

        return preg_replace('#\.git/?$#i', '', rtrim($normalized, '/')) ?? $normalized;
    }

    private static function canonicalGithubSsh(string $identity): ?string
    {
        if (preg_match('#^github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $identity, $matches) !== 1) {
            return null;
        }

        return "ssh://git@ssh.github.com:443/{$matches[1]}/{$matches[2]}.git";
    }

    /** @return array{url: string, identity: string, category: string} */
    private static function transport(string $url, string $identity): array
    {
        $lower = strtolower(trim($url));
        $category = preg_match('#^(?:ssh://|git@[^:]+:)#', $lower) === 1
            ? 'ssh'
            : (preg_match('#^https?://#', $lower) === 1 ? 'https' : 'other');

        return ['url' => $url, 'identity' => $identity, 'category' => $category];
    }

    public static function stderrCategory(string $output, bool $timedOut): string
    {
        if ($timedOut) return 'timeout';
        $output = strtolower($output);
        if (preg_match('/authentication failed|permission denied|could not read username|publickey/', $output) === 1) return 'authentication';
        if (str_contains($output, 'host key verification failed')) return 'host_key_verification';
        if (preg_match('/repository .* not found|does not appear to be a git repository/', $output) === 1) return 'repository_not_found';
        if (preg_match('/could not resolve host|failed to connect|connection (?:timed out|reset|refused)|network is unreachable/', $output) === 1) return 'network';
        if (str_contains($output, "couldn't find remote ref")) return 'remote_ref_missing';
        if (str_contains($output, 'failed to start process')) return 'process_start';
        return 'git_failure';
    }

    private function failure(string $mappingName, string $branch, string $category): LocalBuildFailedException
    {
        return new LocalBuildFailedException(
            'local_workspace_prepare_failed',
            "Dependency repository mapping could not resolve a trusted fetch transport [mapping={$mappingName}, branch={$branch}, category={$category}].",
        );
    }
}
