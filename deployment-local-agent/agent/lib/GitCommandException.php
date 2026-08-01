<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Thrown by CommitManager/PushManager when a git command fails OR times
 * out — $timedOut lets agent.php report a distinct `failure_code:
 * process_timeout` to the Hub for the timeout case (see
 * LocalAgentJobController::report()), instead of an ordinary failure
 * indistinguishable from e.g. a merge conflict or a rejected push.
 */
final class GitCommandException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
    ) {
        parent::__construct($message);
    }
}
