<?php

declare(strict_types=1);

require __DIR__ . '/../lib/HttpHealthCheckRunner.php';

use DeploymentAgent\HttpHealthCheckRunner;

$failures = [];
$passes = 0;
$check = static function (string $name, bool $condition) use (&$failures, &$passes): void {
    if ($condition) {
        $passes++;
    } else {
        $failures[] = $name;
    }
};

$port = 23000 + random_int(0, 8000);
$serverLog = tempnam(sys_get_temp_dir(), 'health-server-');
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/fake_health_server.php'],
    [1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'w']],
    $pipes,
    __DIR__,
);

if (!is_resource($process)) {
    throw new RuntimeException('Could not start health fixture server.');
}

try {
    $deadline = microtime(true) + 5;
    do {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($connection !== false) {
            fclose($connection);
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    $runner = new HttpHealthCheckRunner();
    $base = [
        'expected_status' => 200,
        'timeout_seconds' => 1,
        'retries' => 0,
        'retry_interval_seconds' => 0,
        'allowed_redirects' => false,
    ];

    $ok = $runner->run($base + [
        'url' => "http://127.0.0.1:{$port}/ok",
        'expected_content' => 'customer-homepage-marker',
    ]);
    $check('HTTP 200 and expected content pass', $ok['status'] === 'succeeded' && $ok['httpStatus'] === 200);

    $error = $runner->run($base + ['url' => "http://127.0.0.1:{$port}/error"]);
    $check('HTTP 500 fails with the actual response status', $error['status'] === 'failed' && str_contains($error['output'], 'got 500'));

    $redirectBlocked = $runner->run($base + ['url' => "http://127.0.0.1:{$port}/redirect"]);
    $check('redirects are rejected by default', $redirectBlocked['status'] === 'failed' && $redirectBlocked['httpStatus'] === 302);

    $redirectAllowed = $runner->run(array_merge($base, [
        'url' => "http://127.0.0.1:{$port}/redirect",
        'allowed_redirects' => true,
    ]));
    $check('configured redirects are followed', $redirectAllowed['status'] === 'succeeded');

    $timeout = $runner->run($base + ['url' => "http://127.0.0.1:{$port}/slow"]);
    $check('timeout fails health verification', $timeout['status'] === 'failed' && $timeout['timedOut'] && str_contains(strtolower($timeout['output']), 'timed out'));
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($serverLog);
}

if ($failures !== []) {
    fwrite(STDERR, "HTTP health tests failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "HTTP health tests passed: {$passes}\n";
