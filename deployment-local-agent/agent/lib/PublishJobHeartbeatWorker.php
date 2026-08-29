<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * A job-scoped companion process which renews a claimed PublishJob lease
 * independently of the agent's synchronous publish pipeline.  The parent
 * process owns the lifecycle: it creates an exclusive lock before spawning
 * us, requests a clean stop after reporting the terminal result, and this
 * worker exits by itself if that parent disappears unexpectedly.
 *
 * No credential is passed on the command line.  The worker reads the same
 * local config.php as its parent and uses the existing signed Client API.
 */
final class PublishJobHeartbeatWorker
{
    /** @var resource|null */
    private $process = null;
    private ?string $lockPath = null;
    private ?string $stopPath = null;

    public function __construct(
        private readonly string $jobPublicId,
        private readonly string $configPath,
        private readonly Logger $logger,
        private readonly int $intervalSeconds = 20,
        private readonly ?string $phpBinary = null,
        private readonly int $maxLifetimeSeconds = 720,
    ) {
    }

    public function start(): bool
    {
        if ($this->process !== null) {
            $this->logger->warn("PublishJob {$this->jobPublicId}: heartbeat worker is already started in this agent process.");

            return false;
        }

        $directory = dirname($this->configPath) . '/storage/job-heartbeats';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->logger->warn("PublishJob {$this->jobPublicId}: could not create heartbeat-worker directory.");

            return false;
        }

        $stem = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->jobPublicId) ?: 'job';
        $this->lockPath = "{$directory}/{$stem}.lock";
        $this->stopPath = "{$directory}/{$stem}.stop";

        if (!$this->acquireLock()) {
            $this->logger->warn("PublishJob {$this->jobPublicId}: heartbeat worker already exists; refusing a duplicate worker.");

            return false;
        }

        @unlink($this->stopPath);
        $command = [
            $this->phpBinary ?: PHP_BINARY,
            __FILE__,
            '--worker',
            '--job', $this->jobPublicId,
            '--config', $this->configPath,
            '--lock', $this->lockPath,
            '--stop', $this->stopPath,
            '--parent-pid', (string) getmypid(),
            '--interval', (string) max(1, $this->intervalSeconds),
            '--max-lifetime', (string) max(60, $this->maxLifetimeSeconds),
        ];

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ], $pipes);

        if (!is_resource($process)) {
            @unlink($this->lockPath);
            $this->lockPath = null;
            $this->stopPath = null;
            $this->logger->warn("PublishJob {$this->jobPublicId}: could not start heartbeat worker.");

            return false;
        }

        fclose($pipes[0]);
        $this->process = $process;
        $status = proc_get_status($process);
        @file_put_contents($this->lockPath, json_encode([
            'worker_pid' => $status['pid'] ?? null,
            'parent_pid' => getmypid(),
            'started_at' => time(),
        ], JSON_THROW_ON_ERROR));
        $this->logger->info("PublishJob {$this->jobPublicId}: heartbeat worker started.");

        return true;
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->stopPath !== null) {
            @file_put_contents($this->stopPath, 'stop');
        }

        $deadline = microtime(true) + 15;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        $status = proc_get_status($this->process);
        if ($status['running']) {
            @proc_terminate($this->process);
        }
        @proc_close($this->process);
        $this->process = null;
        $this->removeFiles();
        $this->logger->info("PublishJob {$this->jobPublicId}: heartbeat worker stopped.");
    }

    private function acquireLock(): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen((string) $this->lockPath, 'x');
            if ($handle !== false) {
                fclose($handle);

                return true;
            }

            $metadata = json_decode((string) @file_get_contents((string) $this->lockPath), true);
            $workerPid = is_array($metadata) ? (int) ($metadata['worker_pid'] ?? 0) : 0;
            $parentPid = is_array($metadata) ? (int) ($metadata['parent_pid'] ?? 0) : 0;
            $workerAlive = $workerPid > 0 ? self::processIsAlive($workerPid) : false;
            $parentAlive = $parentPid > 0 ? self::processIsAlive($parentPid) : false;
            $age = time() - (int) (is_array($metadata) ? ($metadata['started_at'] ?? 0) : 0);
            if ($workerAlive !== false || $parentAlive !== false || $age < 720) {
                return false;
            }

            @unlink((string) $this->lockPath);
        }

        return false;
    }

    private function removeFiles(): void
    {
        if ($this->stopPath !== null) {
            @unlink($this->stopPath);
        }
        if ($this->lockPath !== null) {
            @unlink($this->lockPath);
        }
    }

    /** Returns null when the operating system does not permit this check. */
    private static function processIsAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');
            if (!is_string($output) || trim($output) === '') {
                return null;
            }
            if (str_contains($output, '"' . $pid . '"')) {
                return true;
            }

            return str_contains($output, 'No tasks are running') ? false : null;
        }

        return is_dir('/proc/' . $pid);
    }

    /** @param array<int, string> $arguments */
    public static function runWorker(array $arguments): int
    {
        $options = self::parseArguments($arguments);
        foreach (['job', 'config', 'lock', 'stop', 'parent-pid', 'interval', 'max-lifetime'] as $required) {
            if (!isset($options[$required]) || $options[$required] === '') {
                return 2;
            }
        }

        $config = require $options['config'];
        if (!is_array($config)) {
            return 2;
        }

        require_once __DIR__ . '/Security.php';
        require_once __DIR__ . '/Logger.php';
        require_once __DIR__ . '/Client.php';
        $logger = new Logger((string) $config['log_dir']);
        $client = new Client(
            baseUrl: (string) $config['hub_url'],
            localAgentId: (string) $config['local_agent_id'],
            agentToken: (string) $config['agent_token'],
            signingSecret: (string) $config['signing_secret'],
            timeoutSeconds: (int) ($config['request_timeout_seconds'] ?? 10),
            logger: $logger,
        );

        $job = $options['job'];
        $stopPath = $options['stop'];
        $lockPath = $options['lock'];
        $parentPid = (int) $options['parent-pid'];
        $interval = max(1, (int) $options['interval']);
        $deadline = microtime(true) + max(60, (int) $options['max-lifetime']);
        $logger->info("PublishJob {$job}: independent heartbeat worker running.");

        try {
            while (!is_file($stopPath) && self::processIsAlive($parentPid) !== false && microtime(true) < $deadline) {
                try {
                    $response = $client->request('POST', "/api/local-agent/publish-jobs/{$job}/heartbeat");
                    if ($response['status'] === 200) {
                        $logger->info("PublishJob {$job}: independent heartbeat succeeded.");
                    } else {
                        $logger->warn("PublishJob {$job}: independent heartbeat returned HTTP {$response['status']}.");
                    }
                } catch (\Throwable $e) {
                    $logger->warn("PublishJob {$job}: independent heartbeat failed: " . $e->getMessage());
                }

                $until = microtime(true) + $interval;
                while (!is_file($stopPath) && self::processIsAlive($parentPid) !== false && microtime(true) < $until && microtime(true) < $deadline) {
                    usleep(250_000);
                }
            }
        } finally {
            @unlink($stopPath);
            @unlink($lockPath);
            $logger->info("PublishJob {$job}: independent heartbeat worker exited.");
        }

        return 0;
    }

    /** @param array<int, string> $arguments @return array<string, string> */
    private static function parseArguments(array $arguments): array
    {
        $parsed = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (str_starts_with($argument, '--') && isset($arguments[$index + 1]) && !str_starts_with($arguments[$index + 1], '--')) {
                $parsed[substr($argument, 2)] = $arguments[++$index];
            }
        }

        return $parsed;
    }
}

if (PHP_SAPI === 'cli' && in_array('--worker', $argv, true) && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(PublishJobHeartbeatWorker::runWorker(array_slice($argv, 1)));
}
