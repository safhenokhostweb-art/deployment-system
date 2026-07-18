<?php

declare(strict_types=1);

/**
 * Deployment Agent
 *
 * Runs ON the target production server. Polls the Deployment Service over
 * outbound HTTPS only — the service never opens a connection to this
 * server, so no inbound port and no public SSH are required.
 *
 * Usage:
 *   php agent.php            # run continuously (intended for systemd, see install/)
 *   php agent.php --once     # poll a single time and exit (intended for cron)
 */

require __DIR__ . '/lib/Signer.php';
require __DIR__ . '/lib/Logger.php';
require __DIR__ . '/lib/Client.php';
require __DIR__ . '/lib/Executor.php';
require __DIR__ . '/lib/JobVerifier.php';

use DeploymentAgent\Client;
use DeploymentAgent\Executor;
use DeploymentAgent\JobVerifier;
use DeploymentAgent\Logger;

/**
 * Reported to the Deployment Service on every heartbeat so the dashboard
 * can flag servers running an outdated agent. Bump this when agent.php or
 * lib/* changes in a way that matters operationally.
 */
const AGENT_VERSION = '1.1.0';

$configPath = __DIR__ . '/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php. Copy config.example.php to config.php and fill in real values.\n");
    exit(1);
}

$config = require $configPath;
$logger = new Logger($config['log_dir']);

$client = new Client(
    baseUrl: $config['service_base_url'],
    serverId: $config['server_id'],
    agentToken: $config['agent_token'],
    signingSecret: $config['signing_secret'],
    timeoutSeconds: $config['request_timeout_seconds'],
    logger: $logger,
);

$executor = new Executor();
$runOnce = in_array('--once', $argv, true);

$logger->info('Deployment agent starting' . ($runOnce ? ' (single poll)' : ' (continuous)'));

do {
    try {
        pollAndExecute($client, $executor, $config, $logger);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during poll cycle: ' . $e->getMessage());
    }

    if (!$runOnce) {
        sleep($config['poll_interval_seconds']);
    }
} while (!$runOnce);

function pollAndExecute(Client $client, Executor $executor, array $config, Logger $logger): void
{
    sendHeartbeat($client, $logger);

    $response = $client->request('GET', '/api/agent/jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;

    if ($job === null) {
        return; // nothing queued
    }

    $signature = $response['body']['signature'] ?? '';

    if (!JobVerifier::verify($job, $signature, $config['signing_secret'])) {
        $logger->error("Job {$job['id']} failed payload signature verification — refusing to execute.");
        $client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
            'status' => 'failed',
            'message' => 'Agent rejected job: invalid payload signature',
        ]);

        return;
    }

    $projectKey = $job['project_key'];
    $projectConfig = $config['projects'][$projectKey] ?? null;

    if ($projectConfig === null) {
        $logger->error("Job {$job['id']} references unknown project_key '{$projectKey}' — no local config for it.");
        $client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
            'status' => 'failed',
            'message' => "Unknown project_key '{$projectKey}' on this agent",
        ]);

        return;
    }

    $logger->info("Starting job {$job['id']} ({$job['action']}) for {$projectKey} @ {$job['commit_hash']}");
    $client->request('POST', "/api/agent/jobs/{$job['id']}/status", ['status' => 'running']);

    $workingDirectory = $projectConfig['working_directory'];
    $stepTimeout = $projectConfig['step_timeout_seconds'] ?? $config['default_step_timeout_seconds'];

    $replacements = [
        '{branch}' => $job['branch'] ?? '',
        '{commit}' => $job['commit_hash'] ?? '',
        '{previous_commit}' => $job['previous_commit_hash'] ?? '',
        '{working_directory}' => $workingDirectory,
        '{php_binary}' => $projectConfig['php_binary'] ?? 'php',
    ];

    $sequence = 0;
    $failed = false;

    foreach ($job['pipeline'] as $step) {
        $sequence++;
        $command = strtr($step['command'], $replacements);

        $logger->info("Job {$job['id']} step {$sequence} ({$step['name']}): {$command}");

        $result = $executor->run($command, $workingDirectory, $stepTimeout);

        $client->request('POST', "/api/agent/jobs/{$job['id']}/log", [
            'sequence' => $sequence,
            'step_name' => $step['name'],
            'command' => $command,
            'output' => mb_substr($result['output'], -50000),
            'exit_code' => $result['exitCode'],
            'duration_ms' => $result['durationMs'],
            'timed_out' => $result['timedOut'],
        ]);

        if ($result['exitCode'] !== 0) {
            $failed = true;
            $logger->error("Job {$job['id']} step {$sequence} ({$step['name']}) failed with exit code {$result['exitCode']}");
            break;
        }
    }

    $client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
        'status' => $failed ? 'failed' : 'succeeded',
    ]);

    $logger->info("Job {$job['id']} finished: " . ($failed ? 'failed' : 'succeeded'));
}

/**
 * Failure here must never block job polling — a heartbeat hiccup is not a
 * reason to stop deploying.
 */
function sendHeartbeat(Client $client, Logger $logger): void
{
    try {
        $client->request('POST', '/api/agent/heartbeat', ['agent_version' => AGENT_VERSION]);
    } catch (\Throwable $e) {
        $logger->warn('Heartbeat failed: ' . $e->getMessage());
    }
}
