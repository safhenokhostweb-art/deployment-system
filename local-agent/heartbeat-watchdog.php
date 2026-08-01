<?php

declare(strict_types=1);

/**
 * Independent Heartbeat + Restart Watchdog
 *
 * Root-cause fix (sections 5 & 9): runs as its OWN Windows Scheduled Task
 * (DeploymentLocalAgentWatchdog), entirely independent of the main
 * agent.php process — if agent.php hangs inside a single blocking git
 * command, its own heartbeat/poll loop hangs with it, and nothing running
 * *inside* that same process can ever notice or recover from that.
 * This script instead:
 *
 *   1. Sends the Local Agent's general online heartbeat itself, so
 *      last_seen_at keeps moving forward even while agent.php is stuck —
 *      Agent-online status and Job-lease status are deliberately
 *      separate signals (see PublishJobService's lease/heartbeat), so
 *      this heartbeat succeeding is never mistaken for "the stuck job is
 *      fine too".
 *   2. Polls a restart-only Control Job queue (never fetch_logs/
 *      reload_config — see LocalAgentControlJobController::nextRestartOnly())
 *      and, if an Admin has requested a restart, tree-kills agent.php by
 *      the specific PID it wrote to agent.pid on startup — re-validated
 *      against `tasklist` immediately before killing, and NEVER by image
 *      name — then restarts its Scheduled Task.
 *
 * Same credentials as the main agent (this is the same Local Agent
 * identity; there is no separate Hub registration for the watchdog) but
 * runs as its own independent Scheduled Task principal, so killing the
 * main agent's process can never take this one down with it.
 *
 * Intended to run every ~60s via its own Scheduled Task — see
 * config/windows-task-watchdog.xml.example and install.ps1.
 *
 * Usage: php heartbeat-watchdog.php
 */

require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Logger.php';
require __DIR__ . '/lib/Client.php';
require __DIR__ . '/lib/TaskListParser.php';
require __DIR__ . '/lib/WatchdogCooldown.php';
require __DIR__ . '/lib/WatchdogState.php';

use LocalAgent\Client;
use LocalAgent\Logger;
use LocalAgent\TaskListParser;
use LocalAgent\WatchdogCooldown;
use LocalAgent\WatchdogState;

$configPath = __DIR__ . '/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php — nothing to watch yet.\n");
    exit(0);
}

$config = require $configPath;

if (($config['agent_token'] ?? '') === '' || str_starts_with((string) $config['agent_token'], 'CHANGE_ME')) {
    // Same not-yet-approved state agent.php itself checks for — nothing
    // to heartbeat or watch until this agent has real credentials.
    exit(0);
}

$logger = new Logger($config['log_dir']);
$client = new Client(
    baseUrl: $config['hub_url'],
    localAgentId: $config['local_agent_id'],
    agentToken: $config['agent_token'],
    signingSecret: $config['signing_secret'],
    timeoutSeconds: $config['request_timeout_seconds'],
    logger: $logger,
);

sendIndependentHeartbeat($client, $logger);
pollAndHandleRestart($client, $config, $logger);

function sendIndependentHeartbeat(Client $client, Logger $logger): void
{
    try {
        $response = $client->request('POST', '/api/local-agent/heartbeat', [
            'hostname' => gethostname() ?: null,
            'operating_system' => PHP_OS_FAMILY,
        ]);

        if ($response['status'] !== 200) {
            $logger->warn("Watchdog: independent heartbeat returned HTTP {$response['status']}");
        }
    } catch (\Throwable $e) {
        $logger->warn('Watchdog: independent heartbeat failed: ' . $e->getMessage());
    }
}

function pollAndHandleRestart(Client $client, array $config, Logger $logger): void
{
    try {
        $response = $client->request('GET', '/api/local-agent/control-jobs/next-restart');
    } catch (\Throwable $e) {
        $logger->warn('Watchdog: restart-job poll failed: ' . $e->getMessage());

        return;
    }

    if ($response['status'] !== 200) {
        $logger->warn("Watchdog: restart-job poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return; // nothing queued
    }

    $reportPath = "/api/local-agent/control-jobs/{$job['id']}/report";
    $cooldownSeconds = (int) ($config['watchdog_cooldown_seconds'] ?? 300);
    $statePath = $config['watchdog_state_path'] ?? (dirname($config['log_dir']) . '/watchdog-state.json');
    $state = new WatchdogState($statePath);
    $cooldown = new WatchdogCooldown($cooldownSeconds);
    $now = time();
    $lastRestartAt = $state->lastRestartAt();

    if ($cooldown->isActive($lastRestartAt, $now)) {
        $remaining = $cooldown->remainingSeconds($lastRestartAt, $now);
        $logger->warn("Watchdog: restart requested but still in cooldown ({$remaining}s remaining) — refusing to restart again this soon.");
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Restart-loop protection: another watchdog-initiated restart happened less than {$cooldownSeconds}s ago. Refusing to restart again until the cooldown elapses ({$remaining}s remaining).",
        ]);

        return;
    }

    $pidFilePath = dirname($config['log_dir']) . '/agent.pid';
    $killedPid = null;

    if (is_file($pidFilePath)) {
        $pid = trim((string) file_get_contents($pidFilePath));

        if (ctype_digit($pid) && (int) $pid > 0) {
            $tasklist = runFixedCommand(['tasklist', '/FI', "PID eq {$pid}", '/FO', 'CSV', '/NH']);

            if (TaskListParser::isImageName($tasklist['output'], 'php.exe')) {
                $killResult = runFixedCommand(['taskkill', '/PID', $pid, '/T', '/F']);

                if ($killResult['exitCode'] === 0) {
                    $killedPid = $pid;
                    $logger->info("Watchdog: tree-killed unresponsive agent process (PID {$pid}).");
                } else {
                    // Not fatal to the overall recovery — schtasks below
                    // still runs regardless — but the eventual report must
                    // never claim a kill that didn't actually happen.
                    $logger->warn("Watchdog: taskkill for PID {$pid} exited with code {$killResult['exitCode']}: " . trim($killResult['output']));
                }
            } else {
                $logger->warn("Watchdog: agent.pid contained PID {$pid}, which is no longer a php.exe process — skipping taskkill, only restarting the Scheduled Task.");
            }
        }
    } else {
        $logger->warn('Watchdog: no agent.pid found — the main agent may already be stopped. Restarting the Scheduled Task anyway.');
    }

    $taskName = $config['watchdog_managed_task_name'] ?? 'DeploymentLocalAgent';

    // Best-effort: the task may already be stopped (a hung/killed process
    // still shows the task as "Running" until something ends it, but a
    // process that exited on its own — e.g. after taskkill above — can
    // leave the task already idle), so a nonzero /End exit code here is
    // expected and not itself a failure worth reporting.
    runFixedCommand(['schtasks', '/End', '/TN', $taskName]);

    $runResult = runFixedCommand(['schtasks', '/Run', '/TN', $taskName]);

    // Counted toward the cooldown either way — a failed attempt (wrong
    // task name, schtasks unavailable, permissions) must still be
    // throttled, or a persistently broken watchdog would retry in a tight
    // loop every single poll instead of just once per cooldown window.
    $state->recordRestart($now);

    if ($runResult['exitCode'] !== 0) {
        $logger->error("Watchdog: schtasks /Run for '{$taskName}' failed with exit code {$runResult['exitCode']}: " . trim($runResult['output']));

        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Could not restart Scheduled Task '{$taskName}' (schtasks exited with code {$runResult['exitCode']}): " . trim($runResult['output']),
        ]);

        return;
    }

    $logger->info("Watchdog: restarted Scheduled Task '{$taskName}'" . ($killedPid !== null ? " after killing PID {$killedPid}" : '') . '.');

    $client->request('POST', $reportPath, [
        'status' => 'succeeded',
        'output' => $killedPid !== null
            ? "Tree-killed unresponsive agent process (PID {$killedPid}) and restarted the Scheduled Task '{$taskName}'."
            : "Agent process was already stopped; restarted the Scheduled Task '{$taskName}'.",
    ]);
}

/**
 * @param array<int, string> $command a fixed, hardcoded command only —
 *     NEVER built from Hub response content, so a compromised or
 *     malicious Hub response can never make the watchdog run an
 *     arbitrary command. The array form of proc_open also means no value
 *     here is ever interpreted by a shell.
 * @return array{exitCode: int, output: string}
 */
function runFixedCommand(array $command): array
{
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        return ['exitCode' => 255, 'output' => ''];
    }

    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exitCode' => $exitCode, 'output' => $output];
}
