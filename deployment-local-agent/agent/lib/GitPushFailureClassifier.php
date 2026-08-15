<?php

declare(strict_types=1);

namespace LocalAgent;

/** Central, fail-closed classifier for `git push` retry eligibility. */
final class GitPushFailureClassifier
{
    public static function isRetryable(string $output): bool
    {
        return preg_match('/(?:connection (?:reset|timed out|timeout|closed|was closed)|could not resolve host|temporary failure in name resolution|name or service not known|dns (?:lookup|resolution)|ssh[^\n]*(?:connection|reset|closed|timeout)|kex_exchange_identification|network is unreachable|broken pipe|remote end hung up unexpectedly|unexpected disconnect)/i', $output) === 1;
    }
}
