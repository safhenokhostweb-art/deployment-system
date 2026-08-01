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
    ) {
    }

    public function push(string $repositoryPath, string $remoteName, string $branch, ?callable $onTick = null): string
    {
        $result = $this->git->run(['git', 'push', $remoteName, $branch], $repositoryPath, $this->git->timeoutFor('push'), $onTick);

        if ($result['exitCode'] !== 0) {
            $reason = $result['timedOut'] ? 'timed out' : 'failed';

            throw new GitCommandException("git push {$reason} in {$repositoryPath}: " . trim($result['output']), $result['timedOut']);
        }

        return $result['output'];
    }
}
