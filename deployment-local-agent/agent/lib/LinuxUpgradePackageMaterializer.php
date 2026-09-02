<?php

declare(strict_types=1);

namespace LocalAgent;

/** Builds the Linux upgrade package from one exact commit in an isolated repository. */
final class LinuxUpgradePackageMaterializer
{
    private readonly DependencyRepositoryResolver $repositories;

    public function __construct(private readonly GitManager $git, ?DependencyRepositoryResolver $repositories = null)
    {
        $this->repositories = $repositories ?? new DependencyRepositoryResolver($git);
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
        if (preg_match('/^[0-9a-f]{40}$/', $expectedCommit) !== 1) {
            throw new \RuntimeException('The Hub supplied an invalid expected Linux upgrade commit.');
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
        $transport = $this->repositories->resolve($mapping, $repositoryMapping, $branch);
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/local-agent-upgrade-' . bin2hex(random_bytes(8));
        $repository = $root . '/repository';
        $extracted = $root . '/extracted';
        if (!mkdir($repository, 0700, true) || !mkdir($extracted, 0700, true)) {
            throw new \RuntimeException('Could not create an isolated Linux upgrade workspace.');
        }

        try {
            $this->run(['git', 'init'], $repository, 30, 'initializing the package repository');
            $this->run(['git', 'config', 'core.autocrlf', 'false'], $repository, 30, 'disabling host line-ending conversion');
            $this->run(['git', 'config', 'core.eol', 'lf'], $repository, 30, 'fixing deterministic package line endings');
            $this->run(['git', 'check-ref-format', '--branch', $branch], $repository, 30, 'validating the allowed package branch');
            $this->run(['git', 'remote', 'add', 'origin', $transport], $repository, 30, 'configuring the validated package transport');
            $this->run(['git', 'fetch', '--no-tags', '--prune', 'origin', 'refs/heads/' . $branch], $repository, $this->git->timeoutFor('fetch'), 'fetching the allowed package branch');
            $this->run(['git', 'cat-file', '-e', $expectedCommit . '^{commit}'], $repository, 30, 'validating the requested package commit');
            $this->run(['git', 'merge-base', '--is-ancestor', $expectedCommit, 'FETCH_HEAD'], $repository, 30, 'validating package commit reachability');

            $treeHash = strtolower(trim($this->run(['git', 'rev-parse', $expectedCommit . ':deployment-local-agent'], $repository, 30, 'resolving the package tree')));
            if (preg_match('/^[0-9a-f]{40}$/', $treeHash) !== 1) {
                throw new \RuntimeException('The exact commit has no valid deployment-local-agent package tree.');
            }

            $archive = $root . '/deployment-local-agent.zip';
            $this->run(['git', 'archive', '--format=zip', '-o', $archive, $expectedCommit, '--', 'deployment-local-agent'], $repository, 300, 'archiving the exact package commit');
            SafeFilesystem::extractZipSafely($archive, $extracted);

            $packageRoot = realpath($extracted . '/deployment-local-agent');
            $extractedRoot = realpath($extracted);
            if ($packageRoot === false || $extractedRoot === false || !str_starts_with($packageRoot, $extractedRoot . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('The exact commit does not contain the official Linux upgrade package.');
            }
            $this->verifyExtractedTree($repository, $expectedCommit, $packageRoot);

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

            return $use($packageRoot, $treeHash, $expectedCommit);
        } finally {
            if (is_dir($root)) {
                SafeFilesystem::recursiveDelete($root);
            }
        }
    }

    private function verifyExtractedTree(string $repository, string $commit, string $packageRoot): void
    {
        $raw = $this->run(['git', 'ls-tree', '-r', '-z', $commit, '--', 'deployment-local-agent'], $repository, 60, 'reading the exact package tree');
        $expected = [];
        foreach (explode("\0", rtrim($raw, "\0")) as $entry) {
            if (preg_match('/^(100644|100755) blob ([0-9a-f]{40})\tdeployment-local-agent\/(.+)$/', $entry, $matches) !== 1) {
                throw new \RuntimeException('The exact Linux upgrade package contains an unsupported Git tree entry.');
            }
            $relative = str_replace('\\', '/', $matches[3]);
            $path = $packageRoot . '/' . $relative;
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException("The extracted Linux upgrade package entry is invalid: {$relative}.");
            }
            $contents = file_get_contents($path);
            $blob = is_string($contents) ? sha1('blob ' . strlen($contents) . "\0" . $contents) : '';
            if (!hash_equals($matches[2], $blob)) {
                throw new \RuntimeException("The extracted Linux upgrade package does not match the requested commit: {$relative}.");
            }
            $expected[] = $relative;
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new \RuntimeException('The extracted Linux upgrade package contains an unsupported filesystem entry.');
            }
            $actual[] = str_replace('\\', '/', substr($item->getPathname(), strlen($packageRoot) + 1));
        }
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new \RuntimeException('The extracted Linux upgrade package file set does not match the requested commit.');
        }
    }

    /** @param array<int, string> $command */
    private function run(array $command, string $cwd, int $timeout, string $operation): string
    {
        $result = $this->git->run($command, $cwd, $timeout);
        if ($result['exitCode'] !== 0) {
            $timedOut = ($result['timedOut'] ?? false) === true ? 'true' : 'false';
            throw new \RuntimeException("Linux upgrade package failed while {$operation} [exit_code={$result['exitCode']}, timed_out={$timedOut}].");
        }

        return (string) $result['output'];
    }
}
