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
require __DIR__ . '/../lib/PipelineExecutor.php';

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
 * @return array{process: resource, port: int, logPath: string, manifestPath: string}
 */
function startFakeHub(): array
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
    $env = array_merge(getenv() ?: [], ['FAKE_HUB_LOG' => $logPath, 'ARTIFACT_MANIFEST_PATH' => $manifestPath]);
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

$workspace = makeWorkspace();
$hub = startFakeHub();
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
// Scenario 3: artifact present but activation FAILS (checksum
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
// Scenario 4: legacy pipeline, a step fails mid-way — subsequent steps
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

// Typed privileged operations are a closed argv allowlist.  This source-level
// contract test is intentionally platform-independent: Windows CI has no
// sudo, while production must never substitute a shell command here.
$pipelineSource = (string) file_get_contents(__DIR__ . '/../lib/PipelineExecutor.php');
check('runtime_permissions uses only its fixed helper argv', str_contains($pipelineSource, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$job['project_key']]"), $failures, $passCount);
check('service_reload uses only its fixed helper argv', str_contains($pipelineSource, "['sudo', '-n', '/usr/local/sbin/deployment-service-reload', \$job['project_key']]"), $failures, $passCount);
check('typed service reload does not expose direct systemctl', !str_contains($pipelineSource, "['systemctl'"), $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
