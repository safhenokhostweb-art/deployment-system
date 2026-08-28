<?php

declare(strict_types=1);

/**
 * Phase 2 (Workspace Inventory): WorkspaceInventoryReporter against a
 * real fake Hub HTTP server (fixtures/fake_hub_workspace_inventory_server.php)
 * and real git repos — same no-mocking philosophy as the rest of this
 * test suite. Covers gating (unchanged snapshot not spammed, interval
 * renewal, hash-change triggers a resend), legacy-entry skipping,
 * scanner-failure isolation, Hub-failure isolation, and proves — via the
 * same outcome-based technique Phase 1's own critical test uses, since
 * GitManager is `final` and not mockable — that a full poll() cycle
 * never mutates any of the scanned repositories.
 *
 * Usage: php tests/run_workspace_inventory_reporting.php
 */

require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/GitStatusPorcelainV2Parser.php';
require __DIR__ . '/../lib/WorkspaceInventoryScanner.php';
require __DIR__ . '/../lib/WorkspaceInventoryReporter.php';

use LocalAgent\Client;
use LocalAgent\GitManager;
use LocalAgent\Logger;
use LocalAgent\WorkspaceInventoryReporter;
use LocalAgent\WorkspaceInventoryScanner;

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

function makeTempDir(string $label): string
{
    $root = sys_get_temp_dir() . "/{$label}-" . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);

            continue;
        }
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

function newGit(): GitManager
{
    return new GitManager(timeouts: ['status' => 30], jobHeartbeatIntervalSeconds: 10);
}

function runGit(GitManager $git, array $argv, string $cwd): array
{
    return $git->run(array_merge(['git'], $argv), $cwd, 30);
}

function initRepo(GitManager $git, string $repo): void
{
    mkdir($repo, 0777, true);
    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Workspace Inventory Reporting Test'], $repo);
}

function commitAll(GitManager $git, string $repo, string $message = 'commit'): void
{
    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', $message], $repo);
}

function treeContentHash(string $dir): string
{
    $lines = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($dir) + 1));
        if ($relative === '.git' || str_starts_with($relative, '.git/')) {
            continue;
        }
        if ($item->isDir()) {
            continue;
        }
        $lines[] = $relative . '|' . sha1_file((string) $item->getPathname());
    }
    sort($lines);

    return hash('sha256', implode("\n", $lines));
}

/** @return array{process: resource, port: int, logPath: string, scenarioPath: string, serverLogPath: string} */
function startFakeHub(): array
{
    $port = 22000 + random_int(0, 8000);
    $storageDir = makeTempDir('fake-hub-workspace-inventory');
    $logPath = "{$storageDir}/requests.jsonl";
    touch($logPath);
    $scenarioPath = "{$storageDir}/scenario.json";
    $router = __DIR__ . '/fixtures/fake_hub_workspace_inventory_server.php';

    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-wi-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], ['FAKE_HUB_LOG_PATH' => $logPath, 'FAKE_HUB_SCENARIO_PATH' => $scenarioPath]);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'logPath' => $logPath, 'scenarioPath' => $scenarioPath, 'serverLogPath' => $serverLogPath, 'storageDir' => $storageDir];
        }
        usleep(100000);
    }

    throw new \RuntimeException('Fake Hub server did not start listening in time.');
}

function stopFakeHub(array $hub): void
{
    proc_terminate($hub['process']);
    proc_close($hub['process']);
    @unlink($hub['serverLogPath']);
    rrmdir($hub['storageDir']);
}

/** @return array<int, array<string, mixed>> */
function readLoggedRequests(string $logPath): array
{
    $lines = array_filter(explode("\n", (string) file_get_contents($logPath)));

    return array_map(fn (string $line) => json_decode($line, true), $lines);
}

function newClient(int $port, Logger $logger): Client
{
    return new Client(
        baseUrl: "http://127.0.0.1:{$port}",
        localAgentId: 'test-agent-id',
        agentToken: 'test-token',
        signingSecret: 'test-secret',
        timeoutSeconds: 5,
        logger: $logger,
    );
}

// ---------------------------------------------------------------------------
// Gating: first scan reports, unchanged snapshot is not spammed, hash
// change triggers a resend, interval elapsed triggers a renewal resend.
// ---------------------------------------------------------------------------
$ws = makeTempDir('wi-report-gating');
$hub = startFakeHub();
try {
    $git = newGit();
    $repo = "{$ws}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $logDir = makeTempDir('wi-report-gating-log');
    $logger = new Logger($logDir);
    $client = newClient($hub['port'], $logger);
    $scanner = new WorkspaceInventoryScanner($git);
    $reporter = new WorkspaceInventoryReporter($scanner, $client, $logger, refreshIntervalSeconds: 60);

    $projects = ['deployment-system' => ['project_id' => 1, 'mapping_id' => 10, 'source_workspace_path' => $repo]];
    $state = [];

    $reporter->poll($projects, $state);
    check('gating: first scan reports once', count(readLoggedRequests($hub['logPath'])) === 1, $failures, $passCount);
    check('gating: state records the reported hash', isset($state['deployment-system']['hash']), $failures, $passCount);

    $reporter->poll($projects, $state);
    check('gating: unchanged snapshot is not reported again immediately', count(readLoggedRequests($hub['logPath'])) === 1, $failures, $passCount);

    file_put_contents("{$repo}/new-file.txt", "new\n");
    $reporter->poll($projects, $state);
    check('gating: a changed snapshot (new untracked file) triggers a resend', count(readLoggedRequests($hub['logPath'])) === 2, $failures, $passCount);

    // Force the interval to have "elapsed" by rewriting the recorded timestamp.
    $state['deployment-system']['at'] = time() - 3600;
    $reporter->poll($projects, $state);
    check('gating: an elapsed interval triggers a renewal resend even with an unchanged snapshot', count(readLoggedRequests($hub['logPath'])) === 3, $failures, $passCount);

    $requests = readLoggedRequests($hub['logPath']);
    $last = end($requests);
    check('payload: includes project_id', $last['project_id'] === 1, $failures, $passCount);
    check('payload: includes mapping_id', $last['mapping_id'] === 10, $failures, $passCount);
    check('payload: includes a scanned_at timestamp', isset($last['scanned_at']) && is_string($last['scanned_at']), $failures, $passCount);
    check('payload: includes the scanner snapshot_hash', isset($last['snapshot_hash']) && preg_match('/^[0-9a-f]{64}$/', $last['snapshot_hash']) === 1, $failures, $passCount);
} finally {
    stopFakeHub($hub);
    rrmdir($ws);
}

// ---------------------------------------------------------------------------
// Legacy entries (no project_id/mapping_id) are skipped, never reported.
// R (adapted): a project not present in the synced $projects map at all
// — e.g. a detached Sandogh — is never scanned or reported, by construction.
// ---------------------------------------------------------------------------
$ws2 = makeTempDir('wi-report-legacy-skip');
$hub2 = startFakeHub();
try {
    $git = newGit();
    $legacyRepo = "{$ws2}/legacy-repo";
    initRepo($git, $legacyRepo);
    file_put_contents("{$legacyRepo}/base.txt", "base\n");
    commitAll($git, $legacyRepo);

    // Simulates a real, on-disk, valid git repo (e.g. a detached Sandogh
    // checkout) that simply never appears in the synced $projects map —
    // the exact real-world shape of "not synced/mapped".
    $sandoghRepo = "{$ws2}/sandogh-family-fund";
    initRepo($git, $sandoghRepo);
    file_put_contents("{$sandoghRepo}/base.txt", "base\n");
    commitAll($git, $sandoghRepo);

    $logDir = makeTempDir('wi-report-legacy-skip-log');
    $logger = new Logger($logDir);
    $client = newClient($hub2['port'], $logger);
    $scanner = new WorkspaceInventoryScanner($git);
    $reporter = new WorkspaceInventoryReporter($scanner, $client, $logger, refreshIntervalSeconds: 60);

    // Only the legacy-config-shaped entry (no project_id/mapping_id) is
    // passed — sandogh-family-fund is not in this map at all.
    $projects = ['legacy-project' => ['remote_name' => 'origin', 'branch' => 'main', 'source_workspace_path' => $legacyRepo]];
    $state = [];

    $reporter->poll($projects, $state);

    check('legacy skip: no request was sent for a project with no project_id/mapping_id', readLoggedRequests($hub2['logPath']) === [], $failures, $passCount);
    check('legacy skip: state was never recorded for the legacy entry', $state === [], $failures, $passCount);
} finally {
    stopFakeHub($hub2);
    rrmdir($ws2);
}

// ---------------------------------------------------------------------------
// Q. A scanner failure for one project (bad/nonexistent path) does not
// crash poll() and does not stop a DIFFERENT project's cycle in the same call.
// ---------------------------------------------------------------------------
$ws3 = makeTempDir('wi-report-scan-failure');
$hub3 = startFakeHub();
try {
    $git = newGit();
    $goodRepo = "{$ws3}/good-repo";
    initRepo($git, $goodRepo);
    file_put_contents("{$goodRepo}/base.txt", "base\n");
    commitAll($git, $goodRepo);

    $logDir = makeTempDir('wi-report-scan-failure-log');
    $logger = new Logger($logDir);
    $client = newClient($hub3['port'], $logger);
    $scanner = new WorkspaceInventoryScanner($git);
    $reporter = new WorkspaceInventoryReporter($scanner, $client, $logger, refreshIntervalSeconds: 60);

    $projects = [
        'broken-project' => ['project_id' => 2, 'mapping_id' => 20, 'source_workspace_path' => "{$ws3}/does-not-exist-at-all"],
        'good-project' => ['project_id' => 3, 'mapping_id' => 30, 'source_workspace_path' => $goodRepo],
    ];
    $state = [];

    $threw = false;
    try {
        $reporter->poll($projects, $state);
    } catch (\Throwable $e) {
        $threw = true;
    }

    check('scan failure: poll() itself never throws', !$threw, $failures, $passCount);
    check('scan failure: the broken project is simply skipped (no state recorded)', !isset($state['broken-project']), $failures, $passCount);
    check('scan failure: the good project in the SAME call still gets reported', isset($state['good-project']), $failures, $passCount);
    check('scan failure: exactly one request was sent (for the good project only)', count(readLoggedRequests($hub3['logPath'])) === 1, $failures, $passCount);
} finally {
    stopFakeHub($hub3);
    rrmdir($ws3);
}

// ---------------------------------------------------------------------------
// Q (continued). A Hub-side failure response (non-200) does not crash
// poll() and does not mark the project as successfully reported (retried
// next cycle instead).
// ---------------------------------------------------------------------------
$ws4 = makeTempDir('wi-report-hub-failure');
$hub4 = startFakeHub();
try {
    $git = newGit();
    $repo = "{$ws4}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    file_put_contents($hub4['scenarioPath'], json_encode(['status' => 422]));

    $logDir = makeTempDir('wi-report-hub-failure-log');
    $logger = new Logger($logDir);
    $client = newClient($hub4['port'], $logger);
    $scanner = new WorkspaceInventoryScanner($git);
    $reporter = new WorkspaceInventoryReporter($scanner, $client, $logger, refreshIntervalSeconds: 60);

    $projects = ['deployment-system' => ['project_id' => 1, 'mapping_id' => 10, 'source_workspace_path' => $repo]];
    $state = [];

    $threw = false;
    try {
        $reporter->poll($projects, $state);
    } catch (\Throwable $e) {
        $threw = true;
    }

    check('hub failure: poll() itself never throws', !$threw, $failures, $passCount);
    check('hub failure: state is not recorded as successfully reported', !isset($state['deployment-system']), $failures, $passCount);
    check('hub failure: the Hub did receive the attempt', count(readLoggedRequests($hub4['logPath'])) === 1, $failures, $passCount);

    // Next cycle (Hub now healthy) retries and succeeds.
    unlink($hub4['scenarioPath']);
    $reporter->poll($projects, $state);
    check('hub failure: a subsequent healthy cycle succeeds and records state', isset($state['deployment-system']), $failures, $passCount);
} finally {
    stopFakeHub($hub4);
    rrmdir($ws4);
}

// ---------------------------------------------------------------------------
// P. A full poll() cycle across multiple projects never mutates any
// scanned repository — same outcome-based technique as Phase 1's own
// critical test (GitManager is `final`, not mockable).
// ---------------------------------------------------------------------------
$ws5 = makeTempDir('wi-report-no-mutation');
$hub5 = startFakeHub();
try {
    $git = newGit();
    $repoA = "{$ws5}/repo-a";
    initRepo($git, $repoA);
    file_put_contents("{$repoA}/tracked.txt", "base\n");
    commitAll($git, $repoA);
    file_put_contents("{$repoA}/tracked.txt", "modified\n");
    file_put_contents("{$repoA}/untracked.txt", "new\n");

    $repoB = "{$ws5}/repo-b";
    initRepo($git, $repoB);
    file_put_contents("{$repoB}/base.txt", "base\n");
    commitAll($git, $repoB);

    $before = [];
    foreach (['repo-a' => $repoA, 'repo-b' => $repoB] as $key => $path) {
        $before[$key] = [
            'head' => trim(runGit($git, ['rev-parse', 'HEAD'], $path)['output']),
            'branch' => trim(runGit($git, ['rev-parse', '--abbrev-ref', 'HEAD'], $path)['output']),
            'status' => runGit($git, ['status', '--porcelain=v2', '-z', '--branch', '--ahead-behind', '--untracked-files=all'], $path)['output'],
            'tree' => treeContentHash($path),
        ];
    }

    $logDir = makeTempDir('wi-report-no-mutation-log');
    $logger = new Logger($logDir);
    $client = newClient($hub5['port'], $logger);
    $scanner = new WorkspaceInventoryScanner($git);
    $reporter = new WorkspaceInventoryReporter($scanner, $client, $logger, refreshIntervalSeconds: 60);

    $projects = [
        'repo-a' => ['project_id' => 1, 'mapping_id' => 10, 'source_workspace_path' => $repoA],
        'repo-b' => ['project_id' => 2, 'mapping_id' => 20, 'source_workspace_path' => $repoB],
    ];
    $state = [];
    $reporter->poll($projects, $state);

    foreach (['repo-a' => $repoA, 'repo-b' => $repoB] as $key => $path) {
        $after = [
            'head' => trim(runGit($git, ['rev-parse', 'HEAD'], $path)['output']),
            'branch' => trim(runGit($git, ['rev-parse', '--abbrev-ref', 'HEAD'], $path)['output']),
            'status' => runGit($git, ['status', '--porcelain=v2', '-z', '--branch', '--ahead-behind', '--untracked-files=all'], $path)['output'],
            'tree' => treeContentHash($path),
        ];

        check("no mutation ({$key}): HEAD unchanged", $before[$key]['head'] === $after['head'], $failures, $passCount);
        check("no mutation ({$key}): branch unchanged", $before[$key]['branch'] === $after['branch'], $failures, $passCount);
        check("no mutation ({$key}): git status output byte-identical", $before[$key]['status'] === $after['status'], $failures, $passCount);
        check("no mutation ({$key}): working tree content byte-identical", $before[$key]['tree'] === $after['tree'], $failures, $passCount);
        check("no mutation ({$key}): no MERGE_HEAD/index.lock left behind", !is_file("{$path}/.git/MERGE_HEAD") && !is_file("{$path}/.git/index.lock"), $failures, $passCount);
    }

    check('no mutation: both projects were actually scanned and reported (sanity check)', count(readLoggedRequests($hub5['logPath'])) === 2, $failures, $passCount);
} finally {
    stopFakeHub($hub5);
    rrmdir($ws5);
}

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
