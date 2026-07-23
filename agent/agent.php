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
require __DIR__ . '/lib/RemoteUrlSanitizer.php';
require __DIR__ . '/lib/WorkspaceScanner.php';
require __DIR__ . '/lib/OperationRegistry.php';
require __DIR__ . '/lib/HealthCollector.php';

use DeploymentAgent\Client;
use DeploymentAgent\Executor;
use DeploymentAgent\HealthCollector;
use DeploymentAgent\JobVerifier;
use DeploymentAgent\Logger;
use DeploymentAgent\OperationRegistry;
use DeploymentAgent\WorkspaceScanner;

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

    // Phase 4-H: strictly opt-in — absent (or empty) workspace_root means
    // this exact branch never runs, so an unconfigured (even upgraded)
    // agent behaves byte-for-byte like the pre-4-H agent. Kept as its own
    // try/catch and its own poll so a Discovery failure can never affect
    // the deployment-job poll above.
    if (!empty($config['workspace_root'])) {
        try {
            pollAndExecuteDiscovery($client, $config, $logger);
        } catch (\Throwable $e) {
            $logger->error('Unhandled error during discovery poll cycle: ' . $e->getMessage());
        }
    }

    // Phase 4-K: always polled, same as the deployment job poll above —
    // unlike Discovery (which reads broadly across a whole workspace and
    // so is opt-in), an operation only ever executes a fixed,
    // registry-defined command, and only when an authenticated Admin/
    // Operator explicitly queued it from the Hub. This is not a new trust
    // boundary: an approved Agent can already execute arbitrary pipeline
    // commands from a Project's own `pipeline` column for every
    // deployment — the Operations registry is a strictly narrower,
    // pre-approved subset of that same existing capability. Kept as its
    // own try/catch so a failure here can never affect deployment polling.
    try {
        pollAndExecuteOperation($client, $executor, $config, $logger);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during operation poll cycle: ' . $e->getMessage());
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
 * Phase 4-H: same signed channel, same 'agent.signed' auth, same Client —
 * no new protocol. Only reports structured metadata (WorkspaceScanner's
 * output); never uploads a file or a secret.
 */
function pollAndExecuteDiscovery(Client $client, array $config, Logger $logger): void
{
    $response = $client->request('GET', '/api/agent/discovery-jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Discovery poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return; // nothing queued
    }

    $logger->info("Starting discovery job {$job['id']} for workspace {$job['workspace_path']}");

    try {
        $scanner = new WorkspaceScanner();
        $projects = $scanner->scan($job['workspace_path']);

        $client->request('POST', "/api/agent/discovery-jobs/{$job['id']}/report", [
            'status' => 'succeeded',
            'projects' => $projects,
        ]);

        $logger->info("Discovery job {$job['id']} finished: succeeded (" . count($projects) . ' project(s) found)');
    } catch (\Throwable $e) {
        $client->request('POST', "/api/agent/discovery-jobs/{$job['id']}/report", [
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        $logger->error("Discovery job {$job['id']} failed: " . $e->getMessage());
    }
}

/**
 * Phase 4-K: same signed channel, same 'agent.signed' auth, same Client —
 * no new protocol. Only ever receives an `operation_type` key (never a
 * raw command), validated against this agent's own OperationRegistry
 * before anything is executed — Task 4/12's "never trust a command
 * string off the network" rule enforced independently on this side too,
 * not just by the Hub.
 */
function pollAndExecuteOperation(Client $client, Executor $executor, array $config, Logger $logger): void
{
    $response = $client->request('GET', '/api/agent/operation-jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Operation poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return; // nothing queued
    }

    $operationType = $job['operation_type'];
    $reportPath = "/api/agent/operation-jobs/{$job['id']}/report";

    if (!OperationRegistry::isAllowed($operationType)) {
        $logger->error("Operation job {$job['id']} references unknown operation_type '{$operationType}' — refusing to execute.");
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Unknown operation_type '{$operationType}' — not in this agent's allowed operations registry.",
        ]);

        return;
    }

    $workingDirectory = sys_get_temp_dir();
    $phpBinary = 'php';

    if (OperationRegistry::requiresProject($operationType)) {
        $projectKey = $job['project_key'] ?? null;
        $projectConfig = $projectKey !== null ? ($config['projects'][$projectKey] ?? null) : null;

        if ($projectConfig === null) {
            $logger->error("Operation job {$job['id']} ('{$operationType}') needs a project, but project_key '{$projectKey}' is unknown on this agent.");
            $client->request('POST', $reportPath, [
                'status' => 'failed',
                'error_message' => "Unknown or missing project_key '{$projectKey}' on this agent for operation '{$operationType}'.",
            ]);

            return;
        }

        $workingDirectory = $projectConfig['working_directory'];
        $phpBinary = $projectConfig['php_binary'] ?? 'php';
    }

    $command = strtr(OperationRegistry::commandFor($operationType), [
        '{working_directory}' => $workingDirectory,
        '{php_binary}' => $phpBinary,
    ]);

    // Unlike the deployment job flow, there is no separate "status:
    // running" call here — claimNext() (server-side) already flipped the
    // job to running the instant this poll claimed it, so there's nothing
    // left to report until the command actually finishes.
    $logger->info("Starting operation job {$job['id']} ({$operationType}): {$command}");

    $timeout = $config['default_step_timeout_seconds'];
    $result = $executor->run($command, $workingDirectory, $timeout);
    $output = mb_substr($result['output'], -50000);

    if ($result['exitCode'] !== 0 || $result['timedOut']) {
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'output' => $output,
            'error_message' => $result['timedOut']
                ? "Operation exceeded {$timeout}s timeout and was terminated."
                : "Operation exited with code {$result['exitCode']}.",
        ]);
        $logger->error("Operation job {$job['id']} failed (exit {$result['exitCode']}).");

        return;
    }

    $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => $output]);
    $logger->info("Operation job {$job['id']} finished: succeeded");
}

/**
 * Failure here must never block job polling — a heartbeat hiccup is not a
 * reason to stop deploying.
 */
function sendHeartbeat(Client $client, Logger $logger): void
{
    try {
        // Phase 4-K Task 11: same existing heartbeat call, three new
        // optional fields — a legacy Hub (pre-4-K) simply ignores unknown
        // JSON keys, and an old agent binary never sends them at all, so
        // neither side needs to change behavior for the other to keep
        // working.
        $health = (new HealthCollector())->collect();

        $client->request('POST', '/api/agent/heartbeat', array_merge(
            ['agent_version' => AGENT_VERSION],
            $health,
        ));
    } catch (\Throwable $e) {
        $logger->warn('Heartbeat failed: ' . $e->getMessage());
    }
}
