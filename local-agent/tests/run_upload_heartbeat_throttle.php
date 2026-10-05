<?php

declare(strict_types=1);

// Exercise the actual Client progress callback with a monotonic fake clock.
// No network requests, artifacts, jobs, or real credentials are used.
namespace LocalAgent {
    $clockNs = 0;
    $progressEvents = [];
    $heartbeatTimes = [];

    function hrtime(bool $asNumber = false): int { return $GLOBALS['clockNs']; }
    function curl_init(string $url): object { return new \stdClass(); }
    function curl_setopt_array(object $handle, array $options): bool {
        $handle->progress = $options[CURLOPT_PROGRESSFUNCTION];
        return true;
    }
    function curl_exec(object $handle): string {
        foreach ($GLOBALS['progressEvents'] as $at) {
            $GLOBALS['clockNs'] = $at;
            if (($handle->progress)() !== 0) {
                throw new \RuntimeException('Progress callback aborted the upload.');
            }
        }
        return '{"status":"ok"}';
    }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace {
    require __DIR__ . '/../lib/Security.php';
    require __DIR__ . '/../lib/Logger.php';
    require ($argv[1] ?? null) === 'installed'
        ? __DIR__ . '/../../deployment-local-agent/agent/lib/Client.php'
        : __DIR__ . '/../lib/Client.php';

    $logDir = sys_get_temp_dir() . '/upload-heartbeat-throttle-' . bin2hex(random_bytes(6));
    $client = new \LocalAgent\Client('http://test.invalid', 'fixture-agent', 'fixture-token', 'fixture-secret', 10, new \LocalAgent\Logger($logDir));
    $checks = 0;
    function expectThrottle(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
        $GLOBALS['checks']++;
    }
    function simulateUpload(\LocalAgent\Client $client, array $events, ?callable $heartbeat): array {
        $GLOBALS['clockNs'] = 0;
        $GLOBALS['progressEvents'] = $events;
        $GLOBALS['heartbeatTimes'] = [];
        return $client->uploadRawBody('POST', '/fixture/content', 'fixture bytes', $heartbeat);
    }
    $recordHeartbeat = static function (): void { $GLOBALS['heartbeatTimes'][] = $GLOBALS['clockNs']; };

    try {
        // Thousands of rapid events must not make a quick upload wait on HTTP.
        simulateUpload($client, range(0, 2_000_000_000, 100_000), $recordHeartbeat);
        expectThrottle($heartbeatTimes === [], 'Fast transfer invoked a synchronous heartbeat.');

        // Boundary events and duplicates ensure at most one call per interval.
        simulateUpload($client, [0, 24_999_999_999, 25_000_000_000, 25_000_000_000, 49_999_999_999, 50_000_000_000, 75_000_000_000], $recordHeartbeat);
        expectThrottle($heartbeatTimes === [25_000_000_000, 50_000_000_000, 75_000_000_000], 'Heartbeat interval was not enforced.');

        // A slow callback must leave a full interval after it finishes.
        simulateUpload($client, [25_000_000_000, 55_000_000_000, 79_999_999_999, 80_000_000_000], static function (): void {
            $GLOBALS['heartbeatTimes'][] = $GLOBALS['clockNs'];
            $GLOBALS['clockNs'] += 30_000_000_000;
        });
        expectThrottle($heartbeatTimes === [25_000_000_000, 80_000_000_000], 'Slow callback caused an immediate repeated heartbeat.');

        // Heartbeat transport errors are supplemental, not upload failures.
        $result = simulateUpload($client, [25_000_000_000, 25_000_000_001, 50_000_000_000], static function (): void {
            $GLOBALS['heartbeatTimes'][] = $GLOBALS['clockNs'];
            throw new \RuntimeException('fixture heartbeat unavailable');
        });
        expectThrottle($heartbeatTimes === [25_000_000_000, 50_000_000_000] && $result['status'] === 200, 'Heartbeat failure aborted transfer or retried immediately.');

        simulateUpload($client, [25_000_000_000, 50_000_000_000], null);
        expectThrottle($heartbeatTimes === [], 'Null callback was invoked.');

        // A second request/retry starts its own interval, without shared state.
        simulateUpload($client, [24_999_999_999, 25_000_000_000], $recordHeartbeat);
        expectThrottle($heartbeatTimes === [25_000_000_000], 'Heartbeat timer leaked between upload requests.');
        echo "Passed {$checks} upload heartbeat throttle checks.\n";
    } finally {
        foreach (glob($logDir . '/*.log') ?: [] as $log) { unlink($log); }
        rmdir($logDir);
    }
}
