<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Pushes to the remote the developer's own git already knows how to
 * authenticate with (SSH key or Git Credential Manager) — this class
 * never reads, stores, or transmits a GitHub token or password; it only
 * ever runs `git push`, exactly as the developer would from a terminal.
 */
final class PushManager
{
    public function __construct(
        private readonly GitManager $git,
        private readonly ?RetryPolicy $retryPolicy = null,
        /** @var \Closure(string,string,string,?callable):array{exitCode:int,output:string,timedOut:bool}|null */
        ?callable $attemptRunner = null,
    ) {
        $this->attemptRunner = $attemptRunner === null ? null : \Closure::fromCallable($attemptRunner);
    }

    /** @var \Closure(string,string,string,?callable):array{exitCode:int,output:string,timedOut:bool}|null */
    private readonly ?\Closure $attemptRunner;

    public function push(string $repositoryPath, string $remoteName, string $branch, ?callable $onTick = null): string
    {
        $commitHash = $this->git->lastCommitHash($repositoryPath);
        $policy = $this->retryPolicy ?? new RetryPolicy();

        return $policy->run(function () use ($repositoryPath, $remoteName, $branch, $onTick, $commitHash): string {
            if ($this->git->lastCommitHash($repositoryPath) !== $commitHash) {
                throw new GitCommandException('Refusing to retry git push because HEAD changed during the push operation.');
            }
            $result = $this->attemptRunner !== null
                ? ($this->attemptRunner)($repositoryPath, $remoteName, $branch, $onTick)
                : $this->git->run(['git', 'push', $remoteName, $branch], $repositoryPath, $this->git->timeoutFor('push'), $onTick);
            if ($result['exitCode'] === 0) return $result['output'];
            $message = trim($result['output']);
            if (GitPushFailureClassifier::isRetryable($message)) {
                throw new RetryableOperationException('transient git push transport failure');
            }
            $reason = $result['timedOut'] ? 'timed out' : 'failed';
            throw new GitCommandException("git push {$reason} in {$repositoryPath}: {$message}", $result['timedOut']);
        });
    }
}
