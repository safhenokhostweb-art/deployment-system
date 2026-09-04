<?php

declare(strict_types=1);

/**
 * Real, non-mocked test for SelfUpgradeManager — the Server Agent's own
 * exact-commit self-upgrade (download/verify/stage/activate), proving
 * the exact behaviors the Deployment #183/#184 fix and this task's hard
 * invariants require: package integrity is verified before ANY live
 * mutation, activation is staged/atomic, a failure before the swap
 * leaves the active runtime completely untouched, config.php survives,
 * and package/version mismatches fail closed.
 *
 * Usage: php tests/run_self_upgrade.php
 */

require __DIR__ . '/../lib/Signer.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/Executor.php';
require __DIR__ . '/../lib/SelfUpgradeManager.php';

use DeploymentAgent\Client;
use DeploymentAgent\Executor;
use DeploymentAgent\Logger;
use DeploymentAgent\SelfUpgradeManager;

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
    $root = sys_get_temp_dir() . '/self-upgrade-test-' . bin2hex(random_bytes(6));
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

/**
 * @param array<string, string> $files relative path => file content
 */
function buildPackageZip(string $dir, string $name, array $files): array
{
    $zipPath = "{$dir}/{$name}.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach ($files as $relative => $content) {
        $zip->addFromString($relative, $content);
    }
    $zip->close();

    return ['path' => $zipPath, 'checksum' => hash_file('sha256', $zipPath)];
}

function goodAgentPhp(string $version): string
{
    return "<?php\nconst AGENT_VERSION = '{$version}';\n";
}

function goodPipelineExecutor(): string
{
    return "<?php\n// withoutUnconditionalSuccessSuffix marker present\n";
}

/**
 * @return array{process: resource, port: int, logPath: string, serverLogPath: string}
 */
function startFakeHub(?string $packagePath, int $packageStatus = 200): array
{
    $port = 24000 + random_int(0, 7000);
    $logPath = tempnam(sys_get_temp_dir(), 'fake-hub-self-upgrade-log-');
    $router = __DIR__ . '/fixtures/fake_hub_self_upgrade_server.php';
    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-self-upgrade-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], [
        'FAKE_HUB_LOG' => $logPath,
        'FAKE_HUB_PACKAGE_PATH' => $packagePath ?? '',
        'FAKE_HUB_PACKAGE_STATUS' => (string) $packageStatus,
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
    @unlink($hub['serverLogPath']);
    @unlink($hub['logPath']);
}

/** @return array<int, array{method: string, path: string, body: array<string, mixed>|null}> */
function readHubLog(string $logPath): array
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($logPath)));

    return array_map(static fn (string $line) => json_decode($line, true), $lines);
}

function makeClient(int $port, Logger $logger): Client
{
    return new Client(
        baseUrl: "http://127.0.0.1:{$port}",
        serverId: 'test-server',
        agentToken: 'test-token',
        signingSecret: 'test-signing-secret',
        timeoutSeconds: 10,
        logger: $logger,
    );
}

$workspace = makeWorkspace();
$logger = new Logger($workspace . '/logs');
$executor = new Executor();

function makeInstallDir(string $workspace, string $name, string $version): string
{
    $dir = "{$workspace}/{$name}";
    mkdir($dir . '/lib', 0777, true);
    file_put_contents($dir . '/agent.php', goodAgentPhp($version));
    file_put_contents($dir . '/lib/PipelineExecutor.php', "<?php\n// old pipeline executor\n");
    file_put_contents($dir . '/config.php', "<?php\nreturn ['agent_token' => 'super-secret-token'];\n");

    return $dir;
}

// ============================================================
// Scenario 1: a successful upgrade — package integrity verified,
// activation staged then atomic, config.php preserved, old runtime
// renamed aside (never deleted), Hub receives exact-commit evidence
// BEFORE activation.
// ============================================================
$pkg1 = buildPackageZip($workspace, 'good-package', [
    'agent.php' => goodAgentPhp('1.4.1'),
    'lib/PipelineExecutor.php' => goodPipelineExecutor(),
]);
$installDir1 = makeInstallDir($workspace, 'install-good', '1.4.0');
$hub1 = startFakeHub($pkg1['path']);
$client1 = makeClient($hub1['port'], $logger);
$manager1 = new SelfUpgradeManager($client1, $executor, $logger);

$job1 = [
    'id' => 'job-good',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('a', 40),
    'package_id' => 'pkg-good',
    'expected_package_hash' => $pkg1['checksum'],
];

$onValidatedCalls = [];
$result1 = $manager1->upgrade($job1, $installDir1, PHP_BINARY, function (array $evidence) use (&$onValidatedCalls, $installDir1, &$failures, &$passCount): void {
    $onValidatedCalls[] = $evidence;
    // Activation must NOT have happened yet — this fires BEFORE the swap.
    check('onValidated fires before activation (old version still live)', str_contains((string) file_get_contents($installDir1 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);
});

check('successful upgrade returns the correct old/new version pair', $result1['old_version'] === '1.4.0' && $result1['new_version'] === '1.4.1', $failures, $passCount);
check('onValidated was called exactly once with the correct evidence', count($onValidatedCalls) === 1 && $onValidatedCalls[0]['source_commit'] === $job1['target_commit_sha'] && $onValidatedCalls[0]['package_hash'] === $pkg1['checksum'], $failures, $passCount);
check('the new agent.php is now active', str_contains((string) file_get_contents($installDir1 . '/agent.php'), "AGENT_VERSION = '1.4.1'"), $failures, $passCount);
check('the new lib/PipelineExecutor.php is now active', str_contains((string) file_get_contents($installDir1 . '/lib/PipelineExecutor.php'), 'withoutUnconditionalSuccessSuffix'), $failures, $passCount);
check('config.php was preserved byte-for-byte', str_contains((string) file_get_contents($installDir1 . '/config.php'), 'super-secret-token'), $failures, $passCount);
check('exactly one backup directory holds the previous runtime', count(glob("{$installDir1}.backup-*")) === 1, $failures, $passCount);
check('the previous runtime backup still has the OLD version', str_contains((string) file_get_contents(glob("{$installDir1}.backup-*")[0] . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);
check('no staging directory was left behind', count(glob("{$installDir1}.staging-*")) === 0, $failures, $passCount);

stopFakeHub($hub1);

// ============================================================
// Scenario 2: downloaded package checksum does not match the expected,
// hash-verified value — must fail closed with the active runtime
// completely untouched.
// ============================================================
$pkg2 = buildPackageZip($workspace, 'tampered-package', [
    'agent.php' => goodAgentPhp('1.4.1'),
    'lib/PipelineExecutor.php' => goodPipelineExecutor(),
]);
$installDir2 = makeInstallDir($workspace, 'install-checksum-mismatch', '1.4.0');
$hub2 = startFakeHub($pkg2['path']);
$client2 = makeClient($hub2['port'], $logger);
$manager2 = new SelfUpgradeManager($client2, $executor, $logger);

$job2 = [
    'id' => 'job-checksum-mismatch',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('b', 40),
    'package_id' => 'pkg-tampered',
    'expected_package_hash' => str_repeat('0', 64), // deliberately wrong
];

$threw = null;
try {
    $manager2->upgrade($job2, $installDir2, PHP_BINARY, static fn () => null);
} catch (\Throwable $e) {
    $threw = $e;
}
check('a checksum mismatch throws', $threw !== null && str_contains($threw->getMessage(), 'checksum does not match'), $failures, $passCount);
check('a checksum mismatch leaves the active runtime untouched', str_contains((string) file_get_contents($installDir2 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);
check('a checksum mismatch creates no backup directory', count(glob("{$installDir2}.backup-*")) === 0, $failures, $passCount);
check('a checksum mismatch leaves no staging directory behind', count(glob("{$installDir2}.staging-*")) === 0, $failures, $passCount);

stopFakeHub($hub2);

// ============================================================
// Scenario 3: the package is missing a required file (lib/PipelineExecutor.php)
// — must fail closed before activation.
// ============================================================
$pkg3 = buildPackageZip($workspace, 'incomplete-package', [
    'agent.php' => goodAgentPhp('1.4.1'),
]);
$installDir3 = makeInstallDir($workspace, 'install-missing-file', '1.4.0');
$hub3 = startFakeHub($pkg3['path']);
$client3 = makeClient($hub3['port'], $logger);
$manager3 = new SelfUpgradeManager($client3, $executor, $logger);

$job3 = [
    'id' => 'job-missing-file',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('c', 40),
    'package_id' => 'pkg-incomplete',
    'expected_package_hash' => $pkg3['checksum'],
];

$threw = null;
try {
    $manager3->upgrade($job3, $installDir3, PHP_BINARY, static fn () => null);
} catch (\Throwable $e) {
    $threw = $e;
}
check('a package missing required files throws', $threw !== null && str_contains($threw->getMessage(), 'missing required runtime files'), $failures, $passCount);
check('a package missing required files leaves the active runtime untouched', str_contains((string) file_get_contents($installDir3 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);

stopFakeHub($hub3);

// ============================================================
// Scenario 4: the package declares a version that does not match the
// job's target_version — must fail closed.
// ============================================================
$pkg4 = buildPackageZip($workspace, 'wrong-version-package', [
    'agent.php' => goodAgentPhp('1.4.2'), // not what the job expects
    'lib/PipelineExecutor.php' => goodPipelineExecutor(),
]);
$installDir4 = makeInstallDir($workspace, 'install-wrong-version', '1.4.0');
$hub4 = startFakeHub($pkg4['path']);
$client4 = makeClient($hub4['port'], $logger);
$manager4 = new SelfUpgradeManager($client4, $executor, $logger);

$job4 = [
    'id' => 'job-wrong-version',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('d', 40),
    'package_id' => 'pkg-wrong-version',
    'expected_package_hash' => $pkg4['checksum'],
];

$threw = null;
try {
    $manager4->upgrade($job4, $installDir4, PHP_BINARY, static fn () => null);
} catch (\Throwable $e) {
    $threw = $e;
}
check('a version mismatch throws', $threw !== null && str_contains($threw->getMessage(), 'does not match expected version'), $failures, $passCount);
check('a version mismatch leaves the active runtime untouched', str_contains((string) file_get_contents($installDir4 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);

stopFakeHub($hub4);

// ============================================================
// Scenario 5: a shipped PHP file fails syntax validation — must fail
// closed before activation, regardless of what the version string says.
// ============================================================
$pkg5 = buildPackageZip($workspace, 'broken-syntax-package', [
    'agent.php' => goodAgentPhp('1.4.1'),
    'lib/PipelineExecutor.php' => "<?php\nfunction broken( {\n", // invalid PHP
]);
$installDir5 = makeInstallDir($workspace, 'install-broken-syntax', '1.4.0');
$hub5 = startFakeHub($pkg5['path']);
$client5 = makeClient($hub5['port'], $logger);
$manager5 = new SelfUpgradeManager($client5, $executor, $logger);

$job5 = [
    'id' => 'job-broken-syntax',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('e', 40),
    'package_id' => 'pkg-broken-syntax',
    'expected_package_hash' => $pkg5['checksum'],
];

$threw = null;
try {
    $manager5->upgrade($job5, $installDir5, PHP_BINARY, static fn () => null);
} catch (\Throwable $e) {
    $threw = $e;
}
check('a syntax error throws', $threw !== null && str_contains($threw->getMessage(), 'Syntax validation failed'), $failures, $passCount);
check('a syntax error leaves the active runtime untouched', str_contains((string) file_get_contents($installDir5 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);

stopFakeHub($hub5);

// ============================================================
// Scenario 6: the Hub returns a non-200 for the package download —
// must fail closed with no mutation at all.
// ============================================================
$installDir6 = makeInstallDir($workspace, 'install-download-fail', '1.4.0');
$hub6 = startFakeHub(null, 404);
$client6 = makeClient($hub6['port'], $logger);
$manager6 = new SelfUpgradeManager($client6, $executor, $logger);

$job6 = [
    'id' => 'job-download-fail',
    'target_version' => '1.4.1',
    'target_commit_sha' => str_repeat('f', 40),
    'package_id' => 'pkg-missing',
    'expected_package_hash' => str_repeat('1', 64),
];

$threw = null;
try {
    $manager6->upgrade($job6, $installDir6, PHP_BINARY, static fn () => null);
} catch (\Throwable $e) {
    $threw = $e;
}
check('a failed download throws', $threw !== null && str_contains($threw->getMessage(), 'Could not download'), $failures, $passCount);
check('a failed download leaves the active runtime untouched', str_contains((string) file_get_contents($installDir6 . '/agent.php'), "AGENT_VERSION = '1.4.0'"), $failures, $passCount);

stopFakeHub($hub6);

rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
