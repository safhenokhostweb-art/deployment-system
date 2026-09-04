<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (materialization retry gap): renamed from
 * GitPushFailureClassifier — the exact same predicate, unchanged, now
 * shared by `git push` (PushManager) AND the isolated-workspace branch
 * fetch during Artifact materialization (RetryableGitFetch, used by both
 * WorkspaceSourcePreparer's primary repository fetch and
 * PinnedDependencyMaterializer's dependency fetch). One predicate, one
 * place, so a transient-failure signature is recognized identically
 * everywhere a Git network operation can retry — never several
 * independently-drifting copies.
 *
 * Deliberately narrower in scope than
 * DependencyRepositoryResolver::stderrCategory() — that method's coarse
 * external category (network/authentication/host_key_verification/
 * repository_not_found/remote_ref_missing/process_start/git_failure) is a
 * reporting label, unchanged by this class and kept backward-compatible
 * on the Hub. This predicate answers a different question purely for
 * LOCAL retry control: is a signature present that means "the connection
 * genuinely dropped mid-operation", regardless of which external category
 * it happens to be reported under (e.g. "remote end hung up
 * unexpectedly" is reported as git_failure externally, but is still
 * treated as transient/retryable here).
 */
final class GitTransportFailureClassifier
{
    public static function isRetryable(string $output): bool
    {
        return preg_match('/(?:connection (?:reset|timed out|timeout|closed|was closed)|could not resolve host|temporary failure in name resolution|name or service not known|dns (?:lookup|resolution)|ssh[^\n]*(?:connection|reset|closed|timeout)|kex_exchange_identification|network is unreachable|broken pipe|remote end hung up unexpectedly|unexpected disconnect)/i', $output) === 1;
    }
}
