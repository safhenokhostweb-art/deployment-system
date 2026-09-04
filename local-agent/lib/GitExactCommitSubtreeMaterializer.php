<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * The reusable, security-critical core of "materialize one exact-commit
 * subtree from a trusted repository into an isolated directory, and
 * prove every extracted file's content matches that exact commit's git
 * tree" — extracted from LinuxUpgradePackageMaterializer (behavior-
 * preserving: that class's own public contract and tests are
 * untouched) so ServerAgentPackageMaterializer can reuse the identical
 * fetch/archive/verify logic for a different subtree
 * (agent/ instead of deployment-local-agent/) without a second,
 * independently-maintained copy of this logic.
 */
final class GitExactCommitSubtreeMaterializer
{
    public function __construct(private readonly GitManager $git)
    {
    }

    /**
     * @param array<string, mixed> $mapping resolved repository mapping (source_workspace_path/local_path + repository_url)
     * @param callable(string, string, string): mixed $use ($subtreeRoot, $treeHash, $expectedCommit) -> mixed
     */
    public function withSubtree(
        array $mapping,
        string $repositoryMappingName,
        string $branch,
        string $expectedCommit,
        string $subtree,
        DependencyRepositoryResolver $repositories,
        callable $use,
    ): mixed {
        if (preg_match('/^[0-9a-f]{40}$/', $expectedCommit) !== 1) {
            throw new \RuntimeException('The Hub supplied an invalid expected exact-commit package commit.');
        }
        if (preg_match('#^[A-Za-z0-9._-]+$#', $subtree) !== 1) {
            throw new \RuntimeException('An invalid exact-commit package subtree was requested.');
        }

        $transport = $repositories->resolve($mapping, $repositoryMappingName, $branch);
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/local-agent-package-' . bin2hex(random_bytes(8));
        $repository = $root . '/repository';
        $extracted = $root . '/extracted';
        if (!mkdir($repository, 0700, true) || !mkdir($extracted, 0700, true)) {
            throw new \RuntimeException('Could not create an isolated exact-commit package workspace.');
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

            $treeHash = strtolower(trim($this->run(['git', 'rev-parse', $expectedCommit . ':' . $subtree], $repository, 30, 'resolving the package tree')));
            if (preg_match('/^[0-9a-f]{40}$/', $treeHash) !== 1) {
                throw new \RuntimeException("The exact commit has no valid {$subtree} package tree.");
            }

            $archive = $root . '/' . $subtree . '.zip';
            $this->run(['git', 'archive', '--format=zip', '-o', $archive, $expectedCommit, '--', $subtree], $repository, 300, 'archiving the exact package commit');
            SafeFilesystem::extractZipSafely($archive, $extracted);

            $subtreeRoot = realpath($extracted . '/' . $subtree);
            $extractedRoot = realpath($extracted);
            if ($subtreeRoot === false || $extractedRoot === false || !str_starts_with($subtreeRoot, $extractedRoot . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException("The exact commit does not contain the expected {$subtree} package.");
            }
            $this->verifyExtractedTree($repository, $expectedCommit, $subtree, $subtreeRoot);

            return $use($subtreeRoot, $treeHash, $expectedCommit);
        } finally {
            if (is_dir($root)) {
                SafeFilesystem::recursiveDelete($root);
            }
        }
    }

    private function verifyExtractedTree(string $repository, string $commit, string $subtree, string $subtreeRoot): void
    {
        $raw = $this->run(['git', 'ls-tree', '-r', '-z', $commit, '--', $subtree], $repository, 60, 'reading the exact package tree');
        $expected = [];
        foreach (explode("\0", rtrim($raw, "\0")) as $entry) {
            if (preg_match('#^(100644|100755) blob ([0-9a-f]{40})\t' . preg_quote($subtree, '#') . '/(.+)$#', $entry, $matches) !== 1) {
                throw new \RuntimeException('The exact package contains an unsupported Git tree entry.');
            }
            $relative = str_replace('\\', '/', $matches[3]);
            $path = $subtreeRoot . '/' . $relative;
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException("The extracted package entry is invalid: {$relative}.");
            }
            $contents = file_get_contents($path);
            $blob = is_string($contents) ? sha1('blob ' . strlen($contents) . "\0" . $contents) : '';
            if (!hash_equals($matches[2], $blob)) {
                throw new \RuntimeException("The extracted package does not match the requested commit: {$relative}.");
            }
            $expected[] = $relative;
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($subtreeRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new \RuntimeException('The extracted package contains an unsupported filesystem entry.');
            }
            $actual[] = str_replace('\\', '/', substr($item->getPathname(), strlen($subtreeRoot) + 1));
        }
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new \RuntimeException('The extracted package file set does not match the requested commit.');
        }
    }

    /** @param array<int, string> $command */
    private function run(array $command, string $cwd, int $timeout, string $operation): string
    {
        $result = $this->git->run($command, $cwd, $timeout);
        if ($result['exitCode'] !== 0) {
            $timedOut = ($result['timedOut'] ?? false) === true ? 'true' : 'false';
            throw new \RuntimeException("Exact-commit package materialization failed while {$operation} [exit_code={$result['exitCode']}, timed_out={$timedOut}].");
        }

        return (string) $result['output'];
    }
}
