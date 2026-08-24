<?php

declare(strict_types=1);

/**
 * Dependency-free integration test for the `deployment_recipe` execution
 * path (RecipeStepRunner/ReleaseManager/ServiceAllowlist + Executor's
 * argv/env/onTick additions) — the Agent-side half of the
 * sandogh-family-fund deployment overhaul. Same philosophy as
 * tests/run.php: no mocking framework, real fixtures, real assertions.
 *
 * Unlike run.php, this genuinely spawns real `git`, `node`, and `npm`
 * processes (confirmed available on this dev machine) against a real
 * temporary git repository and a real temporary "Hub" (a `php -S`
 * process serving fixtures/fake_hub_server.php) — so the core safety
 * mechanism this whole effort exists to build (atomic activation +
 * automatic rollback on a post-activation failure) is proven by actually
 * running it, not by asserting about mocked calls.
 *
 * Usage: php tests/run_recipe.php
 */

require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Signer.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/Executor.php';
require __DIR__ . '/../lib/ShellPlaceholders.php';
require __DIR__ . '/../lib/ServiceAllowlist.php';
require __DIR__ . '/../lib/ReleaseManager.php';
require __DIR__ . '/../lib/RecipeStepRunner.php';
require __DIR__ . '/../lib/HttpHealthCheckRunner.php';

use DeploymentAgent\Client;
use DeploymentAgent\Executor;
use DeploymentAgent\Logger;
use DeploymentAgent\RecipeStepRunner;
use DeploymentAgent\ReleaseManager;
use DeploymentAgent\ServiceAllowlist;

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, array &$failures, int &$passCount): void
{
    if ($condition) {
        $passCount++;

        return;
    }

    $failures[] = $description;
}

function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/recipe-agent-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir)) {
        unlink($dir);

        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);

            continue;
        }
        // git marks object files read-only on Windows, which blocks a
        // plain unlink() — clearing it first is harmless on every OS.
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Whether this OS account can actually create a symlink — on Windows,
 * unlike Ubuntu (the real production target, where any regular user can),
 * that needs either SeCreateSymbolicLinkPrivilege or Developer Mode.
 * ReleaseManager's atomic activation is fundamentally symlink-based (the
 * whole point: an atomic, near-instant rename() over a symlink, not a
 * file copy), so on a Windows host without that privilege, activation
 * itself cannot be verified locally — a genuine test-environment gap,
 * not a code defect, and irrelevant to the real Ubuntu target.
 */
function symlinksAreSupported(): bool
{
    $dir = sys_get_temp_dir() . '/symlink-probe-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $target = $dir . '/target';
    $link = $dir . '/link';
    mkdir($target, 0777, true);
    $ok = @symlink($target, $link);
    rrmdir($dir);

    return $ok === true;
}

/** @return array{exitCode: int, output: string} */
function runGit(array $argv, string $cwd): array
{
    $executor = new Executor();
    $result = $executor->runArgv(array_merge(['git'], $argv), $cwd, 30);

    return ['exitCode' => $result['exitCode'], 'output' => $result['output']];
}

/**
 * A real bare "upstream" repo (standing in for GitHub) plus a real local
 * clone of it (standing in for the persistent, out-of-band-bootstrapped
 * source clone `checkout_release` worktree-adds from) — so the clone has
 * a genuine `origin` remote and checkout()'s `git fetch origin <branch>`
 * behaves exactly as it would in production, including actually needing
 * to fetch a new commit down before it can be worktree-added.
 *
 * Two commits on `main`: the first (A) is the "currently deployed"
 * baseline, the second (B) is the "next deploy" — package.json has zero
 * dependencies so `npm install` is instant and entirely offline.
 * build.js writes exactly the Next.js `output: 'standalone'` shape
 * (server.js under .next/standalone/apps/web, .next/static and public
 * left OUTSIDE it) that assemble_next_standalone exists to fix up — the
 * same officially-documented gap behind the real "manual Next.js
 * standalone path fix" workaround this whole effort replaces.
 *
 * @return array{path: string, commitA: string, commitB: string}
 */
function buildSourceRepo(string $workspace): array
{
    $upstream = $workspace . '/upstream.git';
    mkdir($upstream, 0777, true);
    runGit(['init', '--quiet', '--bare', '-b', 'main'], $upstream);

    $scratch = $workspace . '/scratch';
    mkdir($scratch, 0777, true);
    runGit(['init', '--quiet', '-b', 'main'], $scratch);
    runGit(['config', 'user.email', 'test@example.test'], $scratch);
    runGit(['config', 'user.name', 'Recipe Test'], $scratch);
    runGit(['remote', 'add', 'origin', $upstream], $scratch);

    mkdir("{$scratch}/apps/web", 0777, true);
    file_put_contents("{$scratch}/package.json", json_encode([
        'name' => 'fixture-app', 'version' => '1.0.0', 'private' => true,
        'scripts' => ['build' => 'node apps/web/build.js'],
    ], JSON_PRETTY_PRINT));
    file_put_contents("{$scratch}/apps/web/build.js", <<<'JS'
        const fs = require('fs');
        const base = __dirname;
        fs.mkdirSync(`${base}/.next/standalone/apps/web`, { recursive: true });
        fs.mkdirSync(`${base}/.next/static`, { recursive: true });
        fs.mkdirSync(`${base}/public`, { recursive: true });
        fs.writeFileSync(`${base}/.next/standalone/apps/web/server.js`, '// fixture server\n');
        fs.writeFileSync(`${base}/.next/static/foo.css`, 'body{}\n');
        fs.writeFileSync(`${base}/public/favicon.ico`, 'fixture\n');
        console.log('build ok');
        JS);

    runGit(['add', '-A'], $scratch);
    runGit(['commit', '--quiet', '-m', 'initial (release A)'], $scratch);
    runGit(['push', '--quiet', 'origin', 'main'], $scratch);
    $commitA = trim(runGit(['rev-parse', 'HEAD'], $scratch)['output']);

    // The clone `checkout_release` actually worktree-adds from — cloned
    // HERE, right after commit A, so it deliberately does NOT yet have
    // commit B below. The second deploy's checkout must genuinely
    // `git fetch origin main` to pull it down, exactly like production's
    // persistent bootstrap clone would need to after a new push.
    $source = $workspace . '/source';
    runGit(['clone', '--quiet', $upstream, $source], $workspace);

    file_put_contents("{$scratch}/apps/web/build.js", str_replace('build ok', 'build ok v2', file_get_contents("{$scratch}/apps/web/build.js")));
    runGit(['add', '-A'], $scratch);
    runGit(['commit', '--quiet', '-m', 'second (release B)'], $scratch);
    runGit(['push', '--quiet', 'origin', 'main'], $scratch);
    $commitB = trim(runGit(['rev-parse', 'HEAD'], $scratch)['output']);

    return ['path' => $source, 'commitA' => $commitA, 'commitB' => $commitB];
}

/**
 * @return array{process: resource, port: int, logPath: string}
 */
function startFakeHub(): array
{
    $port = 20000 + random_int(0, 9000);
    $logPath = tempnam(sys_get_temp_dir(), 'fake-hub-log-');
    $router = __DIR__ . '/fixtures/fake_hub_server.php';

    $phpBinary = PHP_BINARY;
    // Root-cause fix (the exact class of bug already documented in
    // Executor.php/GitManager.php elsewhere in this codebase): `php -S`
    // logs a line to its own stdout/stderr per request; piped and never
    // drained, the OS pipe buffer can fill and block the server's next
    // write, silently freezing it. A file redirect can never block.
    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-server-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], ['FAKE_HUB_LOG' => $logPath]);
    $process = proc_open([$phpBinary, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'logPath' => $logPath, 'serverLogPath' => $serverLogPath];
        }
        usleep(100000);
    }

    throw new \RuntimeException('Fake Hub server did not start listening in time.');
}

function stopFakeHub(array $hub): void
{
    proc_terminate($hub['process']);
    proc_close($hub['process']);
    @unlink($hub['logPath']);
    @unlink($hub['serverLogPath']);
}

/** @return array<int, array{method: string, path: string, body: array<string, mixed>|null}> */
function readHubLog(string $logPath): array
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($logPath)));

    return array_map(static fn (string $line) => json_decode($line, true), $lines);
}

function step(string $type, string $name, array $params = [], int $timeoutSeconds = 60): array
{
    return array_merge(['type' => $type, 'name' => $name, 'timeout_seconds' => $timeoutSeconds], $params);
}

$symlinksSupported = symlinksAreSupported();
if (!$symlinksSupported) {
    echo "NOTE: this OS account cannot create symlinks (no SeCreateSymbolicLinkPrivilege/Developer Mode) — activation/rollback symlink assertions are skipped below. Production (Ubuntu) has no such restriction for a regular user; every other assertion (git worktree/fetch, real npm/node execution, step orchestration, failure detection, retention cleanup) still runs and is asserted in full.\n\n";
}

// ============================================================
// Executor: argv-form is genuinely shell-free (no injection surface)
// ============================================================
$executor = new Executor();

$injection = $executor->runArgv(['node', '-e', 'console.log(process.argv[1])', 'a; echo INJECTED'], sys_get_temp_dir(), 15);
check(
    'runArgv passes a value containing a shell metacharacter through literally, never as a second command',
    str_contains($injection['output'], 'a; echo INJECTED') && !str_contains($injection['output'], "\nINJECTED"),
    $failures, $passCount,
);

$envResult = $executor->runArgv(['node', '-e', 'console.log(process.env.RECIPE_TEST_VAR || "")'], sys_get_temp_dir(), 15, ['RECIPE_TEST_VAR' => 'hello-from-recipe']);
check('runArgv env vars are visible to the child process', trim($envResult['output']) === 'hello-from-recipe', $failures, $passCount);

$ticks = 0;
$slow = $executor->runArgv(['node', '-e', 'setTimeout(() => {}, 2500)'], sys_get_temp_dir(), 15, [], function () use (&$ticks) { $ticks++; });
check('onTick fires at least once during a multi-second command', $ticks >= 1, $failures, $passCount);

// ============================================================
// ServiceAllowlist
// ============================================================
check('an allowed service passes', ServiceAllowlist::isAllowed('sandogh-web.service', ['sandogh-api.service', 'sandogh-web.service']), $failures, $passCount);
check('a service not on the list is rejected', !ServiceAllowlist::isAllowed('ssh.service', ['sandogh-api.service']), $failures, $passCount);
check('an empty allowlist rejects everything', !ServiceAllowlist::isAllowed('anything.service', []), $failures, $passCount);

// ============================================================
// ReleaseManager: checkout / activate / retention, against a real git repo
// ============================================================
$workspace = makeWorkspace();
$source = buildSourceRepo($workspace);
$releaseRoot = $workspace . '/app';
mkdir($releaseRoot, 0777, true);
$releases = new ReleaseManager();

$releaseA = $releases->releasePath($releaseRoot, 'deploy-a');
$checkoutResult = $releases->checkout($executor, $source['path'], $releaseA, 'main', $source['commitA'], 30);
check('checkout() succeeds via a real `git worktree add`', $checkoutResult['exitCode'] === 0, $failures, $passCount);
check('checkout() materializes the exact commit content', file_exists("{$releaseA}/apps/web/build.js"), $failures, $passCount);

$releases->activate($releaseRoot, $releaseA);
if ($symlinksSupported) {
    check('activate() creates a `current` symlink pointing at the release', $releases->currentReleasePath($releaseRoot) === $releaseA, $failures, $passCount);
    check('activate() leaves no `previous` symlink for the very first activation', $releases->previousReleasePath($releaseRoot) === null, $failures, $passCount);
}

$releaseB = $releases->releasePath($releaseRoot, 'deploy-b');
$releases->checkout($executor, $source['path'], $releaseB, 'main', $source['commitB'], 30);
$releases->activate($releaseRoot, $releaseB);
if ($symlinksSupported) {
    check('a second activate() moves `current` to the new release', $releases->currentReleasePath($releaseRoot) === $releaseB, $failures, $passCount);
    check('a second activate() sets `previous` to the release current pointed at before', $releases->previousReleasePath($releaseRoot) === $releaseA, $failures, $passCount);
}

// Retention: create three more fake releases, keep only retentionCount total.
for ($i = 1; $i <= 3; $i++) {
    $extra = $releases->releasePath($releaseRoot, "extra-{$i}");
    mkdir($extra, 0777, true);
    touch($extra, time() - (10 - $i)); // stagger mtimes, oldest first
}
$removed = $releases->cleanupOldReleases($executor, $releaseRoot, 2, $source['path']);
$remainingReleases = array_diff(scandir($releases->releasesDir($releaseRoot)), ['.', '..']);
check('cleanupOldReleases removes releases beyond the retention count', count($removed) > 0, $failures, $passCount);
if ($symlinksSupported) {
    check('cleanupOldReleases never removes the release `current` points at', in_array('deploy-b', $remainingReleases, true), $failures, $passCount);
    check('cleanupOldReleases never removes the release `previous` points at', in_array('deploy-a', $remainingReleases, true), $failures, $passCount);
}

rrmdir($workspace);

// ============================================================
// RecipeStepRunner: full end-to-end deploy, then a deliberate
// post-activation failure that must trigger automatic rollback.
// ============================================================
$workspace2 = makeWorkspace();
$source2 = buildSourceRepo($workspace2);
$releaseRoot2 = $workspace2 . '/app';
mkdir($releaseRoot2, 0777, true);

$hub = startFakeHub();
$logger = new Logger($workspace2 . '/logs');
$client = new Client(
    baseUrl: "http://127.0.0.1:{$hub['port']}",
    serverId: 'test-server',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 5,
    logger: $logger,
);
$runner = new RecipeStepRunner(new Executor(), $client, $logger, new ReleaseManager());
$projectConfig = [
    'working_directory' => $releaseRoot2,
    'job_heartbeat_interval_seconds' => 30,
    'release_retention_count' => 5,
    'allowed_services' => [],
];

function happyPathSteps(): array
{
    return [
        step('checkout_release', 'checkout', ['source_repository' => '{project_root}/../source']),
        step('verify_runtime', 'node version', ['runtime' => 'node', 'min_version' => '18.0.0']),
        step('install_dependencies', 'npm install', ['working_directory' => '{release_path}', 'manager' => 'npm', 'mode' => 'install']),
        step('run_script', 'build', ['working_directory' => '{release_path}', 'script' => 'build', 'env' => []]),
        step('verify_path_exists', 'server.js exists', ['path' => '{release_path}/apps/web/.next/standalone/apps/web/server.js', 'kind' => 'file']),
        step('verify_file_not_contains', 'no localhost leaked', ['path' => '{release_path}/apps/web/.next', 'forbidden' => 'localhost:3000']),
        step('assemble_next_standalone', 'assemble standalone', ['app_path' => 'apps/web']),
        step('verify_path_exists', 'static asset copied into standalone', ['path' => '{release_path}/apps/web/.next/standalone/apps/web/.next/static/foo.css', 'kind' => 'file']),
        step('activate_release', 'activate'),
    ];
}

// --- Deploy A: full happy path, at commit A (already present in the
// source clone at clone time — no fetch of anything new needed). ---
$jobA = [
    'id' => 'deploy-a',
    'action' => 'deploy',
    'commit_hash' => $source2['commitA'],
    'previous_commit_hash' => null,
    'branch' => 'main',
    'recipe_rollback' => false,
    'deployment_recipe' => ['timeout_seconds' => 300, 'steps' => happyPathSteps()],
];
$runner->run($jobA, $projectConfig);

$releaseAPath = (new ReleaseManager())->releasePath($releaseRoot2, 'deploy-a');
if ($symlinksSupported) {
    check('deploy A: current symlink points at release A after a successful recipe', (new ReleaseManager())->currentReleasePath($releaseRoot2) === $releaseAPath, $failures, $passCount);
}
check('deploy A: the assembled static asset genuinely exists on disk', file_exists("{$releaseAPath}/apps/web/.next/standalone/apps/web/.next/static/foo.css"), $failures, $passCount);
check('deploy A: the assembled .next/cache directory was created', is_dir("{$releaseAPath}/apps/web/.next/standalone/apps/web/.next/cache"), $failures, $passCount);

$logA = readHubLog($hub['logPath']);
$statusesA = array_values(array_filter(array_map(static fn ($e) => $e['path'] === "/api/agent/jobs/deploy-a/status" ? ($e['body']['status'] ?? null) : null, $logA)));
check('deploy A: reports running then building then activating then succeeded, in order', $statusesA === ['running', 'building', 'activating', 'succeeded'], $failures, $passCount);
check('deploy A: at least one log entry was reported for the install_dependencies step', (bool) array_filter($logA, static fn ($e) => $e['path'] === '/api/agent/jobs/deploy-a/log' && ($e['body']['step_type'] ?? null) === 'install_dependencies'), $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// --- Deploy B: succeeds through activation, then a deliberate real npm
// failure (a script that does not exist) — must trigger automatic
// rollback back to release A, replaying the http_health_check that ran
// after B's own activation. ---
$stepsB = happyPathSteps();
$stepsB[] = step('http_health_check', 'health check', [
    'url' => "http://127.0.0.1:{$hub['port']}/healthz",
    'expected_status' => 200,
    'request_timeout_seconds' => 5,
    'retries' => 0,
    'retry_delay_seconds' => 0,
    'must_not_contain' => null,
]);
$stepsB[] = step('run_script', 'deliberately broken step', ['working_directory' => '{release_path}', 'script' => 'this-script-does-not-exist', 'env' => []]);

$jobB = [
    'id' => 'deploy-b',
    'action' => 'deploy',
    'commit_hash' => $source2['commitB'],
    'previous_commit_hash' => $source2['commitA'],
    'branch' => 'main',
    'recipe_rollback' => false,
    'deployment_recipe' => ['timeout_seconds' => 300, 'steps' => $stepsB],
];
$runner->run($jobB, $projectConfig);

if ($symlinksSupported) {
    check(
        'deploy B: after a post-activation failure, current symlink is reverted back to release A',
        (new ReleaseManager())->currentReleasePath($releaseRoot2) === $releaseAPath,
        $failures, $passCount,
    );
}

$logB = readHubLog($hub['logPath']);
$statusesB = array_values(array_filter(array_map(static fn ($e) => $e['path'] === "/api/agent/jobs/deploy-b/status" ? ($e['body']['status'] ?? null) : null, $logB)));
check('deploy B: reaches activating before the deliberate failure', in_array('activating', $statusesB, true), $failures, $passCount);

// Automatic rollback itself resolves its target via previousReleasePath()
// (symlink-based) — without symlink support there is no previous release
// to find, so handleFailure() correctly reports `failed` instead (see the
// "no previous release" scenario below, which is exactly that same path).
if ($symlinksSupported) {
    check('deploy B: final reported status is rolled_back, not failed', end($statusesB) === 'rolled_back', $failures, $passCount);

    $rolledBackEntry = null;
    foreach ($logB as $entry) {
        if ($entry['path'] === '/api/agent/jobs/deploy-b/status' && ($entry['body']['status'] ?? null) === 'rolled_back') {
            $rolledBackEntry = $entry;
        }
    }
    check(
        'deploy B: the rolled_back report explains what triggered it',
        $rolledBackEntry !== null && str_contains($rolledBackEntry['body']['message'] ?? '', 'automatically rolled back'),
        $failures, $passCount,
    );
}

// --- Manual rollback (recipe_rollback:true), the same code path the
// dashboard's "Rollback" button uses — proves the previous/current
// symlink pair correctly flips again on a second rollback. ---
@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

$releaseBPath = (new ReleaseManager())->releasePath($releaseRoot2, 'deploy-b');
$jobRollback = [
    'id' => 'deploy-c-manual-rollback',
    'action' => 'rollback',
    'commit_hash' => $source2['commitB'],
    'previous_commit_hash' => $source2['commitA'],
    'branch' => 'main',
    'recipe_rollback' => true,
    'deployment_recipe' => ['timeout_seconds' => 300, 'steps' => $stepsB],
];
$runner->run($jobRollback, $projectConfig);

// runRollback()'s entire success path depends on previousReleasePath()
// (symlink-based) resolving to something — without symlink support it
// always looks like "no previous release", so both assertions below are
// only meaningful when symlinks actually work on this host.
if ($symlinksSupported) {
    check(
        'manual rollback: current symlink flips forward to release B (whatever `previous` pointed at)',
        (new ReleaseManager())->currentReleasePath($releaseRoot2) === $releaseBPath,
        $failures, $passCount,
    );

    $logC = readHubLog($hub['logPath']);
    $statusesC = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/deploy-c-manual-rollback/status' ? ($e['body']['status'] ?? null) : null, $logC)));
    check('manual rollback: reports activating then verifying then rolled_back', $statusesC === ['activating', 'verifying', 'rolled_back'], $failures, $passCount);
}

// --- No previous release available: must fail cleanly, not throw. ---
@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');
$freshRoot = $workspace2 . '/fresh-app';
mkdir($freshRoot, 0777, true);
$freshConfig = $projectConfig;
$freshConfig['working_directory'] = $freshRoot;
$jobNoPrevious = ['id' => 'deploy-no-previous', 'action' => 'rollback', 'recipe_rollback' => true, 'deployment_recipe' => ['timeout_seconds' => 60, 'steps' => []]];
$runner->run($jobNoPrevious, $freshConfig);
$logD = readHubLog($hub['logPath']);
$noPreviousStatus = null;
foreach ($logD as $entry) {
    if ($entry['path'] === '/api/agent/jobs/deploy-no-previous/status') {
        $noPreviousStatus = $entry['body']['status'] ?? null;
    }
}
check('rollback with no previous release reports failed, not a crash', $noPreviousStatus === 'failed', $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace2);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
