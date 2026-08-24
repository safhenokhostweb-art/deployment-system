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

    public static function productionSourcePathForbidden(string $path): bool
    {
        $lower = strtolower(rtrim(str_replace('\\', '/', trim($path)), '/'));

        return in_array($lower, ['/var/www', '/srv/www'], true)
            || str_starts_with($lower, '/var/www/')
            || str_starts_with($lower, '/srv/www/')
            || preg_match('#^/home/[^/]+/(?:public|public_html)(?:/|$)#', $lower) === 1
            || preg_match('#(?:^|/)htdocs(?:/|$)#', $lower) === 1
            || preg_match('#(?:^|/)(?:releases?|current)(?:/|$)#', $lower) === 1
            || preg_match('#^[a-z]:/inetpub/wwwroot(?:/|$)#', $lower) === 1;
    }

    /**
     * @throws \RuntimeException with a message safe to report back to the Hub as-is
     */
    public function assertSafeToPublish(GitManager $git, string $repositoryPath, string $expectedBranch): void
    {
        if (self::productionSourcePathForbidden($repositoryPath)) {
            throw new \RuntimeException("Refusing to publish from production-like directory '{$repositoryPath}'. Configure this project mapping to a dedicated build checkout.");
        }

        $branch = $git->currentBranch($repositoryPath);

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

    public function assertSafeToPublishVerifiedHead(GitManager $git, string $repositoryPath, string $expectedBranch, string $expectedHead): string
    {
        $this->assertSafeToPublish($git, $repositoryPath, $expectedBranch);

        if ($git->changedFiles($repositoryPath) !== []) {
            throw new \RuntimeException('Refusing no-source-change publish: the working tree is no longer clean.');
        }

        $head = $git->lastCommitHash($repositoryPath);
        if (!preg_match('/^[0-9a-f]{40}$/', $expectedHead) || !hash_equals($expectedHead, $head)) {
            throw new \RuntimeException('Refusing no-source-change publish: current HEAD no longer matches the reviewed commit.');
        }

        return $head;
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
        $result = $git->run(['git', 'status', '--porcelain'], $repositoryPath, $git->timeoutFor('status'));
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
