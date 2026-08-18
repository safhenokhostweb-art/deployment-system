<?php

declare(strict_types=1);

/**
 * Upgrade-compatibility regression test for agent identity resolution
 * (agent.php). An official in-place upgrade preserves config.php untouched
 * (install/upgrade-runtime.sh), so a legacy config whose only identity key
 * is `local_agent_id` — the key the predecessor Local-Agent wrote — must
 * still boot and poll the Hub with that exact identity instead of
 * crash-looping on "config.php requires agent_id". Canonical `agent_id`
 * keeps precedence, and legacy `server_id` keeps its existing fallback
 * position ahead of `local_agent_id`.
 *
 * Runs a throwaway fake Hub (php -S) and boots the real agent.php --once
 * against it with a temporary, git-ignored config.php; the agent/ config
 * file is removed afterwards. Refuses to run if a real config.php exists.
 *
 * Usage: php tests/run_identity_compat.php
 */

$agentDir = dirname(__DIR__);
$agentPhp = $agentDir . '/agent.php';
$configPath = $agentDir . '/config.php';

if (is_file($configPath)) {
    fwrite(STDERR, "Refusing to run: a real agent/config.php already exists — this test needs that path for a temporary config.\n");
    exit(2);
}

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

$tmp = sys_get_temp_dir() . '/identity-compat-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$logDir = $tmp . '/logs';
mkdir($logDir, 0700, true);
$routerPath = $tmp . '/router.php';
$serverLogPath = $tmp . '/server.log';
$serverStdioPath = $tmp . '/server-stdio.log';
$port = 24000 + random_int(0, 6000);

// Minimal fake Hub: records the identity header for every request and
// answers an empty job queue. The agent never needs valid signatures here —
// with `{"job": null}` it completes a clean single poll cycle.
file_put_contents($routerPath, <<<'PHP'
<?php
$entry = json_encode([
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'identity' => $_SERVER['HTTP_X_DEPLOYMENT_SERVER_ID'] ?? '',
], JSON_UNESCAPED_SLASHES);
@file_put_contents((string) getenv('FAKE_SERVER_LOG'), $entry . "\n", FILE_APPEND);
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (str_ends_with($uri, '/api/agent/jobs/next') || str_ends_with($uri, '/api/agent/operation-jobs/next')) {
    echo '{"job": null, "signature": ""}';
} else {
    echo '{}';
}
PHP);

$descriptorSpec = [1 => ['file', $serverStdioPath, 'w'], 2 => ['file', $serverStdioPath, 'w']];
$env = array_merge(getenv() ?: [], ['FAKE_SERVER_LOG' => $serverLogPath]);
$serverProcess = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerPath], $descriptorSpec, $serverPipes, $tmp, $env);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "Could not start fake Hub server.\n");
    exit(2);
}

$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn !== false) {
        fclose($conn);
        break;
    }
    usleep(100000);
}
if ($conn === false) {
    fwrite(STDERR, "Fake Hub server did not start listening in time.\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    exit(2);
}

/**
 * Writes a temporary config.php (identity key(s) given by $identityLines) and
 * boots the real agent.php --once against the fake Hub.
 *
 * @param array<int, string> $identityLines
 * @return array{exitCode: int, output: string}
 */
function bootAgentOnce(array $identityLines, string $agentPhp, string $configPath, string $logDir, int $port, string $agentDir): array
{
    $config = <<<PHP
    <?php
    return [
    'service_base_url' => 'http://127.0.0.1:{$port}',
    'agent_token' => 'test-token',
    'signing_secret' => 'test-secret',
    'poll_interval_seconds' => 1,
    'request_timeout_seconds' => 5,
    'default_step_timeout_seconds' => 10,
    'log_dir' => '{$logDir}',
    'projects' => [],
PHP;
    foreach ($identityLines as $line) {
        $config .= "\n    " . $line . ',';
    }
    $config .= "\n];\n";
    file_put_contents($configPath, $config);

    $pipes = null;
    $process = proc_open(
        [PHP_BINARY, $agentPhp, '--once'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $agentDir,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start agent.php subprocess.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);


    return ['exitCode' => $exitCode, 'output' => ($stdout ?: '') . ($stderr ?: '')];
}

/** @return array<int, array{uri: string, identity: string}> */
function readServerRequests(string $serverLogPath): array
{
    $requests = [];
    foreach (file($serverLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $requests[] = ['uri' => (string) ($decoded['uri'] ?? ''), 'identity' => (string) ($decoded['identity'] ?? '')];
        }
    }

    return $requests;
}

/** @return array<int, array{uri: string, identity: string}> */
function jobsNextRequests(array $requests): array
{
    return array_values(array_filter(
        $requests,
        static fn (array $r): bool => str_ends_with($r['uri'], '/api/agent/jobs/next'),
    ));
}

try {
    // Scenario 1 — the production regression: legacy config whose ONLY
    // identity key is `local_agent_id` must boot and poll with that identity.
    $run = bootAgentOnce(["'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $port, $agentDir);
    check('legacy local_agent_id-only config boots (exit 0, not the identity error exit 1)', $run['exitCode'] === 0, $failures, $passCount);
    check('legacy local_agent_id-only config does not crash-loop on the identity error', !str_contains($run['output'], 'requires agent_id'), $failures, $passCount);
    $requests = jobsNextRequests(readServerRequests($serverLogPath));
    check(
        'legacy local_agent_id-only config polls with the exact legacy identity',
        $requests !== [] && $requests[count($requests) - 1]['identity'] === 'legacy-local-agent-42',
        $failures,
        $passCount,
    );

    // Scenario 2 — canonical `agent_id` still wins when the legacy key is also present.
    $run = bootAgentOnce(["'agent_id' => 'canonical-agent-7'", "'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $port, $agentDir);
    check('canonical agent_id still boots an upgrade config', $run['exitCode'] === 0, $failures, $passCount);
    $requests = jobsNextRequests(readServerRequests($serverLogPath));
    check(
        'canonical agent_id takes precedence over the legacy local_agent_id key',
        $requests !== [] && $requests[count($requests) - 1]['identity'] === 'canonical-agent-7',
        $failures,
        $passCount,
    );

    // Scenario 3 — legacy `server_id` keeps its existing fallback position ahead of `local_agent_id`.
    $run = bootAgentOnce(["'server_id' => 'legacy-server-9'", "'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $port, $agentDir);
    check('legacy server_id config still boots an upgrade config', $run['exitCode'] === 0, $failures, $passCount);
    $requests = jobsNextRequests(readServerRequests($serverLogPath));
    check(
        'legacy server_id takes precedence over the legacy local_agent_id key',
        $requests !== [] && $requests[count($requests) - 1]['identity'] === 'legacy-server-9',
        $failures,
        $passCount,
    );
} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    @unlink($configPath);
    // Best-effort cleanup of the temporary directory tree.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($tmp);
}

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
