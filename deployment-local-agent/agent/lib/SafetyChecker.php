<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 5.2: the pre-flight checks a Publish/Rollback must pass before
 * CommitManager/PushManager ever touch the working tree — spec section
 * 5's "conflict/incomplete-merge/wrong-branch" stop conditions. A
 * credential problem needs no detection here: it already surfaces as a
 * `git push` non-zero exit, caught by agent.php's existing try/catch.
 */
final class SafetyChecker
{
    private const UNMERGED_CODES = ['UU', 'AA', 'DD', 'AU', 'UA', 'UD', 'DU'];

    /**
     * @throws \RuntimeException with a message safe to report back to the Hub as-is
     */
    public function assertSafeToPublish(GitManager $git, string $repositoryPath, string $expectedBranch): void
    {
        $branch = $this->currentBranch($git, $repositoryPath);

        if ($branch !== $expectedBranch) {
            throw new \RuntimeException("Refusing to publish: current branch is '{$branch}', expected '{$expectedBranch}'.");
        }

        if ($this->mergeInProgress($repositoryPath)) {
            throw new \RuntimeException('Refusing to publish: a merge is in progress (MERGE_HEAD exists) — resolve or abort it first.');
        }

        $conflicted = $this->conflictedFiles($git, $repositoryPath);
        if ($conflicted !== []) {
            throw new \RuntimeException('Refusing to publish: unresolved merge conflicts in ' . implode(', ', $conflicted));
        }
    }

    private function currentBranch(GitManager $git, string $repositoryPath): string
    {
        $result = $git->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repositoryPath);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Could not determine the current branch in {$repositoryPath}: " . trim($result['output']));
        }

        return trim($result['output']);
    }

    private function mergeInProgress(string $repositoryPath): bool
    {
        return is_file(rtrim($repositoryPath, '/\\') . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'MERGE_HEAD');
    }

    /**
     * @return array<int, string>
     */
    private function conflictedFiles(GitManager $git, string $repositoryPath): array
    {
        $result = $git->run(['git', 'status', '--porcelain'], $repositoryPath);
        $conflicted = [];

        foreach (preg_split('/\r?\n/', $result['output']) as $line) {
            if ($line === '') {
                continue;
            }

            $code = substr($line, 0, 2);
            if (in_array($code, self::UNMERGED_CODES, true)) {
                $conflicted[] = substr($line, 3);
            }
        }

        return $conflicted;
    }
}
