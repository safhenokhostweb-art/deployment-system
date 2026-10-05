<?php

declare(strict_types=1);

/**
 * Root-cause fix (Release #50/#53 primary-repository materialization
 * failures — SSH transport=network exit_code=128 with zero retry and the
 * raw diagnostic evidence discarded): regression coverage for
 * RetryableGitFetch's bounded retry, the shared
 * GitTransportFailureClassifier predicate, and the local-only sanitized
 * diagnostic logging it adds — for BOTH WorkspaceSourcePreparer's primary
 * fetch and PinnedDependencyMaterializer's dependency fetch, which must
 * never diverge.
 */

require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/RetryPolicy.php';
require __DIR__ . '/../lib/GitTransportFailureClassifier.php';
require __DIR__ . '/../lib/SecretRedactor.php';
require __DIR__ . '/../lib/RetryableGitFetch.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/ScopedSshConfig.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';
require __DIR__ . '/../lib/PinnedDependencyMaterializer.php';

use LocalAgent\BuildWorkspace;
use LocalAgent\GitManager;
use LocalAgent\GitTransportFailureClassifier;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\Logger;
use LocalAgent\PinnedDependencyMaterializer;
use LocalAgent\RetryableGitFetch;
use LocalAgent\RetryPolicy;
use LocalAgent\WorkspaceSourcePreparer;

$passed = 0;
$failed = [];
function fetchRetryCheck(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed[] = $label;
}

function fetchRetryRm(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $item = "{$path}/{$name}";
        if (is_dir($item) && !is_link($item)) {
            fetchRetryRm($item);
        } else {
            @chmod($item, 0666);
            @unlink($item);
        }
    }
    @rmdir($path);
}

function fetchRetryGit(GitManager $git, array $command, string $cwd): string
{
    $result = $git->run(array_merge(['git'], $command), $cwd, 60);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . $result['output']);
    }

    return trim($result['output']);
}

/** @return array{root: string, seed: string, remote: string, source: string, commit: string} */
function fetchRetryFixture(GitManager $git, string $label): array
{
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/local-agent-fetch-retry-' . $label . '-' . bin2hex(random_bytes(5));
    $seed = "{$root}/seed";
    $remote = "{$root}/remote.git";
    $source = "{$root}/source";
    mkdir($seed, 0700, true);
    fetchRetryGit($git, ['init', '--initial-branch=main'], $seed);
    fetchRetryGit($git, ['config', 'user.email', 'agent@example.test'], $seed);
    fetchRetryGit($git, ['config', 'user.name', 'Fetch Retry Test'], $seed);
    file_put_contents("{$seed}/tracked.txt", "content\n");
    fetchRetryGit($git, ['add', 'tracked.txt'], $seed);
    fetchRetryGit($git, ['commit', '-m', 'fixture'], $seed);
    $commit = $git->lastCommitHash($seed);
    fetchRetryGit($git, ['clone', '--bare', $seed, $remote], $root);
    fetchRetryGit($git, ['clone', '--branch', 'main', $remote, $source], $root);

    return ['root' => $root, 'seed' => $seed, 'remote' => $remote, 'source' => $source, 'commit' => $commit];
}

/** A scripted runner: canned results for the first N calls, then delegates to the real GitManager. */
function scriptedRunner(GitManager $git, array $cannedResults, ?array &$attemptsOut = null): callable
{
    $attempts = 0;
    return static function (array $command, string $cwd, int $timeout, ?callable $onTick) use ($git, $cannedResults, &$attempts, &$attemptsOut): array {
        $attempts++;
        if ($attemptsOut !== null) {
            $attemptsOut[] = $attempts;
        }
        if (isset($cannedResults[$attempts - 1])) {
            return $cannedResults[$attempts - 1];
        }

        return $git->run($command, $cwd, $timeout, $onTick);
    };
}

$globalConfigRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/local-agent-fetch-retry-globalconfig-' . bin2hex(random_bytes(5));
mkdir($globalConfigRoot, 0700, true);
$previousGlobalConfig = getenv('GIT_CONFIG_GLOBAL');
putenv('GIT_CONFIG_GLOBAL=' . $globalConfigRoot . '/isolated-global.gitconfig');

$git = new GitManager();
$transientOutput = 'fatal: Connection reset by peer';
$cleanupRoots = [];

try {
    // 1. Primary fetch: transient failure on attempt 1, success on attempt 2.
    $fixture = fetchRetryFixture($git, 'primary-success');
    $cleanupRoots[] = $fixture['root'];
    $attempts = [];
    $delays = [];
    $policy = new RetryPolicy(static function (int $s) use (&$delays): void { $delays[] = $s; });
    $runner = scriptedRunner($git, [['exitCode' => 128, 'output' => $transientOutput, 'timedOut' => false]], $attempts);
    $preparer = new WorkspaceSourcePreparer($git, retryPolicy: $policy, fetchRunner: $runner);
    $workspace = BuildWorkspace::create($fixture['root'] . '/builds', 'primary', 'retry-success');
    $prepared = $preparer->prepareRemoteCommit($workspace, $fixture['source'], $fixture['remote'], 'origin', 'main', $fixture['commit']);
    fetchRetryCheck('primary fetch: one transient failure retries and succeeds', count($attempts) === 2 && $delays === [2]);
    fetchRetryCheck('primary fetch: exact commit reachability verification ran for real after the retry', $git->lastCommitHash($workspace->projectDir()) === $fixture['commit']);
    fetchRetryCheck('primary fetch: commitTimestamp captured after retry succeeds', $prepared['commitTimestamp'] > 0);
    $workspace->cleanup();

    // 2. Dependency fetch: same transient-then-success behavior.
    $depFixture = fetchRetryFixture($git, 'dependency-success');
    $cleanupRoots[] = $depFixture['root'];
    $depAttempts = [];
    $depDelays = [];
    $depPolicy = new RetryPolicy(static function (int $s) use (&$depDelays): void { $depDelays[] = $s; });
    $depRunner = scriptedRunner($git, [['exitCode' => 128, 'output' => $transientOutput, 'timedOut' => false]], $depAttempts);
    $materializer = new PinnedDependencyMaterializer($git, retryPolicy: $depPolicy, fetchRunner: $depRunner);
    $allowlist = [['project_id' => 'p', 'repository_mapping' => 'dependency', 'branch' => 'main', 'package_name' => 'acme/widget', 'source_path' => 'pkg', 'destination_path' => '../dependency-target']];
    $depWorkspace = BuildWorkspace::create($depFixture['root'] . '/builds', 'consumer', 'dep-retry-success');
    mkdir($depFixture['seed'] . '/pkg', 0755, true);
    file_put_contents($depFixture['seed'] . '/pkg/composer.json', json_encode(['name' => 'acme/widget']));
    fetchRetryGit($git, ['add', 'pkg/composer.json'], $depFixture['seed']);
    fetchRetryGit($git, ['commit', '-m', 'composer.json'], $depFixture['seed']);
    $depCommit = $git->lastCommitHash($depFixture['seed']);
    fetchRetryGit($git, ['push', $depFixture['remote'], 'main'], $depFixture['seed']);
    $locks = $materializer->materialize($depWorkspace, [['project_id' => 'p', 'repository_mapping' => 'dependency', 'branch' => 'main', 'commit_sha' => $depCommit, 'package_name' => 'acme/widget', 'source_path' => 'pkg', 'destination_path' => '../dependency-target']], $allowlist, ['dependency' => ['repository_url' => $depFixture['remote']]]);
    fetchRetryCheck('dependency fetch: one transient failure retries and succeeds', count($depAttempts) === 2 && $depDelays === [2]);
    fetchRetryCheck('dependency fetch: materialized package name matches after retry', ($locks[0]['package_name'] ?? '') === 'acme/widget');
    $depWorkspace->cleanup();

    // 3. Primary fetch: all 3 attempts transiently fail -> existing
    // failure contract returned after exactly 3 attempts.
    $exhaustFixture = fetchRetryFixture($git, 'primary-exhaust');
    $cleanupRoots[] = $exhaustFixture['root'];
    $exhaustAttempts = [];
    $exhaustDelays = [];
    $exhaustPolicy = new RetryPolicy(static function (int $s) use (&$exhaustDelays): void { $exhaustDelays[] = $s; });
    $exhaustRunner = static function () use (&$exhaustAttempts, $transientOutput): array {
        $exhaustAttempts[] = true;

        return ['exitCode' => 128, 'output' => $transientOutput, 'timedOut' => false];
    };
    $exhaustPreparer = new WorkspaceSourcePreparer($git, retryPolicy: $exhaustPolicy, fetchRunner: $exhaustRunner);
    $exhaustWorkspace = BuildWorkspace::create($exhaustFixture['root'] . '/builds', 'primary', 'exhaust');
    $message = '';
    $failureCode = '';
    try {
        $exhaustPreparer->prepareRemoteCommit($exhaustWorkspace, $exhaustFixture['source'], $exhaustFixture['remote'], 'origin', 'main', $exhaustFixture['commit']);
    } catch (LocalBuildFailedException $e) {
        $message = $e->getMessage();
        $failureCode = $e->failureCode;
    }
    fetchRetryCheck('primary fetch: exhausts at exactly 3 attempts with 2s/5s backoff', count($exhaustAttempts) === 3 && $exhaustDelays === [2, 5]);
    fetchRetryCheck('primary fetch: exhaustion returns the existing externally-compatible failure contract', $failureCode === 'local_workspace_prepare_failed' && str_contains($message, 'Primary repository materialization failed while fetching the authoritative branch') && str_contains($message, 'stderr_category=network') && str_contains($message, 'exit_code=128'));
    $exhaustWorkspace->cleanup();

    // 4. Dependency fetch: same retry exhaustion.
    $depExhaustFixture = fetchRetryFixture($git, 'dependency-exhaust');
    $cleanupRoots[] = $depExhaustFixture['root'];
    $depExhaustAttempts = [];
    $depExhaustDelays = [];
    $depExhaustPolicy = new RetryPolicy(static function (int $s) use (&$depExhaustDelays): void { $depExhaustDelays[] = $s; });
    $depExhaustRunner = static function () use (&$depExhaustAttempts, $transientOutput): array {
        $depExhaustAttempts[] = true;

        return ['exitCode' => 128, 'output' => $transientOutput, 'timedOut' => false];
    };
    $depExhaustMaterializer = new PinnedDependencyMaterializer($git, retryPolicy: $depExhaustPolicy, fetchRunner: $depExhaustRunner);
    $depExhaustWorkspace = BuildWorkspace::create($depExhaustFixture['root'] . '/builds', 'consumer', 'dep-exhaust');
    $depMessage = '';
    try {
        $depExhaustMaterializer->materialize($depExhaustWorkspace, [['project_id' => 'p', 'repository_mapping' => 'dependency', 'branch' => 'main', 'commit_sha' => $depExhaustFixture['commit'], 'package_name' => 'acme/widget', 'source_path' => 'pkg', 'destination_path' => '../dependency-target']], $allowlist, ['dependency' => ['repository_url' => $depExhaustFixture['remote']]]);
    } catch (LocalBuildFailedException $e) {
        $depMessage = $e->getMessage();
    }
    fetchRetryCheck('dependency fetch: exhausts at exactly 3 attempts with 2s/5s backoff', count($depExhaustAttempts) === 3 && $depExhaustDelays === [2, 5]);
    fetchRetryCheck('dependency fetch: exhaustion returns the existing externally-compatible failure contract', str_contains($depMessage, 'Dependency materialization failed while fetching dependency branch') && str_contains($depMessage, 'stderr_category=network'));
    $depExhaustWorkspace->cleanup();

    // 5-8, 10: exactly one attempt for every non-retryable / timeout category.
    $singleAttemptCases = [
        'authentication failure' => ['exitCode' => 128, 'output' => 'fatal: Authentication failed for repository', 'timedOut' => false],
        'host key verification failure' => ['exitCode' => 128, 'output' => 'Host key verification failed.', 'timedOut' => false],
        'repository not found' => ['exitCode' => 128, 'output' => 'remote: Repository not found.', 'timedOut' => false],
        'remote ref missing' => ['exitCode' => 128, 'output' => "fatal: couldn't find remote ref main", 'timedOut' => false],
        'timeout' => ['exitCode' => 124, 'output' => 'fatal: connection reset by peer', 'timedOut' => true],
    ];
    foreach ($singleAttemptCases as $label => $canned) {
        $caseFixture = fetchRetryFixture($git, 'single-' . preg_replace('/[^a-z]+/', '-', $label));
        $cleanupRoots[] = $caseFixture['root'];
        $caseAttempts = [];
        $caseDelays = [];
        $casePolicy = new RetryPolicy(static function (int $s) use (&$caseDelays): void { $caseDelays[] = $s; });
        $caseRunner = static function () use (&$caseAttempts, $canned): array {
            $caseAttempts[] = true;

            return $canned;
        };
        $casePreparer = new WorkspaceSourcePreparer($git, retryPolicy: $casePolicy, fetchRunner: $caseRunner);
        $caseWorkspace = BuildWorkspace::create($caseFixture['root'] . '/builds', 'primary', 'single');
        try {
            $casePreparer->prepareRemoteCommit($caseWorkspace, $caseFixture['source'], $caseFixture['remote'], 'origin', 'main', $caseFixture['commit']);
        } catch (LocalBuildFailedException) {
        }
        fetchRetryCheck("{$label}: exactly one attempt, never retried", count($caseAttempts) === 1 && $caseDelays === []);
        $caseWorkspace->cleanup();
    }

    // 9. Repository identity mismatch: the fetch step is never reached at
    // all (DependencyRepositoryResolver::resolveTransport() fails first),
    // so it must never be "retried" in any sense.
    $identityFixture = fetchRetryFixture($git, 'identity-mismatch');
    $cleanupRoots[] = $identityFixture['root'];
    $identityAttempts = [];
    $identityRunner = static function () use (&$identityAttempts): array {
        $identityAttempts[] = true;

        return ['exitCode' => 0, 'output' => '', 'timedOut' => false];
    };
    $identityPreparer = new WorkspaceSourcePreparer($git, fetchRunner: $identityRunner);
    $identityWorkspace = BuildWorkspace::create($identityFixture['root'] . '/builds', 'primary', 'identity');
    $identityFailed = false;
    try {
        $identityPreparer->prepareRemoteCommit($identityWorkspace, $identityFixture['source'], $identityFixture['root'] . '/a-completely-different-repository.git', 'origin', 'main', $identityFixture['commit']);
    } catch (LocalBuildFailedException $e) {
        $identityFailed = str_contains($e->getMessage(), 'repository_identity_mismatch');
    }
    fetchRetryCheck('repository identity mismatch fails before any fetch attempt is ever made', $identityFailed && $identityAttempts === []);
    $identityWorkspace->cleanup();

    // 12. Primary/dependency parity: both paths make the identical
    // retryability decision (and back off identically) for the same
    // transient output.
    $parityOutputs = ['fatal: Connection reset by peer', 'ssh: connect to host git.example port 22: Connection timed out', 'fatal: the remote end hung up unexpectedly', 'remote: Repository not found.'];
    $parityOk = true;
    foreach ($parityOutputs as $output) {
        $primaryFetcher = new RetryableGitFetch($git, new RetryPolicy(static function (): void {
        }), null);
        $dependencyFetcher = new RetryableGitFetch($git, new RetryPolicy(static function (): void {
        }), null);
        $primaryAttempts = 0;
        $dependencyAttempts = 0;
        $primaryRunner = static function () use (&$primaryAttempts, $output): array {
            $primaryAttempts++;

            return ['exitCode' => 128, 'output' => $output, 'timedOut' => false];
        };
        $dependencyRunner = static function () use (&$dependencyAttempts, $output): array {
            $dependencyAttempts++;

            return ['exitCode' => 128, 'output' => $output, 'timedOut' => false];
        };
        $primaryFetcher->run(['git', 'fetch'], sys_get_temp_dir(), 5, null, 'fetching the authoritative branch', [], $primaryRunner);
        $dependencyFetcher->run(['git', 'fetch'], sys_get_temp_dir(), 5, null, 'fetching dependency branch', [], $dependencyRunner);
        $expectedAttempts = GitTransportFailureClassifier::isRetryable($output) ? 3 : 1;
        $parityOk = $parityOk && $primaryAttempts === $expectedAttempts && $dependencyAttempts === $expectedAttempts;
    }
    fetchRetryCheck('primary and dependency fetch make the identical retryability decision for every tested output', $parityOk);

    // 13-15. Local diagnostic logging: redaction, useful sanitized
    // content, and a size cap — never sent to the Hub, local Agent log
    // only.
    $logDir = str_replace('\\', '/', sys_get_temp_dir()) . '/local-agent-fetch-retry-log-' . bin2hex(random_bytes(5));
    $logger = new Logger($logDir);
    $secretOutput = "fatal: unable to access 'https://oauth2:ghp_supersecrettoken123456@github.com/acme/widget.git/': "
        . "Connection reset by peer\ntoken=my-plain-token secret=my-plain-secret Authorization: Bearer abcdefghijklmnopqrstuvwx";
    $loggingFetcher = new RetryableGitFetch($git, new RetryPolicy(static function (): void {
    }), $logger);
    $loggingRunner = static fn (): array => ['exitCode' => 128, 'output' => $secretOutput, 'timedOut' => false];
    $loggingFetcher->run(['git', 'fetch', '--no-tags', '--prune', 'origin', 'main'], sys_get_temp_dir(), 5, null, 'fetching the authoritative branch', ['mapping' => 'primary-project', 'branch' => 'main', 'identity' => 'github.com/acme/widget', 'transport' => 'ssh'], $loggingRunner);
    $logFile = $logDir . '/local-agent-' . date('Y-m-d') . '.log';
    $logContent = (string) @file_get_contents($logFile);
    fetchRetryCheck('logging: the embedded URL credential is redacted', !str_contains($logContent, 'ghp_supersecrettoken123456') && str_contains($logContent, '[REDACTED]@github.com'));
    fetchRetryCheck('logging: the plain token=/secret= values are redacted', !str_contains($logContent, 'my-plain-token') && !str_contains($logContent, 'my-plain-secret'));
    fetchRetryCheck('logging: the Bearer token is redacted', !str_contains($logContent, 'abcdefghijklmnopqrstuvwx'));
    fetchRetryCheck('logging: attempt/context metadata is present', str_contains($logContent, 'attempt 1/3') && str_contains($logContent, 'mapping=primary-project') && str_contains($logContent, 'branch=main') && str_contains($logContent, 'stderr_category=network') && str_contains($logContent, 'exit_code=128') && str_contains($logContent, 'timed_out=false'));
    fetchRetryCheck('logging: the sanitized transient error text itself is still readable', str_contains($logContent, 'Connection reset by peer'));

    $capLogDir = str_replace('\\', '/', sys_get_temp_dir()) . '/local-agent-fetch-retry-cap-' . bin2hex(random_bytes(5));
    $capLogger = new Logger($capLogDir);
    // Non-retryable filler text so this logs exactly once — isolates the
    // size-cap assertion below from the retry count entirely.
    $hugeOutput = 'fatal: Authentication failed for repository - ' . str_repeat('padding filler text ', 200);
    $capFetcher = new RetryableGitFetch($git, new RetryPolicy(static function (): void {
    }), $capLogger);
    $capRunner = static fn (): array => ['exitCode' => 128, 'output' => $hugeOutput, 'timedOut' => false];
    $capFetcher->run(['git', 'fetch'], sys_get_temp_dir(), 5, null, 'fetching the authoritative branch', [], $capRunner);
    $capLogFile = $capLogDir . '/local-agent-' . date('Y-m-d') . '.log';
    $capLogContent = (string) @file_get_contents($capLogFile);
    fetchRetryCheck('logging: an oversized excerpt is capped, not logged in full', strlen($hugeOutput) > 2000 && str_contains($capLogContent, 'output truncated at 2000 bytes') && strlen($capLogContent) < strlen($hugeOutput));

    // 16 (spot-check only — the authoritative coverage is
    // run_push_retry.php, already re-run unmodified): the renamed
    // classifier still recognizes every pattern `git push` relies on.
    foreach (['connection reset', 'Connection timed out', 'kex_exchange_identification: Connection closed', 'remote end hung up unexpectedly'] as $transient) {
        fetchRetryCheck("shared classifier still accepts transient: {$transient}", GitTransportFailureClassifier::isRetryable($transient));
    }
    foreach (['fatal: Authentication failed', 'remote: Repository not found.'] as $nonTransient) {
        fetchRetryCheck("shared classifier still rejects: {$nonTransient}", !GitTransportFailureClassifier::isRetryable($nonTransient));
    }
} finally {
    foreach ($cleanupRoots as $root) {
        fetchRetryRm($root);
    }
    fetchRetryRm($globalConfigRoot);
    if (isset($logDir)) {
        fetchRetryRm($logDir);
    }
    if (isset($capLogDir)) {
        fetchRetryRm($capLogDir);
    }
    $previousGlobalConfig === false ? putenv('GIT_CONFIG_GLOBAL') : putenv('GIT_CONFIG_GLOBAL=' . $previousGlobalConfig);
}

foreach ($failed as $label) {
    echo "FAIL: {$label}\n";
}
echo "{$passed} passed, " . count($failed) . " failed.\n";
exit($failed === [] ? 0 : 1);
