<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (Release #50/#53 primary-repository materialization
 * failures): the ONE genuinely network-exposed git command in
 * materialization — the branch fetch into a fresh, empty isolated
 * BuildWorkspace — never retried at all. A single transient transport
 * blip (the same shape `git push` already survives via RetryPolicy +
 * GitTransportFailureClassifier) turned into a hard, permanent build
 * failure with the underlying evidence discarded the instant its coarse
 * category was computed.
 *
 * Shared by WorkspaceSourcePreparer's primary repository fetch and
 * PinnedDependencyMaterializer's dependency fetch — both call this same
 * class so the two paths can never independently drift on retry policy,
 * transient classification, or diagnostic logging.
 *
 * Retries ONLY a classified-transient transport failure
 * (GitTransportFailureClassifier::isRetryable(), unchanged from what
 * `git push` already uses) that did not time out. A timeout, or any
 * deterministic failure (authentication, host key, repository identity,
 * missing ref, ...), fails on the first attempt — exactly as before this
 * class existed; retrying a slow-failing timeout would only amplify a
 * 180s stall into up to 3x that, and a deterministic failure will never
 * succeed no matter how many times it's repeated.
 *
 * Every failed attempt is logged locally (sanitized, size-capped — see
 * logAttemptFailure()) before either retrying or giving up. The Hub-
 * visible failure_message is untouched by this class: each caller keeps
 * building its own existing, externally-compatible message from the
 * {exitCode, output, timedOut} result this always returns — this class
 * itself never throws.
 */
final class RetryableGitFetch
{
    /** Hard cap on the sanitized stderr/stdout excerpt kept in the local Agent log per failed attempt. */
    private const MAX_LOGGED_OUTPUT_BYTES = 2000;

    public function __construct(
        private readonly GitManager $git,
        private readonly RetryPolicy $retryPolicy,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * @param array<int, string> $command
     * @param array{mapping?: string, branch?: string, identity?: string, transport?: string} $logContext
     * @param (callable(array<int,string>,string,int,?callable):array{exitCode:int,output:string,timedOut:bool})|null $runner
     *     Test-only seam mirroring PushManager's $attemptRunner — when
     *     given, replaces the real GitManager::run() call for THIS fetch
     *     only, so a transient-then-success (or exhaustion) sequence can
     *     be scripted deterministically without a real flaky network.
     * @return array{exitCode: int, output: string, timedOut: bool}
     */
    public function run(array $command, string $cwd, int $timeout, ?callable $onTick, string $logOperation, array $logContext = [], ?callable $runner = null): array
    {
        $lastResult = ['exitCode' => 255, 'output' => 'git fetch did not run', 'timedOut' => false];

        try {
            return $this->retryPolicy->run(function (int $attempt) use ($command, $cwd, $timeout, $onTick, $logOperation, $logContext, $runner, &$lastResult): array {
                $result = $runner !== null
                    ? $runner($command, $cwd, $timeout, $onTick)
                    : $this->git->run($command, $cwd, $timeout, $onTick);
                $lastResult = $result;

                if ($result['exitCode'] === 0) {
                    return $result;
                }

                $timedOut = ($result['timedOut'] ?? false) === true;
                $output = (string) ($result['output'] ?? '');
                $category = DependencyRepositoryResolver::stderrCategory($output, $timedOut);

                $this->logAttemptFailure($logOperation, $logContext, $attempt, (int) $result['exitCode'], $timedOut, $category, $output);

                if (!$timedOut && GitTransportFailureClassifier::isRetryable($output)) {
                    throw new RetryableOperationException('transient git transport failure');
                }

                // Deterministic failure, or a timeout — never retried.
                // RetryPolicy::run() only catches RetryableOperationException,
                // so this immediately stops the loop; caught below, and the
                // (only) result already captured above is returned as-is.
                throw new \DomainException('non-retryable git transport failure');
            });
        } catch (\Throwable) {
            return $lastResult;
        }
    }

    /**
     * @param array<int, string> $command
     * @param array{mapping?: string, branch?: string, identity?: string, transport?: string} $context
     */
    private function logAttemptFailure(string $operation, array $context, int $attempt, int $exitCode, bool $timedOut, string $category, string $output): void
    {
        if ($this->logger === null) {
            return;
        }

        $sanitized = SecretRedactor::redactForLog($output);
        if (strlen($sanitized) > self::MAX_LOGGED_OUTPUT_BYTES) {
            $sanitized = substr($sanitized, 0, self::MAX_LOGGED_OUTPUT_BYTES) . "\n[local-agent] output truncated at " . self::MAX_LOGGED_OUTPUT_BYTES . ' bytes';
        }

        $this->logger->error(sprintf(
            "git fetch attempt %d/%d failed [operation=%s, mapping=%s, branch=%s, transport=%s, exit_code=%d, timed_out=%s, stderr_category=%s]:\n%s",
            $attempt,
            RetryPolicy::MAX_ATTEMPTS,
            $operation,
            $context['mapping'] ?? 'unknown',
            $context['branch'] ?? 'unknown',
            $context['transport'] ?? 'unknown',
            $exitCode,
            $timedOut ? 'true' : 'false',
            $category,
            $sanitized,
        ));
    }
}
