<?php

declare(strict_types=1);

/**
 * Real, non-mocked test for PipelineExecutor — the extracted legacy
 * pipeline loop (see its own docblock) — proving exactly the three
 * behaviors this task's audit asked for directly, not just by code
 * review: production skips a step ONLY when an artifact was actually
 * activated, no pipeline step ever runs after a failed artifact
 * activation, and a project with no artifact runs its pipeline exactly
 * as it always has.
 *
 * Usage: php tests/run_pipeline.php
 */

require __DIR__ . '/../lib/Signer.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/Executor.php';
require __DIR__ . '/../lib/ShellPlaceholders.php';
require __DIR__ . '/../lib/ArtifactManager.php';
require __DIR__ . '/../lib/ReleaseValidator.php';
require __DIR__ . '/../lib/ProductionPathGuard.php';
require __DIR__ . '/../lib/PipelineExecutor.php';
require __DIR__ . '/../lib/HttpHealthCheckRunner.php';

use DeploymentAgent\ArtifactManager;
use DeploymentAgent\Client;
use DeploymentAgent\Executor;
use DeploymentAgent\Logger;
use DeploymentAgent\PipelineExecutor;

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
    $root = sys_get_temp_dir() . '/pipeline-agent-test-' . bin2hex(random_bytes(6));
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
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

function buildFixtureZip(string $dir, string $name): array
{
    $zipPath = "{$dir}/{$name}.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('index.php', "<?php\n// artifact content\n");
    $zip->close();

    return ['path' => $zipPath, 'checksum' => hash_file('sha256', $zipPath), 'size' => filesize($zipPath)];
}

/**
 * @param array<int, string> $failJobIds           /log + /status POSTs for these job ids get HTTP 500
 * @param array<int, string> $transportFailJobIds  the post-artifact runtime-permissions /log POST for these job
 *                                                 ids is answered with an abruptly truncated response (Client throws)
 * @return array{process: resource, port: int, logPath: string, manifestPath: string, serverLogPath: string}
 */
function startFakeHub(array $failJobIds = [], array $transportFailJobIds = []): array
{
    $port = 23000 + random_int(0, 8000);
    $logPath = tempnam(sys_get_temp_dir(), 'fake-hub-pipeline-log-');
    $manifestPath = tempnam(sys_get_temp_dir(), 'pipeline-artifact-manifest-');
    file_put_contents($manifestPath, json_encode([]));
    $router = __DIR__ . '/fixtures/fake_hub_pipeline_server.php';

    // Root-cause fix (the exact class of bug already documented in
    // Executor.php/GitManager.php elsewhere in this codebase): `php -S`
    // prints an access-log line to its own stdout/stderr for every
    // request it serves. Piping those and never draining them risks the
    // OS pipe buffer filling up, which blocks the SERVER's own next
    // write — i.e. it silently stops answering requests, exactly like a
    // hung test. Redirecting to a file instead means a write can never
    // block regardless of volume, and nothing here ever needs to read
    // this server's own stdout/stderr anyway (its behavior is observed
    // via FAKE_HUB_LOG and the HTTP responses themselves).
    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-pipeline-server-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], [
        'FAKE_HUB_LOG' => $logPath,
        'ARTIFACT_MANIFEST_PATH' => $manifestPath,
        'FAKE_HUB_FAIL_JOB_IDS' => implode(',', $failJobIds),
        'FAKE_HUB_TRANSPORT_FAIL_JOB_IDS' => implode(',', $transportFailJobIds),
    ]);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'logPath' => $logPath, 'manifestPath' => $manifestPath, 'serverLogPath' => $serverLogPath];
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
    @unlink($hub['logPath']);
    @unlink($hub['manifestPath']);
}

function registerManifestEntry(string $manifestPath, string $publicId, string $file, int $status = 200): void
{
    $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
    $manifest[$publicId] = ['file' => $file, 'status' => $status];
    file_put_contents($manifestPath, json_encode($manifest));
}

/** @return array<int, array{method: string, path: string, body: array<string, mixed>|null}> */
function readHubLog(string $logPath): array
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($logPath)));

    return array_map(static fn (string $line) => json_decode($line, true), $lines);
}

/**
 * Reads the local agent log file (the Logger's daily file) and returns only
 * the lines mentioning $jobId, so assertions can target one job's local
 * diagnostics even though every scenario in this file shares one workspace
 * and therefore one Logger.
 *
 * @return array<int, string>
 */
function readAgentLogLines(string $logDir, string $jobId): array
{
    $logFile = $logDir . '/agent-' . date('Y-m-d') . '.log';
    $lines = array_filter(explode("\n", (string) @file_get_contents($logFile)));

    return array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, $jobId)));
}

$workspace = makeWorkspace();
// Only 'job-hub-http-fail' and 'job-hub-transport-fail' are failed by the
// fake Hub (see the fixture's FAKE_HUB_FAIL_JOB_IDS /
// FAKE_HUB_TRANSPORT_FAIL_JOB_IDS env contract); every other job in this
// file is served normally by the same process.
$hub = startFakeHub(['job-hub-http-fail'], ['job-hub-transport-fail']);
$logger = new Logger($workspace . '/logs');
$client = new Client(
    baseUrl: "http://127.0.0.1:{$hub['port']}",
    serverId: 'test-server',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 10,
    logger: $logger,
);
$executor = new Executor();
$artifactManager = new ArtifactManager($client);
$pipelineExecutor = new PipelineExecutor($client, $executor, $artifactManager, $logger);

// ============================================================
// Scenario 1: no artifact — the exact legacy pipeline runs, unaffected,
// including a step marked skip_when_artifact (which only ever matters
// once an artifact was actually activated).
// ============================================================
$legacyDir = $workspace . '/legacy-site';
mkdir($legacyDir, 0777, true);
$marker1 = $workspace . '/legacy-step1.marker';
$marker2 = $workspace . '/legacy-step2.marker';

$jobLegacy = [
    'id' => 'job-legacy',
    'action' => 'deploy',
    'project_key' => 'legacy-project',
    'commit_hash' => str_repeat('a', 40),
    'branch' => 'main',
    'artifact' => null,
    'pipeline' => [
        ['name' => 'git pull', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker1), 'skip_when_artifact' => true],
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker2)],
    ],
];
$pipelineExecutor->execute($jobLegacy, ['working_directory' => $legacyDir], 30);

check('legacy (no artifact): the skip_when_artifact-flagged step still ran', is_file($marker1), $failures, $passCount);
check('legacy (no artifact): the normal step ran', is_file($marker2), $failures, $passCount);

$logLegacy = readHubLog($hub['logPath']);
$legacyStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-legacy/status' ? ($e['body']['status'] ?? null) : null, $logLegacy)));
check('legacy (no artifact): reports running then succeeded', $legacyStatuses === ['running', 'succeeded'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 2: artifact present and activates successfully — the
// skip_when_artifact step is skipped, the normal step still runs.
// ============================================================
$artifactSiteDir = $workspace . '/artifact-site';
$fixtureZip = buildFixtureZip($workspace, 'good-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-good', $fixtureZip['path']);

$marker1b = $workspace . '/artifact-step1.marker';
$marker2b = $workspace . '/artifact-step2.marker';

$jobWithArtifact = [
    'id' => 'job-with-artifact',
    'action' => 'deploy',
    'project_key' => 'artifact-project',
    'commit_hash' => str_repeat('b', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-good', 'checksum_sha256' => $fixtureZip['checksum'], 'size_bytes' => $fixtureZip['size']],
    'pipeline' => [
        ['name' => 'git pull', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker1b), 'skip_when_artifact' => true],
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker2b)],
    ],
];
$pipelineExecutor->execute($jobWithArtifact, ['working_directory' => $artifactSiteDir, 'allowed_services' => []], 30);

check('artifact present: the artifact content was actually activated', is_file("{$artifactSiteDir}/index.php"), $failures, $passCount);
check('artifact present: the skip_when_artifact step did NOT run', !is_file($marker1b), $failures, $passCount);
check('artifact present: the normal (non-skip) step still ran', is_file($marker2b), $failures, $passCount);

$logWithArtifact = readHubLog($hub['logPath']);
$artifactStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-with-artifact/status' ? ($e['body']['status'] ?? null) : null, $logWithArtifact)));
check('artifact present: reports running then succeeded', $artifactStatuses === ['running', 'succeeded'], $failures, $passCount);

$artifactLogEntries = array_values(array_filter($logWithArtifact, static fn ($e) => $e['path'] === '/api/agent/jobs/job-with-artifact/log'));
check('artifact present: exactly 2 log entries (deploy artifact + the one non-skipped step)', count($artifactLogEntries) === 2, $failures, $passCount);
if (count($artifactLogEntries) >= 1) {
    check('artifact present: the first log entry is the artifact deploy step', ($artifactLogEntries[0]['body']['step_name'] ?? null) === 'deploy artifact', $failures, $passCount);
}

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 3: an artifact with a runtime_permissions typed step executes
// the fixed normalizer immediately after activation, before any application
// command, and skips that later typed step instead of running it twice.
// ============================================================
$normalizationMarker = $workspace . '/normalization.marker';
$applicationMarker = $workspace . '/application-after-normalization.marker';
$normalizationCalls = [];
$normalizationShouldFail = false;
$normalizationPipelineExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$normalizationCalls, &$normalizationShouldFail, $normalizationMarker): array {
        $normalizationCalls[] = [$projectKey, $workingDirectory, $timeoutSeconds];
        file_put_contents($normalizationMarker, 'normalized');

        return ['exitCode' => $normalizationShouldFail ? 1 : 0, 'output' => $normalizationShouldFail ? 'normalization failed' : 'normalized', 'timedOut' => false, 'durationMs' => 1];
    },
);

$orderedArtifactSiteDir = $workspace . '/ordered-artifact-site';
$orderedFixtureZip = buildFixtureZip($workspace, 'ordered-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-ordered', $orderedFixtureZip['path']);
$jobWithOrderedNormalization = [
    'id' => 'job-with-ordered-normalization',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('e', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-ordered', 'checksum_sha256' => $orderedFixtureZip['checksum'], 'size_bytes' => $orderedFixtureZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "const fs=require(\'fs\'); if(!fs.existsSync(process.argv[1])) process.exit(19); fs.writeFileSync(process.argv[2], \'ran\')" ' . escapeshellarg($normalizationMarker) . ' ' . escapeshellarg($applicationMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$normalizationPipelineExecutor->execute($jobWithOrderedNormalization, ['working_directory' => $orderedArtifactSiteDir], 30);

check('artifact runtime permissions: application command ran only after immediate normalization', is_file($applicationMarker), $failures, $passCount);
check('artifact runtime permissions: normalizer was invoked exactly once', count($normalizationCalls) === 1, $failures, $passCount);
check('artifact runtime permissions: normalizer received the project key before pipeline execution', ($normalizationCalls[0][0] ?? null) === 'deployment-system', $failures, $passCount);
$orderedLogEntries = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-with-ordered-normalization/log'));
check('artifact runtime permissions: Hub logs activation, immediate normalization, then the application command', array_map(static fn ($e) => $e['body']['step_name'] ?? null, $orderedLogEntries) === ['deploy artifact', 'runtime permissions (post-artifact)', 'migrate'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 4: immediate normalization failure stops the deployment after
// activation; no application pipeline step is allowed to run.
// ============================================================
@unlink($normalizationMarker);
$failedNormalizationMarker = $workspace . '/application-after-failed-normalization.marker';
$normalizationShouldFail = true;
$normalizationCalls = [];
$normalizationFailureSiteDir = $workspace . '/normalization-failure-site';
$normalizationFailureFixtureZip = buildFixtureZip($workspace, 'normalization-failure-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-normalization-failure', $normalizationFailureFixtureZip['path']);
$jobWithFailedNormalization = [
    'id' => 'job-with-failed-normalization',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('f', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-normalization-failure', 'checksum_sha256' => $normalizationFailureFixtureZip['checksum'], 'size_bytes' => $normalizationFailureFixtureZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($failedNormalizationMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$normalizationPipelineExecutor->execute($jobWithFailedNormalization, ['working_directory' => $normalizationFailureSiteDir], 30);

check('failed immediate normalization: no application command ran', !is_file($failedNormalizationMarker), $failures, $passCount);
$normalizationFailureStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-with-failed-normalization/status' ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('failed immediate normalization: reports running then failed', $normalizationFailureStatuses === ['running', 'failed'], $failures, $passCount);
$normalizationFailureLogs = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-with-failed-normalization/log'));
check('failed immediate normalization: logs activation and failed normalization only', array_map(static fn ($e) => $e['body']['step_name'] ?? null, $normalizationFailureLogs) === ['deploy artifact', 'runtime permissions (post-artifact)'], $failures, $passCount);
check('failed immediate normalization: normalizer was still invoked exactly once', count($normalizationCalls) === 1, $failures, $passCount);

// Persistent-runtime preparation happens before artifact preparation and
// activation even when the pipeline has no runtime_permissions typed step.
// A helper failure must leave the existing release byte-for-byte active.
@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');
$persistentFailureSite = $workspace . '/persistent-preflight-failure-site';
mkdir($persistentFailureSite, 0777, true);
file_put_contents($persistentFailureSite . '/sentinel.txt', 'current-release');
$persistentFailureFixture = buildFixtureZip($workspace, 'persistent-preflight-failure');
registerManifestEntry($hub['manifestPath'], 'artifact-persistent-preflight-failure', $persistentFailureFixture['path']);
$normalizationShouldFail = true;
$normalizationCalls = [];
$normalizationPipelineExecutor->execute([
    'id' => 'job-persistent-preflight-failure',
    'action' => 'deploy',
    'project_key' => 'customer-site',
    'commit_hash' => str_repeat('1', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-persistent-preflight-failure', 'checksum_sha256' => $persistentFailureFixture['checksum'], 'size_bytes' => $persistentFailureFixture['size']],
    'artifact_persistence' => ['paths' => ['public/uploads' => 'uploads'], 'root' => '/var/www/customer-site-shared'],
    'pipeline' => [],
], ['working_directory' => '/var/www/customer-site'], 30);
check('persistent preflight failure: existing release remains active', (string) @file_get_contents($persistentFailureSite . '/sentinel.txt') === 'current-release', $failures, $passCount);
check('persistent preflight failure: candidate artifact is never activated', !is_file($persistentFailureSite . '/index.php'), $failures, $passCount);
check('persistent preflight: customer layout resolves against /var/www/customer-site', ($normalizationCalls[0][1] ?? null) === '/var/www/customer-site', $failures, $passCount);
$persistentFailureLogs = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-persistent-preflight-failure/log'));
check('persistent preflight failure: only pre-activation preparation is logged', array_map(static fn ($e) => $e['body']['step_name'] ?? null, $persistentFailureLogs) === ['prepare persistent runtime'], $failures, $passCount);
$persistentFailureStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-persistent-preflight-failure/status' ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('persistent preflight failure: explicit failed state is reported', $persistentFailureStatuses === ['running', 'failed'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// A future `/current` layout must not be guessed from the relative mapping.
// It requires an explicit release-layout migration; reject it before the
// privileged helper, artifact preparation, or live filesystem is touched.
$normalizationShouldFail = false;
$normalizationCalls = [];
$normalizationPipelineExecutor->execute([
    'id' => 'job-customer-current-layout-rejected',
    'action' => 'deploy',
    'project_key' => 'customer-site',
    'commit_hash' => str_repeat('2', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-persistent-preflight-failure', 'checksum_sha256' => $persistentFailureFixture['checksum'], 'size_bytes' => $persistentFailureFixture['size']],
    'artifact_persistence' => ['paths' => ['public/uploads' => 'uploads'], 'root' => '/var/www/customer-site-shared'],
    'pipeline' => [],
], ['working_directory' => '/var/www/customer-site/current'], 30);
check('customer current layout: privileged helper is not invoked', $normalizationCalls === [], $failures, $passCount);
$invalidLayoutLogs = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-customer-current-layout-rejected/log'));
check('customer current layout: rejected before activation with explicit layout message', ($invalidLayoutLogs[0]['body']['failure_code'] ?? null) === 'artifact_persistent_config_invalid' && str_contains((string) ($invalidLayoutLogs[0]['body']['output'] ?? ''), '/current'), $failures, $passCount);
$invalidLayoutStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-customer-current-layout-rejected/status' ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('customer current layout: reports running then failed', $invalidLayoutStatuses === ['running', 'failed'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Local failure observability (CASE 1-6).  The Hub becomes unavailable
// (HTTP 500 / connection failure) the instant the post-artifact
// permission-normalization failure leaves the runtime tree owned by
// deployment-agent, which used to swallow the only copy of the helper's
// stderr diagnostic.  These scenarios prove the LOCAL logger now carries it
// before any Hub call, so it survives even when the Hub POSTs fail.
// ============================================================

// CASE 1 — success (reuses scenario 3's job, whose normalization succeeded):
// a successful post-artifact normalization must leave NO local ERROR line.
$case1Lines = readAgentLogLines($workspace . '/logs', 'job-with-ordered-normalization');
check('CASE 1: successful post-artifact normalization writes no local ERROR line', count(array_filter($case1Lines, static fn (string $l): bool => str_contains($l, '] ERROR '))) === 0, $failures, $passCount);

// CASE 2 — failure with diagnostic (reuses scenario 4's job, whose helper
// returned exit 1 + 'normalization failed'): the local ERROR line carries
// job id, project key, exit code, timed_out and the helper output.  The job
// still fails exactly as before (scenario 4 already proved no application
// pipeline step ran and the Hub status became 'failed').
$case2ErrorLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-with-failed-normalization'), static fn (string $l): bool => str_contains($l, '] ERROR ')));
$case2ErrorLine = implode("\n", $case2ErrorLines);
check('CASE 2: local ERROR line carries the job id', str_contains($case2ErrorLine, 'job-with-failed-normalization'), $failures, $passCount);
check('CASE 2: local ERROR line carries the project key', str_contains($case2ErrorLine, 'project=deployment-system'), $failures, $passCount);
check('CASE 2: local ERROR line carries the helper exit code', str_contains($case2ErrorLine, 'exit_code=1'), $failures, $passCount);
check('CASE 2: local ERROR line carries timed_out', str_contains($case2ErrorLine, 'timed_out=false'), $failures, $passCount);
check('CASE 2: local ERROR line carries the helper diagnostic output', str_contains($case2ErrorLine, 'output=normalization failed'), $failures, $passCount);
check('CASE 2: exactly one local ERROR line for the failed job', count($case2ErrorLines) === 1, $failures, $passCount);

// CASE 3a — Hub AgentJob log POST fails with HTTP 500 (the confirmed
// production symptom: the Hub is alive but unhealthy, so the diagnostic is
// never persisted centrally).  The local diagnostic must already exist, and
// the deployment must still fail.
$hubHttpFailDir = $workspace . '/hub-http-fail-site';
$hubHttpFailZip = buildFixtureZip($workspace, 'hub-http-fail-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-hub-http-fail', $hubHttpFailZip['path']);
$hubHttpFailMarker = $workspace . '/hub-http-fail.marker';
$hubHttpFailCalls = [];
$hubHttpFailExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$hubHttpFailCalls): array {
        $hubHttpFailCalls[] = $projectKey;

        return ['exitCode' => 1, 'output' => '[runtime-permissions] project=deployment-system phase=find_files path=/var/www/deployment-system/service/storage operation=chown exit_code=1', 'timedOut' => false, 'durationMs' => 1];
    },
);
$jobHubHttpFail = [
    'id' => 'job-hub-http-fail',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('g', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-hub-http-fail', 'checksum_sha256' => $hubHttpFailZip['checksum'], 'size_bytes' => $hubHttpFailZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($hubHttpFailMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$hubHttpFailExecutor->execute($jobHubHttpFail, ['working_directory' => $hubHttpFailDir], 30);

check('CASE 3 (HTTP 500): no application pipeline step ran', !is_file($hubHttpFailMarker), $failures, $passCount);
$hubHttpFailLogLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-hub-http-fail'), static fn (string $l): bool => str_contains($l, '] ERROR ') && str_contains($l, 'runtime permission normalization failed')));
check('CASE 3 (HTTP 500): local diagnostic still exists', count($hubHttpFailLogLines) === 1, $failures, $passCount);
check('CASE 3 (HTTP 500): local diagnostic carries the helper output', str_contains(implode("\n", $hubHttpFailLogLines), 'operation=chown'), $failures, $passCount);
$hubHttpFailStatuses = array_values(array_filter(array_map(static fn ($e) => str_contains($e['path'] ?? '', 'job-hub-http-fail/status') ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('CASE 3 (HTTP 500): failed status report was still attempted (Hub 500s it, as in production)', $hubHttpFailStatuses === ['running', 'failed'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// CASE 3b — Hub connection dies during the runtime-permissions /log POST
// (curl reports a transport error, so Client::request() throws).  The local
// diagnostic was written before the POST, so it survives; the exception
// still propagates and fails the job exactly as it did before this change.
$hubTransportFailDir = $workspace . '/hub-transport-fail-site';
$hubTransportFailZip = buildFixtureZip($workspace, 'hub-transport-fail-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-hub-transport-fail', $hubTransportFailZip['path']);
$hubTransportFailMarker = $workspace . '/hub-transport-fail.marker';
$hubTransportFailCalls = [];
$hubTransportFailExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$hubTransportFailCalls): array {
        $hubTransportFailCalls[] = $projectKey;

        return ['exitCode' => 1, 'output' => '[runtime-permissions] project=deployment-system phase=validate path=/var/www/deployment-system/service/database operation=chown exit_code=1', 'timedOut' => false, 'durationMs' => 1];
    },
);
$jobHubTransportFail = [
    'id' => 'job-hub-transport-fail',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('h', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-hub-transport-fail', 'checksum_sha256' => $hubTransportFailZip['checksum'], 'size_bytes' => $hubTransportFailZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($hubTransportFailMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$hubTransportThrew = false;
try {
    $hubTransportFailExecutor->execute($jobHubTransportFail, ['working_directory' => $hubTransportFailDir], 30);
} catch (\Throwable) {
    $hubTransportThrew = true;
}
check('CASE 3 (transport): Hub /log POST failure still fails the job (exception propagates as before)', $hubTransportThrew, $failures, $passCount);
check('CASE 3 (transport): no application pipeline step ran', !is_file($hubTransportFailMarker), $failures, $passCount);
$hubTransportFailLogLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-hub-transport-fail'), static fn (string $l): bool => str_contains($l, '] ERROR ') && str_contains($l, 'runtime permission normalization failed')));
check('CASE 3 (transport): local diagnostic still exists despite the throwing Hub POST', count($hubTransportFailLogLines) === 1, $failures, $passCount);
check('CASE 3 (transport): local diagnostic carries the helper output', str_contains(implode("\n", $hubTransportFailLogLines), 'phase=validate'), $failures, $passCount);

// CASE 4 — helper fails with empty stdout/stderr: the local log must say
// output=<empty> so "helper produced no diagnostic" is distinguishable from
// "transport lost the diagnostic".
$emptyDir = $workspace . '/empty-output-site';
$emptyZip = buildFixtureZip($workspace, 'empty-output-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-empty-output', $emptyZip['path']);
$emptyMarker = $workspace . '/empty-output.marker';
$emptyCalls = [];
$emptyExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$emptyCalls): array {
        $emptyCalls[] = $projectKey;

        return ['exitCode' => 2, 'output' => '', 'timedOut' => false, 'durationMs' => 1];
    },
);
$jobEmptyOutput = [
    'id' => 'job-empty-output',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('i', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-empty-output', 'checksum_sha256' => $emptyZip['checksum'], 'size_bytes' => $emptyZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($emptyMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$emptyExecutor->execute($jobEmptyOutput, ['working_directory' => $emptyDir], 30);
$emptyErrorLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-empty-output'), static fn (string $l): bool => str_contains($l, '] ERROR ')));
check('CASE 4: empty helper output is logged explicitly as output=<empty>', count($emptyErrorLines) === 1 && str_contains(implode("\n", $emptyErrorLines), 'output=<empty>'), $failures, $passCount);
check('CASE 4: original helper exit code is preserved', str_contains(implode("\n", $emptyErrorLines), 'exit_code=2'), $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// CASE 5 — helper returns very large diagnostic output: the local journal
// message is bounded to the 4 KiB cap (not the 50 KB the Hub truncation
// allows), so a misbehaving/looping helper cannot flood the journal.
$largeDir = $workspace . '/large-output-site';
$largeZip = buildFixtureZip($workspace, 'large-output-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-large-output', $largeZip['path']);
$largeMarker = $workspace . '/large-output.marker';
$largeCalls = [];
$largeOutput = str_repeat('x', 100000);
$largeExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$largeCalls, $largeOutput): array {
        $largeCalls[] = $projectKey;

        return ['exitCode' => 1, 'output' => $largeOutput, 'timedOut' => false, 'durationMs' => 1];
    },
);
$jobLargeOutput = [
    'id' => 'job-large-output',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('j', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-large-output', 'checksum_sha256' => $largeZip['checksum'], 'size_bytes' => $largeZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($largeMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$largeExecutor->execute($jobLargeOutput, ['working_directory' => $largeDir], 30);
$largeErrorLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-large-output'), static fn (string $l): bool => str_contains($l, '] ERROR ')));
$largeErrorLine = implode("\n", $largeErrorLines);
check('CASE 5: large helper output is bounded with an explicit truncation marker', count($largeErrorLines) === 1 && str_contains($largeErrorLine, '...[truncated]'), $failures, $passCount);
check('CASE 5: bounded journal line stays far below the raw 100 KB helper output', mb_strlen($largeErrorLine) < 5000, $failures, $passCount);

// CASE 6 — helper diagnostic on stderr.  Executor already merges child
// stdout+stderr into result['output'] (proved directly below), and the real
// helper writes every diagnostic to stderr (printf ... >&2); together these
// prove stderr reaches the local failure diagnostic without any executor
// redesign.
$stderrProbe = $executor->runArgv(
    [PHP_BINARY, '-r', 'fwrite(STDOUT, "stdout-probe"); fwrite(STDERR, "stderr-probe");'],
    sys_get_temp_dir(),
    10,
);
check('CASE 6: Executor merges child stderr into result["output"]', str_contains($stderrProbe['output'], 'stdout-probe') && str_contains($stderrProbe['output'], 'stderr-probe'), $failures, $passCount);

$stderrDir = $workspace . '/stderr-fail-site';
$stderrZip = buildFixtureZip($workspace, 'stderr-fail-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-stderr-fail', $stderrZip['path']);
$stderrMarker = $workspace . '/stderr-fail.marker';
$stderrCalls = [];
$stderrExecutor = new PipelineExecutor(
    $client,
    $executor,
    $artifactManager,
    $logger,
    static function (string $projectKey, string $workingDirectory, int $timeoutSeconds) use (&$stderrCalls): array {
        $stderrCalls[] = $projectKey;

        return ['exitCode' => 1, 'output' => '[runtime-permissions] project=deployment-system phase=find_files path=/var/www/deployment-system/service/database operation=chmod exit_code=1', 'timedOut' => false, 'durationMs' => 1];
    },
);
$jobStderrFail = [
    'id' => 'job-stderr-fail',
    'action' => 'deploy',
    'project_key' => 'deployment-system',
    'commit_hash' => str_repeat('k', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-stderr-fail', 'checksum_sha256' => $stderrZip['checksum'], 'size_bytes' => $stderrZip['size']],
    'pipeline' => [
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($stderrMarker)],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
    ],
];
$stderrExecutor->execute($jobStderrFail, ['working_directory' => $stderrDir], 30);
$stderrErrorLines = array_values(array_filter(readAgentLogLines($workspace . '/logs', 'job-stderr-fail'), static fn (string $l): bool => str_contains($l, '] ERROR ')));
check('CASE 6: stderr diagnostic reaches the local failure log', count($stderrErrorLines) === 1 && str_contains(implode("\n", $stderrErrorLines), 'phase=find_files') && str_contains(implode("\n", $stderrErrorLines), 'operation=chmod'), $failures, $passCount);

// ============================================================
// Scenario 5: artifact present but activation FAILS (checksum
// mismatch) — no pipeline step must run at all, not even a non-skip one.
// ============================================================
$failedSiteDir = $workspace . '/failed-site';
$fixtureZip2 = buildFixtureZip($workspace, 'bad-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-bad', $fixtureZip2['path']);

$marker2c = $workspace . '/failed-step2.marker';

$jobBadArtifact = [
    'id' => 'job-bad-artifact',
    'action' => 'deploy',
    'project_key' => 'artifact-project',
    'commit_hash' => str_repeat('c', 40),
    'branch' => 'main',
    // Deliberately wrong checksum — forces ArtifactManager to reject it.
    'artifact' => ['public_id' => 'artifact-bad', 'checksum_sha256' => str_repeat('0', 64), 'size_bytes' => $fixtureZip2['size']],
    'pipeline' => [
        ['name' => 'git pull', 'command' => 'node -e "process.exit(0)"', 'skip_when_artifact' => true],
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker2c)],
    ],
];
$pipelineExecutor->execute($jobBadArtifact, ['working_directory' => $failedSiteDir, 'allowed_services' => []], 30);

check('failed activation: the normal (non-skip) step never ran', !is_file($marker2c), $failures, $passCount);
check('failed activation: the target directory was never created', !is_dir($failedSiteDir), $failures, $passCount);

$logBadArtifact = readHubLog($hub['logPath']);
$badArtifactStatusEntries = array_values(array_filter($logBadArtifact, static fn ($e) => $e['path'] === '/api/agent/jobs/job-bad-artifact/status'));
// PipelineExecutor reports `running` unconditionally before it even
// looks at job['artifact'] (matching the pre-extraction behavior
// verbatim) — the requirement this scenario actually tests is "no
// PIPELINE STEP runs after a failed activation", not "no status report
// of any kind precedes the failure", so two entries (running, failed)
// is the correct, expected shape here.
check('failed activation: exactly two status reports (running, then failed) — no pipeline step ever ran', count($badArtifactStatusEntries) === 2, $failures, $passCount);
if (count($badArtifactStatusEntries) === 2) {
    check('failed activation: first report is running', ($badArtifactStatusEntries[0]['body']['status'] ?? null) === 'running', $failures, $passCount);
    check('failed activation: second report is failed', ($badArtifactStatusEntries[1]['body']['status'] ?? null) === 'failed', $failures, $passCount);
    check('failed activation: failure_code is artifact_checksum_mismatch', ($badArtifactStatusEntries[1]['body']['failure_code'] ?? null) === 'artifact_checksum_mismatch', $failures, $passCount);
}
$badArtifactLogEntries = array_values(array_filter($logBadArtifact, static fn ($e) => $e['path'] === '/api/agent/jobs/job-bad-artifact/log'));
check('failed activation: exactly one log entry (the failed artifact deploy step itself, no pipeline steps)', count($badArtifactLogEntries) === 1, $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 6: legacy pipeline, a step fails mid-way — subsequent steps
// never run (pre-existing behavior, reverified after the extraction).
// ============================================================
$failStopDir = $workspace . '/fail-stop-site';
mkdir($failStopDir, 0777, true);
$marker3 = $workspace . '/fail-stop-step2.marker';

$jobFailStop = [
    'id' => 'job-fail-stop',
    'action' => 'deploy',
    'project_key' => 'legacy-project',
    'commit_hash' => str_repeat('d', 40),
    'branch' => 'main',
    'artifact' => null,
    'pipeline' => [
        ['name' => 'broken step', 'command' => 'node -e "process.exit(1)"'],
        ['name' => 'never reached', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($marker3)],
    ],
];
$pipelineExecutor->execute($jobFailStop, ['working_directory' => $failStopDir], 30);

check('a failed step stops the pipeline — the next step never ran', !is_file($marker3), $failures, $passCount);
$logFailStop = readHubLog($hub['logPath']);
$failStopStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-fail-stop/status' ? ($e['body']['status'] ?? null) : null, $logFailStop)));
check('a failed step: final status is failed', end($failStopStatuses) === 'failed', $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 7: artifact with a before_activation maintenance entry — the
// step runs against the CURRENT release BEFORE the atomic swap, activation
// follows, and every later step runs against the NEW release. The closing
// `exit maintenance mode` re-enters the site after the swap (with
// persistent storage, maintenance survives the swap — this is the single
// guaranteed re-entry point).
// ============================================================
$maintenanceSiteDir = $workspace . '/maintenance-site';
mkdir($maintenanceSiteDir, 0777, true);
// Simulate the currently-active release's code (what maintenance entry must
// observe) versus the artifact's brand-new code (what post-activation steps
// must observe).
file_put_contents("{$maintenanceSiteDir}/index.php", "<?php // OLD RELEASE\n");
$maintenanceBeforeMarker = $workspace . '/maintenance-before.marker';
$maintenanceAfterMarker = $workspace . '/maintenance-after.marker';
$maintenanceExitMarker = $workspace . '/maintenance-exit.marker';

$maintenanceZip = buildFixtureZip($workspace, 'maintenance-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-maintenance', $maintenanceZip['path']);

$jobMaintenanceFirst = [
    'id' => 'job-maintenance-first',
    'action' => 'deploy',
    'project_key' => 'artifact-project',
    'commit_hash' => str_repeat('m', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-maintenance', 'checksum_sha256' => $maintenanceZip['checksum'], 'size_bytes' => $maintenanceZip['size']],
    'pipeline' => [
        // before_activation: must run while working_directory is STILL the
        // old release, i.e. it must capture the OLD index.php.
        ['name' => 'maintenance mode', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], require(\'fs\').readFileSync(process.cwd() + \'/index.php\'))" ' . escapeshellarg($maintenanceBeforeMarker), 'before_activation' => true],
        ['name' => 'git pull', 'command' => 'node -e "process.exit(0)"', 'skip_when_artifact' => true],
        // Post-activation: must observe the NEW release's index.php.
        ['name' => 'migrate', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], require(\'fs\').readFileSync(process.cwd() + \'/index.php\'))" ' . escapeshellarg($maintenanceAfterMarker)],
        ['name' => 'exit maintenance mode', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($maintenanceExitMarker)],
    ],
];
$pipelineExecutor->execute($jobMaintenanceFirst, ['working_directory' => $maintenanceSiteDir, 'allowed_services' => []], 30);

check('maintenance-first: the before_activation step ran against the CURRENT release', file_get_contents($maintenanceBeforeMarker) === "<?php // OLD RELEASE\n", $failures, $passCount);
check('maintenance-first: the previous release was renamed aside as one backup', count(glob("{$workspace}/maintenance-site.backup-*")) === 1, $failures, $passCount);
check('maintenance-first: post-activation steps run against the NEW release', file_get_contents($maintenanceAfterMarker) === "<?php\n// artifact content\n", $failures, $passCount);
check('maintenance-first: the closing exit-maintenance step ran', is_file($maintenanceExitMarker), $failures, $passCount);

$maintenanceLogEntries = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-maintenance-first/log'));
check('maintenance-first: Hub log order is maintenance entry, artifact activation, then post-activation steps', array_map(static fn ($e) => $e['body']['step_name'] ?? null, $maintenanceLogEntries) === ['maintenance mode', 'deploy artifact', 'migrate', 'exit maintenance mode'], $failures, $passCount);
$maintenanceStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-maintenance-first/status' ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('maintenance-first: reports running then succeeded', $maintenanceStatuses === ['running', 'succeeded'], $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');


// ============================================================
// Scenario 8: a pipeline step FAILS after activation — the agent restores
// the previous release (code rollback; persistent state is never touched)
// and runs the guaranteed maintenance cleanup (`artisan up`) so a failed
// deploy can never leave the site permanently down.
// ============================================================
$rollbackSiteDir = $workspace . '/rollback-site';
mkdir($rollbackSiteDir, 0777, true);
file_put_contents("{$rollbackSiteDir}/index.php", "<?php // OLD RELEASE\n");
// A present artisan binary lets the guaranteed cleanup actually run (it may
// exit non-zero here — this is not a real Laravel tree — but the cleanup
// report must still be posted to the Hub).
file_put_contents("{$rollbackSiteDir}/artisan", "<?php\n");
$rollbackMaintenanceMarker = $workspace . '/rollback-maintenance.marker';
$rollbackAfterMarker = $workspace . '/rollback-after.marker';

$rollbackZip = buildFixtureZip($workspace, 'rollback-artifact');
registerManifestEntry($hub['manifestPath'], 'artifact-rollback', $rollbackZip['path']);

$jobRollback = [
    'id' => 'job-rollback',
    'action' => 'deploy',
    'project_key' => 'artifact-project',
    'commit_hash' => str_repeat('r', 40),
    'branch' => 'main',
    'artifact' => ['public_id' => 'artifact-rollback', 'checksum_sha256' => $rollbackZip['checksum'], 'size_bytes' => $rollbackZip['size']],
    'pipeline' => [
        // The trailing `echo artisan down` literal is what the agent detects
        // (str_contains) to remember that maintenance mode was entered.
        ['name' => 'maintenance mode', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'down\')" ' . escapeshellarg($rollbackMaintenanceMarker) . ' && echo artisan down', 'before_activation' => true],
        ['name' => 'broken migrate', 'command' => 'node -e "process.exit(1)"'],
        ['name' => 'never reached', 'command' => 'node -e "require(\'fs\').writeFileSync(process.argv[1], \'ran\')" ' . escapeshellarg($rollbackAfterMarker)],
    ],
];
$pipelineExecutor->execute($jobRollback, ['working_directory' => $rollbackSiteDir, 'allowed_services' => []], 30);

check('rollback: maintenance was entered before activation', is_file($rollbackMaintenanceMarker), $failures, $passCount);
check('rollback: the post-failure step never ran', !is_file($rollbackAfterMarker), $failures, $passCount);
check('rollback: the previous release was restored as the live working directory', file_get_contents("{$rollbackSiteDir}/index.php") === "<?php // OLD RELEASE\n", $failures, $passCount);
check('rollback: the failed release left no backup or failed-release directory behind', count(glob("{$workspace}/rollback-site.backup-*")) === 0 && count(glob("{$workspace}/rollback-site.failed-*")) === 0, $failures, $passCount);

$rollbackLogEntries = array_values(array_filter(readHubLog($hub['logPath']), static fn ($e) => $e['path'] === '/api/agent/jobs/job-rollback/log'));
$rollbackStepNames = array_map(static fn ($e) => $e['body']['step_name'] ?? null, $rollbackLogEntries);
check('rollback: maintenance cleanup entry was reported (guaranteed artisan up)', in_array('maintenance cleanup', $rollbackStepNames, true), $failures, $passCount);
check('rollback: the failing step is the last application step in the log', array_slice($rollbackStepNames, 0, -1) === ['maintenance mode', 'deploy artifact', 'broken migrate'], $failures, $passCount);
$rollbackStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-rollback/status' ? ($e['body']['status'] ?? null) : null, readHubLog($hub['logPath']))));
check('rollback: final status is failed', end($rollbackStatuses) === 'failed', $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// ============================================================
// Scenario 9: the plain pipeline always performs an explicitly configured
// customer-facing HTTP check after its application commands. HTTP 200 is
// the only success path; HTTP 500 is reported with a structured health log
// and a failed terminal status.
// ============================================================
$healthDir = $workspace . '/health-site';
mkdir($healthDir, 0777, true);
$healthJob = [
    'id' => 'job-health-ok',
    'action' => 'deploy',
    'project_key' => 'health-site',
    'commit_hash' => str_repeat('1', 40),
    'branch' => 'main',
    'artifact' => null,
    'pipeline' => [['name' => 'commands pass', 'command' => 'node -e "process.exit(0)"']],
    'http_health_check' => [
        'enabled' => true,
        'url' => "http://127.0.0.1:{$hub['port']}/health-ok",
        'expected_status' => 200,
        'timeout_seconds' => 2,
        'retries' => 0,
        'retry_interval_seconds' => 0,
        'allowed_redirects' => false,
        'expected_content' => 'customer-homepage-marker',
    ],
];
$pipelineExecutor->execute($healthJob, ['working_directory' => $healthDir, 'allowed_services' => []], 30);
$healthOkEvents = readHubLog($hub['logPath']);
$healthOkLog = array_values(array_filter($healthOkEvents, static fn ($e) => $e['path'] === '/api/agent/jobs/job-health-ok/log' && ($e['body']['step_type'] ?? null) === 'http_health_check'));
$healthOkStatuses = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-health-ok/status' ? ($e['body']['status'] ?? null) : null, $healthOkEvents)));
check('pipeline HTTP 200 records a successful explicit health step', count($healthOkLog) === 1 && $healthOkLog[0]['body']['exit_code'] === 0, $failures, $passCount);
check('pipeline HTTP 200 permits terminal success', end($healthOkStatuses) === 'succeeded', $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');
$healthJob['id'] = 'job-health-500';
$healthJob['http_health_check']['url'] = "http://127.0.0.1:{$hub['port']}/health-error";
$healthJob['http_health_check']['expected_content'] = null;
$pipelineExecutor->execute($healthJob, ['working_directory' => $healthDir, 'allowed_services' => []], 30);
$healthErrorEvents = readHubLog($hub['logPath']);
$healthErrorLog = array_values(array_filter($healthErrorEvents, static fn ($e) => $e['path'] === '/api/agent/jobs/job-health-500/log' && ($e['body']['step_type'] ?? null) === 'http_health_check'));
$healthErrorStatus = array_values(array_filter(array_map(static fn ($e) => $e['path'] === '/api/agent/jobs/job-health-500/status' ? $e['body'] : null, $healthErrorEvents)));
check('pipeline HTTP 500 records the actual health failure', count($healthErrorLog) === 1 && $healthErrorLog[0]['body']['exit_code'] === 1 && str_contains($healthErrorLog[0]['body']['output'], 'got 500'), $failures, $passCount);
check('pipeline HTTP 500 blocks success with health_check_failed', end($healthErrorStatus)['status'] === 'failed' && end($healthErrorStatus)['failure_code'] === 'health_check_failed', $failures, $passCount);

@unlink($hub['logPath']);
file_put_contents($hub['logPath'], '');

// Upgrade bridge: Hub 1.3.1 sent signed persistence without the matching
// preserve list. Server Agent 1.3.2 must retain .env while dropping only
// stale local preserve entries owned by persistent paths.
$overlapFilter = new ReflectionMethod(PipelineExecutor::class, 'withoutPersistentOverlaps');
$overlapFilter->setAccessible(true);
$filteredPreserve = $overlapFilter->invoke(null, ['.env', 'database/database.sqlite'], ['database', 'storage/app/artifacts']);
check('partial signed persistence keeps disjoint .env and removes stale database preserve overlap', $filteredPreserve === ['.env'], $failures, $passCount);

// Typed privileged operations are a closed argv allowlist.  This source-level
// contract test is intentionally platform-independent: Windows CI has no
// sudo, while production must never substitute a shell command here.
$pipelineSource = (string) file_get_contents(__DIR__ . '/../lib/PipelineExecutor.php');
check('runtime_permissions uses only its fixed helper argv', str_contains($pipelineSource, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$job['project_key']]"), $failures, $passCount);
check('immediate runtime_permissions keeps the fixed helper argv', str_contains($pipelineSource, "return \$this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$projectKey]"), $failures, $passCount);
check('service_reload uses only its fixed helper argv', str_contains($pipelineSource, "['sudo', '-n', '/usr/local/sbin/deployment-service-reload', \$job['project_key']]"), $failures, $passCount);
check('typed service reload does not expose direct systemctl', !str_contains($pipelineSource, "['systemctl'"), $failures, $passCount);
// The local failure diagnostic is written before the Hub AgentJob log POST
// for the immediate runtime-permissions step, so a failing Hub request can
// never erase it (it is also written before any throw the POST could raise).
$localDiagnosticPos = strpos($pipelineSource, 'formatRuntimePermissionsFailureOutput(');
$runtimePermsHubLogPos = strpos($pipelineSource, "'runtime permissions (post-artifact)'");
check('local runtime-permissions diagnostic precedes the Hub AgentJob log POST', $localDiagnosticPos !== false && $runtimePermsHubLogPos !== false && $localDiagnosticPos < $runtimePermsHubLogPos, $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
