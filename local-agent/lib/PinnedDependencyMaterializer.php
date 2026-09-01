<?php

declare(strict_types=1);

namespace LocalAgent;

/** Materializes allowlisted Composer path packages from an exact reachable Git commit. */
final class PinnedDependencyMaterializer
{
    private readonly DependencyRepositoryResolver $repositories;

    public function __construct(private readonly GitManager $git, ?DependencyRepositoryResolver $repositories = null)
    {
        $this->repositories = $repositories ?? new DependencyRepositoryResolver($git);
    }

    /**
     * @param array<int, array<string, mixed>> $dependencies
     * @param array<int, array<string, mixed>> $allowlist
     * @param array<string, array<string, mixed>> $repositoryMappings
     * @return array<int, array<string, string>>
     */
    public function materialize(BuildWorkspace $workspace, array $dependencies, array $allowlist, array $repositoryMappings, ?callable $onTick = null): array
    {
        $locks = [];
        $seenDestinations = [];
        $checkouts = [];

        foreach ($dependencies as $index => $dependency) {
            $normalized = $this->validateRequest($dependency, $allowlist, $repositoryMappings);
            $destination = $normalized['destination_path'];
            if (isset($seenDestinations[$destination])) {
                throw $this->failure('Dependency contract contains a duplicate destination path.');
            }
            $seenDestinations[$destination] = true;

            $target = $workspace->resolvePathDependencyTarget($destination);
            if (file_exists($target) || is_link($target)) {
                throw $this->failure('Dependency destination already exists in the isolated workspace.');
            }

            $mapping = $repositoryMappings[$normalized['repository_mapping']];
            $checkoutKey = implode("\0", [$normalized['repository_mapping'], $normalized['branch'], $normalized['commit_sha']]);
            if (!isset($checkouts[$checkoutKey])) {
                $checkout = $workspace->root() . '/dependency-checkouts/' . count($checkouts) . '-' . bin2hex(random_bytes(6));
                if (!mkdir($checkout, 0700, true) && !is_dir($checkout)) {
                    throw $this->failure('Could not create an isolated dependency checkout.');
                }

                $repositoryUrl = $this->repositories->resolve($mapping, $normalized['repository_mapping'], $normalized['branch']);
                $context = ['mapping' => $normalized['repository_mapping'], 'branch' => $normalized['branch']];
                $this->run(['git', 'init'], $checkout, 30, 'initializing dependency checkout', $onTick, $context);
                $this->run(['git', 'remote', 'add', 'origin', $repositoryUrl], $checkout, 30, 'configuring dependency mapping', $onTick, $context);
                $this->run(['git', 'fetch', '--no-tags', '--prune', 'origin', $normalized['branch']], $checkout, $this->git->timeoutFor('fetch'), 'fetching dependency branch', $onTick, $context);
                $this->run(['git', 'cat-file', '-e', $normalized['commit_sha'] . '^{commit}'], $checkout, 30, 'validating dependency commit', $onTick, $context);
                $this->run(['git', 'merge-base', '--is-ancestor', $normalized['commit_sha'], 'FETCH_HEAD'], $checkout, 30, 'validating dependency reachability', $onTick, $context);
                $checkouts[$checkoutKey] = $checkout;
            }
            $checkout = $checkouts[$checkoutKey];

            $tree = $this->git->run(['git', 'rev-parse', $normalized['commit_sha'] . ':' . $normalized['source_path']], $checkout, 30);
            $treeHash = strtolower(trim($tree['output']));
            if ($tree['exitCode'] !== 0 || preg_match('/^[0-9a-f]{40}$/', $treeHash) !== 1) {
                throw $this->failure('Allowlisted dependency source path is not a Git tree at the requested commit.');
            }
            $type = $this->git->run(['git', 'cat-file', '-t', $treeHash], $checkout, 30);
            if ($type['exitCode'] !== 0 || trim($type['output']) !== 'tree') {
                throw $this->failure('Allowlisted dependency source path is not a directory tree.');
            }

            $entries = $this->git->run(['git', 'ls-tree', '-r', '-z', $normalized['commit_sha'], '--', $normalized['source_path']], $checkout, 30);
            if ($entries['exitCode'] !== 0 || preg_match('/(^|\x00)(120000|160000) /', $entries['output']) === 1) {
                throw $this->failure('Dependency tree contains a symlink or unresolved submodule.');
            }

            $archive = $workspace->root() . '/dependency-' . $index . '-' . bin2hex(random_bytes(6)) . '.zip';
            try {
                $this->run(['git', 'archive', '--format=zip', '-o', $archive, $normalized['commit_sha'] . ':' . $normalized['source_path']], $checkout, 300, 'archiving dependency tree', $onTick);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                    throw $this->failure('Could not create the dependency destination.');
                }
                if (!mkdir($target, 0700, true) && !is_dir($target)) {
                    throw $this->failure('Could not create the dependency package directory.');
                }
                SafeFilesystem::extractZipSafely($archive, $target);
            } finally {
                @unlink($archive);
            }

            self::assertOrdinaryTree($target);
            $composer = json_decode((string) @file_get_contents($target . '/composer.json'), true);
            if (!is_array($composer) || !hash_equals($normalized['package_name'], (string) ($composer['name'] ?? ''))) {
                throw $this->failure('Materialized package name does not match the allowlisted Composer package.');
            }

            $locks[] = [
                'project_id' => $normalized['project_id'],
                'repository_mapping' => $normalized['repository_mapping'],
                'branch' => $normalized['branch'],
                'commit_sha' => $normalized['commit_sha'],
                'package_name' => $normalized['package_name'],
                'source_path' => $normalized['source_path'],
                'destination_path' => $destination,
                'artifact_path' => 'vendor/' . $normalized['package_name'],
                'tree_hash' => $treeHash,
                'checksum_sha256' => self::contentChecksum($target),
            ];
        }

        return $locks;
    }

    /** @param array<string, mixed> $request @param array<int, array<string, mixed>> $allowlist @param array<string, array<string, mixed>> $mappings */
    private function validateRequest(array $request, array $allowlist, array $mappings): array
    {
        $fields = ['project_id', 'repository_mapping', 'branch', 'commit_sha', 'package_name', 'source_path', 'destination_path'];
        foreach ($fields as $field) {
            if (!is_string($request[$field] ?? null) || trim($request[$field]) === '') {
                throw $this->failure("Dependency contract field '{$field}' is missing or invalid.");
            }
        }
        $request = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $request);
        if (preg_match('/^[0-9a-f]{40}$/', $request['commit_sha']) !== 1) {
            throw $this->failure('Dependency commit must be a full lowercase 40-character Git SHA.');
        }
        if (!$this->validRef($request['branch']) || !$this->validIdentifier($request['project_id']) || !$this->validIdentifier($request['repository_mapping'])) {
            throw $this->failure('Dependency project, mapping, or branch contains unsupported characters.');
        }
        if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $request['package_name']) !== 1) {
            throw $this->failure('Dependency package name is invalid.');
        }
        foreach (['source_path', 'destination_path'] as $pathField) {
            if (!$this->safeRelativePath($request[$pathField], $pathField === 'destination_path')) {
                throw $this->failure("Dependency {$pathField} is not a safe relative path.");
            }
        }
        if (!isset($mappings[$request['repository_mapping']]) || !is_string($mappings[$request['repository_mapping']]['repository_url'] ?? null)) {
            throw $this->failure('Dependency repository mapping is not configured on this Local Agent.');
        }

        $matchFields = ['project_id', 'repository_mapping', 'branch', 'package_name', 'source_path', 'destination_path'];
        $allowed = false;
        foreach ($allowlist as $candidate) {
            $matches = is_array($candidate);
            foreach ($matchFields as $field) {
                $matches = $matches && isset($candidate[$field]) && hash_equals((string) $candidate[$field], $request[$field]);
            }
            $allowed = $allowed || $matches;
        }
        if (!$allowed) {
            throw $this->failure('Dependency repository or package path is not allowlisted for this consumer project.');
        }

        return $request;
    }

    /** @param array{mapping?: string, branch?: string} $context */
    private function run(array $command, string $cwd, int $timeout, string $operation, ?callable $onTick, array $context = []): void
    {
        $result = $this->git->run($command, $cwd, $timeout, $onTick);
        if ($result['exitCode'] !== 0) {
            $mapping = $context['mapping'] ?? 'unknown';
            $branch = $context['branch'] ?? 'unknown';
            $timedOut = ($result['timedOut'] ?? false) === true ? 'true' : 'false';
            $category = self::stderrCategory((string) ($result['output'] ?? ''), $timedOut === 'true');
            throw $this->failure("Dependency materialization failed while {$operation} [mapping={$mapping}, branch={$branch}, exit_code={$result['exitCode']}, timed_out={$timedOut}, stderr_category={$category}].");
        }
    }

    private static function stderrCategory(string $output, bool $timedOut): string
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

    private function safeRelativePath(string $path, bool $allowParentSegments = false): bool
    {
        $normalized = str_replace('\\', '/', $path);
        return $normalized !== '' && !str_starts_with($normalized, '/') && preg_match('#^[A-Za-z]:/#', $normalized) !== 1
            && ($allowParentSegments || !in_array('..', explode('/', $normalized), true)) && !str_contains($normalized, "\0")
            && !str_contains($normalized, '*') && !str_contains($normalized, '?');
    }

    private function validIdentifier(string $value): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1; }
    private function validRef(string $value): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $value) === 1 && !str_contains($value, '..') && !str_contains($value, '@{'); }
    private function failure(string $message): LocalBuildFailedException { return new LocalBuildFailedException('local_workspace_prepare_failed', $message); }

    public static function assertOrdinaryTree(string $root): void
    {
        if (!is_dir($root) || SafeFilesystem::isLinkLikeEntry($root)) {
            throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Materialized dependency is not an ordinary directory.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || SafeFilesystem::isLinkLikeEntry($entry->getPathname())) {
                throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Materialized dependency contains a symlink or reparse point.');
            }
        }
    }

    public static function contentChecksum(string $root): string
    {
        self::assertOrdinaryTree($root);
        $files = [];
        $realRoot = realpath($root);
        SafeFilesystem::collectFilesSafely((string) $realRoot, (string) $realRoot, '', $files);
        ksort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $absolute) {
            $bytes = file_get_contents($absolute);
            if ($bytes === false) {
                throw new LocalBuildFailedException('local_workspace_prepare_failed', 'Could not checksum materialized dependency content.');
            }
            hash_update($hash, $relative . "\0" . strlen($bytes) . "\0" . $bytes);
        }
        return hash_final($hash);
    }
}
