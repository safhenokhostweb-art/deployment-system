<?php

declare(strict_types=1);

/**
 * Local Development Publisher Agent
 *
 * Runs ON a developer's own machine (Windows/Linux/macOS). Polls the
 * Deployment Hub over outbound HTTPS only — the Hub never connects back
 * to this machine, so nothing needs to be reachable from the internet.
 * A completely independent process/codebase from the Hub's Laravel app
 * and from the production Server Agent (agent/) — no shared dependency,
 * no shared runtime, plain PHP only.
 *
 * Usage:
 *   php agent.php install --hub=https://hub.example.test --id=<registration_id> --token=<registration_token> [--name=my-laptop]
 *       One-time self-registration. Writes config.php with hub_url/
 *       local_agent_id filled in. agent_token/signing_secret are issued
 *       only once an Admin approves this agent in the Hub — paste the
 *       values the Hub shows at that point into config.php yourself
 *       (this script never has network access to fetch them after the
 *       fact, by design — they are shown to a human exactly once).
 *
 *   php agent.php            # run continuously while you work — also watches
 *                            # mapped project folders and reports changes
 *   php agent.php --once     # poll a single time and exit
 *
 * Stop with Ctrl+C (or Task Scheduler's own stop signal) — the current
 * poll cycle finishes first (never mid `git add`/`git commit`), then the
 * process exits cleanly. See registerGracefulShutdown().
 */

require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Logger.php';
require __DIR__ . '/lib/Client.php';
require __DIR__ . '/lib/GitManager.php';
require __DIR__ . '/lib/CommitManager.php';
require __DIR__ . '/lib/PushManager.php';
require __DIR__ . '/lib/IgnoreRules.php';
require __DIR__ . '/lib/SafetyChecker.php';
require __DIR__ . '/lib/LogTail.php';
require __DIR__ . '/lib/ShutdownSignal.php';

use LocalAgent\Client;
use LocalAgent\CommitManager;
use LocalAgent\GitManager;
use LocalAgent\IgnoreRules;
use LocalAgent\Logger;
use LocalAgent\LogTail;
use LocalAgent\ShutdownSignal;
use LocalAgent\PushManager;
use LocalAgent\SafetyChecker;
use LocalAgent\Security;

/**
 * Reported on every heartbeat so the Hub can flag a developer machine
 * running an outdated agent (check-and-notify only — see
 * config('deployment.latest_local_agent_version')'s own docblock on the
 * Hub side for why this never triggers an automatic download). Bump
 * when agent.php or lib/* changes in a way that matters operationally.
 */
const LOCAL_AGENT_VERSION = '1.2.0';

$configPath = __DIR__ . '/config.php';

if (($argv[1] ?? null) === 'install') {
    installCommand(array_slice($argv, 2), $configPath);
    exit(0);
}

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php. Run `php agent.php install --hub=... --id=... --token=...` first, or copy config.example.php manually.\n");
    exit(1);
}

$config = require $configPath;

if (($config['agent_token'] ?? '') === '' || str_starts_with((string) $config['agent_token'], 'CHANGE_ME')) {
    fwrite(STDERR, "config.php has no real agent_token yet — this agent is registered but not yet approved. Ask an Admin to approve it in the Hub's Local Agents page, then paste the shown agent_token/signing_secret into config.php.\n");
    exit(1);
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
$git = new GitManager();
$commits = new CommitManager($git);
$pushes = new PushManager($git);
$safety = new SafetyChecker();

$runOnce = in_array('--once', $argv, true);

$logger->info('Local Publisher Agent starting' . ($runOnce ? ' (single poll)' : ' (continuous)') . ' (v' . LOCAL_AGENT_VERSION . ')');

/** @var array<string, array<int, string>> $lastReportedChanges project_key => last file list reported this run */
$lastReportedChanges = [];

$shuttingDown = false;
registerGracefulShutdown($logger, $shuttingDown);

do {
    try {
        sendHeartbeat($client, $logger);
        pollAndExecutePublish($client, $git, $commits, $pushes, $safety, $config, $logger);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during poll cycle: ' . $e->getMessage());
    }

    try {
        pollAndDetectChanges($client, $git, $config, $logger, $lastReportedChanges);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during change-detection cycle: ' . $e->getMessage());
    }

    try {
        pollAndHandleControl($client, $config, $logger);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during control-job poll cycle: ' . $e->getMessage());
    }

    // Checked only here — once a full cycle has finished cleanly, never
    // mid-step — so a Ctrl+C (or a `restart` control job's own exit,
    // handled inside pollAndHandleControl()) can't land between `git add`
    // and `git commit`.
    if ($shuttingDown) {
        $logger->info('Local Publisher Agent shutting down gracefully.');
        exit(0);
    }

    if (!$runOnce) {
        sleep($config['poll_interval_seconds']);
    }
} while (!$runOnce);

function pollAndExecutePublish(Client $client, GitManager $git, CommitManager $commits, PushManager $pushes, SafetyChecker $safety, array $config, Logger $logger): void
{
    $response = $client->request('GET', '/api/local-agent/publish-jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return; // nothing queued
    }

    $projectKey = $job['project_key'];
    $projectConfig = $config['projects'][$projectKey] ?? null;
    $reportPath = "/api/local-agent/publish-jobs/{$job['id']}/report";

    if ($projectConfig === null) {
        $logger->error("Job {$job['id']} references unknown project_key '{$projectKey}' — no local mapping for it in config.php.");
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Unknown project_key '{$projectKey}' on this agent",
        ]);

        return;
    }

    $repositoryPath = $projectConfig['local_path'];
    $remoteName = $projectConfig['remote_name'] ?? 'origin';
    $branch = $projectConfig['branch'] ?? 'main';

    try {
        if ($job['action'] === 'status_check') {
            $logger->info("Job {$job['id']}: checking git status for {$projectKey} at {$repositoryPath}");
            $rawFiles = $git->changedFiles($repositoryPath);
            $changedFiles = IgnoreRules::filter($rawFiles, IgnoreRules::loadPatterns($repositoryPath));
            // Phase 5.5: report what was discarded too, not just what
            // survived — the operator should see a blocked .env the same
            // way they see an allowed file, never just silence.
            $blockedFiles = array_values(array_diff($rawFiles, $changedFiles));
            $client->request('POST', $reportPath, ['changed_files' => $changedFiles, 'blocked_files' => $blockedFiles]);
            $logger->info("Job {$job['id']}: reported " . count($changedFiles) . ' changed file(s), ' . count($blockedFiles) . ' blocked file(s)');

            return;
        }

        if ($job['action'] === 'publish') {
            $logger->info("Job {$job['id']}: publishing {$projectKey} ({$repositoryPath})");
            $safety->assertSafeToPublish($git, $repositoryPath, $branch);
            $changedFiles = IgnoreRules::filter($git->changedFiles($repositoryPath), IgnoreRules::loadPatterns($repositoryPath));
            // Phase 5.5: only commit when there is something new to commit
            // — a retry after "commit succeeded, push failed" has nothing
            // left to add (the previous attempt already committed it), and
            // should just retry the push rather than fail again on
            // CommitManager's own "nothing to commit" guard.
            if ($changedFiles !== []) {
                $commits->addAndCommit($repositoryPath, $changedFiles, (string) $job['commit_message']);
            }
            $output = $pushes->push($repositoryPath, $remoteName, $branch);
            $commitHash = $git->lastCommitHash($repositoryPath);

            $client->request('POST', $reportPath, ['status' => 'succeeded', 'commit_hash' => $commitHash, 'output' => $output]);
            $logger->info("Job {$job['id']}: published as {$commitHash}");

            return;
        }

        if ($job['action'] === 'rollback') {
            $logger->info("Job {$job['id']}: rolling back {$projectKey} ({$repositoryPath})");
            $safety->assertSafeToPublish($git, $repositoryPath, $branch);
            $lastCommit = $git->lastCommitHash($repositoryPath);
            $revertCommit = $commits->revert($repositoryPath, $lastCommit);
            $output = $pushes->push($repositoryPath, $remoteName, $branch);

            $client->request('POST', $reportPath, ['status' => 'succeeded', 'commit_hash' => $revertCommit, 'output' => $output]);
            $logger->info("Job {$job['id']}: reverted {$lastCommit} as {$revertCommit}");

            return;
        }

        $logger->error("Job {$job['id']}: unknown action '{$job['action']}'");
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => "Unknown action '{$job['action']}'"]);
    } catch (\Throwable $e) {
        $logger->error("Job {$job['id']} failed: " . $e->getMessage());
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => $e->getMessage()]);
    }
}

/**
 * Phase 5.2: the file watcher. Reuses the exact same GitManager::changedFiles()
 * (`git status --porcelain`) the manual status_check flow already relies
 * on — no separate filesystem-event dependency, no new poll interval.
 * Only reports when the ignore-filtered file list is non-empty AND
 * differs from what this process already reported for that project, so
 * a developer who keeps saving the same unfinished edit doesn't get a
 * fresh Hub notification every poll cycle.
 *
 * @param array<string, array<int, string>> $lastReportedChanges
 */
function pollAndDetectChanges(Client $client, GitManager $git, array $config, Logger $logger, array &$lastReportedChanges): void
{
    foreach ($config['projects'] as $projectKey => $projectConfig) {
        $repositoryPath = $projectConfig['local_path'];

        try {
            $changedFiles = IgnoreRules::filter($git->changedFiles($repositoryPath), IgnoreRules::loadPatterns($repositoryPath));
        } catch (\Throwable $e) {
            $logger->warn("Watch: could not read git status for {$projectKey} at {$repositoryPath}: " . $e->getMessage());

            continue;
        }

        $previous = $lastReportedChanges[$projectKey] ?? null;

        if ($changedFiles === [] || $changedFiles === $previous) {
            continue;
        }

        $response = $client->request('POST', '/api/local-agent/change-detections', [
            'project_key' => $projectKey,
            'changed_files' => $changedFiles,
        ]);

        if ($response['status'] !== 200) {
            $logger->warn("Watch: reporting changes for {$projectKey} returned HTTP {$response['status']}");

            continue;
        }

        $lastReportedChanges[$projectKey] = $changedFiles;
        $logger->info("Watch: reported " . count($changedFiles) . " changed file(s) for {$projectKey}");
    }
}

/**
 * Phase 5.3: the Hub's only channel to remotely act on this running
 * process — polled every cycle like publish jobs and change detection,
 * since there is deliberately no inbound path to a developer's machine.
 */
function pollAndHandleControl(Client $client, array $config, Logger $logger): void
{
    $response = $client->request('GET', '/api/local-agent/control-jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Control-job poll returned HTTP {$response['status']}");

        return;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return; // nothing queued
    }

    $reportPath = "/api/local-agent/control-jobs/{$job['id']}/report";

    try {
        if ($job['action'] === 'fetch_logs') {
            $logger->info("Control job {$job['id']}: fetching recent log output");
            $output = LogTail::tail($config['log_dir'], 200);
            $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => $output]);

            return;
        }

        if ($job['action'] === 'restart') {
            $logger->info("Control job {$job['id']}: restart requested — reporting success, then exiting");
            $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => 'Restarting now.']);
            // Deliberately non-zero: Task Scheduler's RestartOnFailure must
            // reliably relaunch this process regardless of how it defines
            // "successful completion" for a 0-exit process.
            exit(1);
        }

        $logger->error("Control job {$job['id']}: unknown action '{$job['action']}'");
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => "Unknown action '{$job['action']}'"]);
    } catch (\Throwable $e) {
        $logger->error("Control job {$job['id']} failed: " . $e->getMessage());
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => $e->getMessage()]);
    }
}

/**
 * Windows has no pcntl extension — sapi_windows_set_ctrl_handler() is
 * its equivalent (PHP 7.4+), firing on Ctrl+C/Break/Close/Logoff/
 * Shutdown. Linux/macOS use pcntl_signal() behind its own extension
 * guard. Either way this only ever flips a flag the main loop checks
 * between full cycles (see the `do { ... } while` above) — never
 * interrupts a step in progress.
 *
 * @param bool $shuttingDown passed by reference so the registered
 *     callback can flip the caller's own loop-control variable
 */
function registerGracefulShutdown(Logger $logger, bool &$shuttingDown): void
{
    if (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(function (int $event) use ($logger, &$shuttingDown): void {
            ShutdownSignal::handle($logger, $shuttingDown, 'event', $event);
        });

        return;
    }

    if (extension_loaded('pcntl') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $handler = function (int $signal) use ($logger, &$shuttingDown): void {
            ShutdownSignal::handle($logger, $shuttingDown, 'signal', $signal);
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}

function sendHeartbeat(Client $client, Logger $logger): void
{
    try {
        $response = $client->request('POST', '/api/local-agent/heartbeat', [
            'hostname' => gethostname() ?: null,
            'operating_system' => PHP_OS_FAMILY,
            'agent_version' => LOCAL_AGENT_VERSION,
            'capabilities' => ['git' => true, 'php' => PHP_VERSION],
        ]);

        $latestVersion = $response['body']['latest_version'] ?? null;
        if ($latestVersion !== null && version_compare(LOCAL_AGENT_VERSION, (string) $latestVersion, '<')) {
            $logger->warn("Update available: v{$latestVersion} (running v" . LOCAL_AGENT_VERSION . ") — run upgrade.ps1 from the deployment-local-agent package to update.");
        }
    } catch (\Throwable $e) {
        $logger->warn('Heartbeat failed: ' . $e->getMessage());
    }
}

/**
 * @param array<int, string> $args
 */
function installCommand(array $args, string $configPath): void
{
    $options = ['hub' => null, 'id' => null, 'token' => null, 'name' => null];
    foreach ($args as $arg) {
        foreach (array_keys($options) as $key) {
            if (str_starts_with($arg, "--{$key}=")) {
                $options[$key] = substr($arg, strlen("--{$key}="));
            }
        }
    }

    if ($options['hub'] === null || $options['id'] === null || $options['token'] === null) {
        fwrite(STDERR, "Usage: php agent.php install --hub=https://hub.example.test --id=<registration_id> --token=<registration_token> [--name=my-laptop]\n");
        exit(1);
    }

    $hostname = gethostname() ?: 'unknown-host';
    $name = $options['name'] ?? $hostname;
    $body = [
        'name' => $name,
        'hostname' => $hostname,
        'operating_system' => PHP_OS_FAMILY,
        'capabilities' => ['git' => true, 'php' => PHP_VERSION],
    ];
    $bodyJson = json_encode($body, JSON_THROW_ON_ERROR);

    $method = 'POST';
    $path = '/api/local-agent/register';
    $timestamp = (string) time();
    $nonce = Security::nonce();
    $signature = Security::sign($method, $path, $timestamp, $nonce, $bodyJson, $options['token']);

    $ch = curl_init(rtrim($options['hub'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $bodyJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Deployment-Local-Registration-Id: ' . $options['id'],
            'X-Deployment-Local-Registration-Timestamp: ' . $timestamp,
            'X-Deployment-Local-Registration-Nonce: ' . $nonce,
            'X-Deployment-Local-Registration-Signature: ' . $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        fwrite(STDERR, "Registration request failed: {$error}\n");
        exit(1);
    }

    if ($status !== 200) {
        fwrite(STDERR, "Registration rejected by Hub (HTTP {$status}): {$response}\n");
        exit(1);
    }

    $configContent = "<?php\n\nreturn [\n"
        . "    'hub_url' => " . var_export(rtrim($options['hub'], '/'), true) . ",\n"
        . "    'local_agent_id' => " . var_export($options['id'], true) . ",\n"
        . "    'agent_token' => 'CHANGE_ME_AGENT_TOKEN',\n"
        . "    'signing_secret' => 'CHANGE_ME_SIGNING_SECRET',\n"
        . "    'poll_interval_seconds' => 15,\n"
        . "    'request_timeout_seconds' => 10,\n"
        . "    'projects' => [],\n"
        . "    'log_dir' => __DIR__ . '/storage/logs',\n"
        . "];\n";

    file_put_contents($configPath, $configContent);
    @chmod($configPath, 0600);

    echo "ثبت‌نام انجام شد — این کارگزار منتظر تایید ادمین در پنل است.\n";
    echo "config.php در " . $configPath . " ساخته شد.\n";
    echo "پس از تایید در پنل، مقادیر agent_token و signing_secret نمایش‌داده‌شده را در همین فایل جایگزین کنید، سپس یک نگاشت پروژه (projects) اضافه کنید و «php agent.php» را اجرا کنید.\n";
}
