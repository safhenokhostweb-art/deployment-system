<?php

declare(strict_types=1);

/**
 * Upgrade-compatibility regression test for agent config resolution
 * (agent.php). An official in-place upgrade preserves config.php untouched
 * (install/upgrade-runtime.sh), so a legacy predecessor Local-Agent config —
 * whose keys are `hub_url`, `local_agent_id`, `agent_token`,
 * `signing_secret`, `poll_interval_seconds`, `request_timeout_seconds`,
 * `projects`, `log_dir` (no `service_base_url`, no `default_step_timeout_seconds`,
 * no `agent_id`/`server_id`) — must still boot and poll the Hub with that
 * exact identity instead of crash-looping. Canonical keys win when both the
 * canonical and the legacy form exist.
 *
 * Runs two throwaway fake Hubs (php -S) and boots the real agent.php --once
 * against them with a temporary, git-ignored config.php; the agent/ config
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

/**
 * Starts a throwaway fake Hub that records the identity header for every
 * request and answers an empty job queue. The agent never needs valid
 * signatures here — with `{"job": null}` it completes a clean single poll.
 *
 * @return array{process: resource, port: int, serverLogPath: string}
 */
function startFakeHub(string $tmp, string $tag): array
{
    $routerPath = $tmp . '/router-' . $tag . '.php';
    $serverLogPath = $tmp . '/server-' . $tag . '.log';
    $serverStdioPath = $tmp . '/server-' . $tag . '-stdio.log';
    $port = 24000 + random_int(0, 6000);

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
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerPath], $descriptorSpec, $serverPipes, $tmp, $env);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'serverLogPath' => $serverLogPath];
        }
        usleep(100000);
    }

    throw new RuntimeException('Fake Hub server did not start listening in time.');
}

/** @param array{process: resource, port: int, serverLogPath: string}|null $hub */
function stopFakeHub(?array $hub): void
{
    if ($hub !== null && is_resource($hub['process'])) {
        proc_terminate($hub['process']);
        proc_close($hub['process']);
    }
}

/**
 * Writes a temporary config.php (URL lines + identity lines given) and boots
 * the real agent.php --once. The template deliberately contains none of the
 * keys under test (service_base_url / hub_url, agent_id / server_id /
 * local_agent_id, default_step_timeout_seconds) — each scenario supplies
 * exactly the lines it wants.
 *
 * @param array<int, string> $urlLines
 * @param array<int, string> $identityLines
 * @return array{exitCode: int, output: string}
 */
function bootAgentOnce(array $urlLines, array $identityLines, string $agentPhp, string $configPath, string $logDir, string $agentDir): array
{
    $config = "<?php\nreturn [\n";
    $config .= "    'agent_token' => 'test-token',\n";
    $config .= "    'signing_secret' => 'test-secret',\n";
    $config .= "    'poll_interval_seconds' => 1,\n";
    $config .= "    'request_timeout_seconds' => 5,\n";
    $config .= "    'log_dir' => '{$logDir}',\n";
    $config .= "    'projects' => [],\n";
    foreach ($urlLines as $line) {
        $config .= '    ' . $line . ",\n";
    }
    foreach ($identityLines as $line) {
        $config .= '    ' . $line . ",\n";
    }
    $config .= "];\n";
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

/**
 * @param array<int, array{uri: string, identity: string}> $requests
 * @return string|null the identity of the LAST /api/agent/jobs/next request, or null if none
 */
function jobsNextIdentity(array $requests): ?string
{
    foreach (array_reverse($requests) as $request) {
        if (str_ends_with($request['uri'], '/api/agent/jobs/next')) {
            return $request['identity'];
        }
    }

    return null;
}

try {
    $canonicalHub = null;
    $legacyHub = null;
    $canonicalHub = startFakeHub($tmp, 'canonical');
    $legacyHub = startFakeHub($tmp, 'legacy');

    $canonicalUrl = "'service_base_url' => 'http://127.0.0.1:{$canonicalHub['port']}'";
    $legacyUrl = "'hub_url' => 'http://127.0.0.1:{$legacyHub['port']}'";

    // Scenario 1 — identity compatibility: legacy config whose ONLY identity
    // key is `local_agent_id` must boot and poll with that identity.
    $run = bootAgentOnce([$canonicalUrl], ["'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $agentDir);
    check('legacy local_agent_id-only config boots (exit 0, not the identity error exit 1)', $run['exitCode'] === 0, $failures, $passCount);
    check('legacy local_agent_id-only config does not crash-loop on the identity error', !str_contains($run['output'], 'requires agent_id'), $failures, $passCount);
    check(
        'legacy local_agent_id-only config polls with the exact legacy identity',
        jobsNextIdentity(readServerRequests($canonicalHub['serverLogPath'])) === 'legacy-local-agent-42',
        $failures,
        $passCount,
    );

    // Scenario 2 — canonical `agent_id` still wins when the legacy key is also present.
    $run = bootAgentOnce([$canonicalUrl], ["'agent_id' => 'canonical-agent-7'", "'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $agentDir);
    check('canonical agent_id still boots an upgrade config', $run['exitCode'] === 0, $failures, $passCount);
    check(
        'canonical agent_id takes precedence over the legacy local_agent_id key',
        jobsNextIdentity(readServerRequests($canonicalHub['serverLogPath'])) === 'canonical-agent-7',
        $failures,
        $passCount,
    );

    // Scenario 3 — legacy `server_id` keeps its fallback position ahead of `local_agent_id`.
    $run = bootAgentOnce([$canonicalUrl], ["'server_id' => 'legacy-server-9'", "'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $agentDir);
    check('legacy server_id config still boots an upgrade config', $run['exitCode'] === 0, $failures, $passCount);
    check(
        'legacy server_id takes precedence over the legacy local_agent_id key',
        jobsNextIdentity(readServerRequests($canonicalHub['serverLogPath'])) === 'legacy-server-9',
        $failures,
        $passCount,
    );

    // Scenario 4 — the EXACT production legacy key set: `hub_url` in place of
    // service_base_url, no default_step_timeout_seconds, no agent_id/server_id.
    $canonicalBefore = count(readServerRequests($canonicalHub['serverLogPath']));
    $run = bootAgentOnce([$legacyUrl], ["'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $agentDir);
    check('exact production legacy config boots (exit 0)', $run['exitCode'] === 0, $failures, $passCount);
    check('exact production legacy config raises no undefined-key crash', !str_contains($run['output'], 'Undefined array key'), $failures, $passCount);
    check(
        'exact production legacy config polls via the legacy hub_url',
        jobsNextIdentity(readServerRequests($legacyHub['serverLogPath'])) === 'legacy-local-agent-42',
        $failures,
        $passCount,
    );
    check(
        'exact production legacy config never uses a service_base_url endpoint',
        count(readServerRequests($canonicalHub['serverLogPath'])) === $canonicalBefore,
        $failures,
        $passCount,
    );

    // Scenario 5 — canonical `service_base_url` beats legacy `hub_url` when both exist.
    $legacyBefore = count(readServerRequests($legacyHub['serverLogPath']));
    $run = bootAgentOnce([$canonicalUrl, $legacyUrl], ["'local_agent_id' => 'legacy-local-agent-42'"], $agentPhp, $configPath, $logDir, $agentDir);
    check('canonical-and-legacy URL config boots (exit 0)', $run['exitCode'] === 0, $failures, $passCount);
    check(
        'canonical service_base_url takes precedence over legacy hub_url',
        jobsNextIdentity(readServerRequests($canonicalHub['serverLogPath'])) === 'legacy-local-agent-42',
        $failures,
        $passCount,
    );
    check(
        'legacy hub_url is ignored when service_base_url is present',
        count(readServerRequests($legacyHub['serverLogPath'])) === $legacyBefore,
        $failures,
        $passCount,
    );
} finally {
    stopFakeHub($canonicalHub);
    stopFakeHub($legacyHub);
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
