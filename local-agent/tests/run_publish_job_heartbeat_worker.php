<?php

declare(strict_types=1);

require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/PublishJobHeartbeatWorker.php';

use LocalAgent\Logger;
use LocalAgent\PublishJobHeartbeatWorker;

$failures = [];
$passed = 0;

function checkWorker(string $description, bool $condition, array &$failures, int &$passed): void
{
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $description;
}

function workerState(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function setWorkerState(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
}

function waitForWorkerCount(string $statePath, int $minimum, int $seconds = 5): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ((workerState($statePath)['count'] ?? 0) >= $minimum) {
            return true;
        }
        usleep(100_000);
    }
    return false;
}

$root = sys_get_temp_dir() . '/local-agent-heartbeat-worker-' . bin2hex(random_bytes(5));
mkdir($root, 0777, true);
$statePath = "$root/state.json";
setWorkerState($statePath, ['count' => 0, 'status' => 200]);
$router = "$root/router.php";
file_put_contents($router, <<<'PHP'
<?php
$statePath = getenv('HEARTBEAT_WORKER_STATE');
$state = json_decode((string) file_get_contents($statePath), true);
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/heartbeat')) {
    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    file_put_contents($statePath, json_encode($state));
    http_response_code((int) ($state['status'] ?? 200));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    return;
}
http_response_code(404);
PHP
);

$port = random_int(34000, 39000);
$serverLog = "$root/server.log";
$environment = array_merge(getenv() ?: [], ['HEARTBEAT_WORKER_STATE' => $statePath]);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'w']], $pipes, $root, $environment);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start heartbeat-worker test server.');
}

try {
    for ($i = 0; $i < 30; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100_000);
    }

    $configPath = "$root/config.php";
    file_put_contents($configPath, "<?php return " . var_export([
        'hub_url' => "http://127.0.0.1:$port",
        'local_agent_id' => 'test-agent',
        'agent_token' => 'test-token',
        'signing_secret' => 'test-secret',
        'request_timeout_seconds' => 1,
        'log_dir' => "$root/logs",
    ], true) . ';');
    $logger = new Logger("$root/logs");
    $worker = new PublishJobHeartbeatWorker('job-heartbeat-test', $configPath, $logger, 1);

    checkWorker('heartbeat worker starts once after a claim', $worker->start() === true, $failures, $passed);
    checkWorker('duplicate heartbeat worker start is refused', $worker->start() === false, $failures, $passed);
    checkWorker('initial heartbeat reaches the Hub', waitForWorkerCount($statePath, 1), $failures, $passed);

    foreach (['ZIP creation delay', 'checksum delay', 'upload delay', 'composer/npm delay'] as $stage) {
        $before = (int) (workerState($statePath)['count'] ?? 0);
        sleep(2); // Simulates a synchronous parent-side blocking stage.
        checkWorker("heartbeat continues during $stage", waitForWorkerCount($statePath, $before + 1, 2), $failures, $passed);
    }

    $state = workerState($statePath);
    $state['status'] = 503;
    setWorkerState($statePath, $state);
    $beforeFailure = (int) $state['count'];
    checkWorker('temporary Hub failure is attempted without stopping the worker', waitForWorkerCount($statePath, $beforeFailure + 1, 3), $failures, $passed);
    $state = workerState($statePath);
    $state['status'] = 200;
    setWorkerState($statePath, $state);

    $worker->stop();
    $workerDirectory = dirname($configPath) . '/storage/job-heartbeats';
    $remaining = is_dir($workerDirectory) ? array_values(array_diff(scandir($workerDirectory) ?: [], ['.', '..'])) : [];
    checkWorker('heartbeat worker stops cleanly after success or failure reporting', $remaining === [], $failures, $passed);
    checkWorker('no orphan heartbeat worker lock remains after stop', !is_file("$workerDirectory/job-heartbeat-test.lock"), $failures, $passed);
} finally {
    @proc_terminate($server);
    @proc_close($server);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "$passed passed, 0 failed." . PHP_EOL;
