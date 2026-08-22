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
require __DIR__ . '/lib/GitCommandException.php';
require __DIR__ . '/lib/JobMaxRuntimeExceededException.php';
require __DIR__ . '/lib/GitManager.php';
require __DIR__ . '/lib/RetryPolicy.php';
require __DIR__ . '/lib/GitPushFailureClassifier.php';
require __DIR__ . '/lib/CommitManager.php';
require __DIR__ . '/lib/PushManager.php';
require __DIR__ . '/lib/IgnoreRules.php';
require __DIR__ . '/lib/SafetyChecker.php';
require __DIR__ . '/lib/LogTail.php';
require __DIR__ . '/lib/ShutdownSignal.php';
require __DIR__ . '/lib/InvalidProjectCacheException.php';
require __DIR__ . '/lib/ProjectConfigCache.php';
require __DIR__ . '/lib/ProjectConfigResolver.php';
require __DIR__ . '/lib/ProjectConfigSyncScheduler.php';
require __DIR__ . '/lib/LocalProjectStateReporter.php';
require __DIR__ . '/lib/SafeFilesystem.php';
require __DIR__ . '/lib/BuildWorkspace.php';
require __DIR__ . '/lib/WorkspaceSourcePreparer.php';
require __DIR__ . '/lib/ArtifactBuilder.php';
require __DIR__ . '/lib/ArtifactUploader.php';
require __DIR__ . '/lib/LocalBuildFailedException.php';
require __DIR__ . '/lib/ApplicationRoot.php';
require __DIR__ . '/lib/LocalBuildRunner.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactUploader;
use LocalAgent\Client;
use LocalAgent\CommitManager;
use LocalAgent\GitCommandException;
use LocalAgent\GitManager;
use LocalAgent\IgnoreRules;
use LocalAgent\JobMaxRuntimeExceededException;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;
use LocalAgent\LogTail;
use LocalAgent\LocalProjectStateReporter;
use LocalAgent\ProjectConfigCache;
use LocalAgent\ProjectConfigResolver;
use LocalAgent\ProjectConfigSyncScheduler;
use LocalAgent\ShutdownSignal;
use LocalAgent\PushManager;
use LocalAgent\SafetyChecker;
use LocalAgent\Security;
use LocalAgent\WorkspaceSourcePreparer;

/**
 * Reported on every heartbeat so the Hub can flag a developer machine
 * running an outdated agent (check-and-notify only — see
 * config('deployment.latest_local_agent_version')'s own docblock on the
 * Hub side for why this never triggers an automatic download). Bump
 * when agent.php or lib/* changes in a way that matters operationally.
 */
const LOCAL_AGENT_VERSION = '1.4.3';

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
// Root-cause fix: per-purpose, configurable command timeouts (a hung
// `git add -A` on a huge diff previously had only a flat, effectively
// unenforced ceiling) plus process-tree termination on Windows — see
// GitManager's own docblock. job_heartbeat_interval_seconds paces how
// often a long-running command's $onTick callback (wired below, in
// pollAndExecutePublish()) actually fires a PublishJob heartbeat.
$git = new GitManager(
    timeouts: [
        'status' => (int) ($config['git_status_timeout_seconds'] ?? 30),
        'add' => (int) ($config['git_add_timeout_seconds'] ?? 120),
        'commit' => (int) ($config['git_commit_timeout_seconds'] ?? 60),
        'push' => (int) ($config['git_push_timeout_seconds'] ?? 180),
        'pull' => (int) ($config['git_pull_timeout_seconds'] ?? 180),
    ],
    jobHeartbeatIntervalSeconds: (int) ($config['job_heartbeat_interval_seconds'] ?? 25),
);
$commits = new CommitManager($git);
$pushes = new PushManager($git);
$safety = new SafetyChecker();
// Root-cause fix (production has no access to repo.packagist.org,
// api.github.com, or the npm registry): built right after a successful
// publish (see pollAndExecutePublish()) — LocalBuildRunner runs
// composer/npm HERE (where they work), ArtifactBuilder assembles a
// complete, runtime-ready artifact, and the production Server Agent
// downloads the result instead of ever needing dependency/build tools
// or external registry access at all.
//
// Root-cause fix (v1.4.1): a Windows Scheduled Task's own PATH does not
// necessarily contain composer/npm/php even when an interactive shell's
// does (confirmed: Laragon's PATH additions are shell-session-scoped) —
// composer_command/npm_command/php_binary let an operator pin the exact
// executable(s) in config.php instead of depending on PATH resolution.
$composerCommand = resolveCommandArrayConfig($config, 'composer_command', ['composer']);
$npmCommand = resolveCommandArrayConfig($config, 'npm_command', ['npm']);
$phpBinary = resolvePhpBinaryConfig($config);
// Root-cause fix (v1.4.2): composer install/npm ci now run inside a
// disposable, isolated workspace under this — NEVER inside any
// project's own directory — so a file locked by Windows/VS Code/an
// editor inside the REAL node_modules/vendor can never block a publish;
// see BuildWorkspace's own docblock for the exact bug this fixes.
$workspaceBaseDir = str_replace('\\', '/', rtrim((string) ($config['workspace_base_dir'] ?? (sys_get_temp_dir() . '/DeploymentLocalAgent/builds')), '\\/'));
$workspaceTtlSeconds = (int) ($config['workspace_ttl_seconds'] ?? 86400);
// Root-cause fix (v1.4.2, npm process-tail on Windows): npm can finish
// all of its visible work and still be a genuinely live OS process for a
// long tail afterward (registry-client keep-alive sockets, the
// update-notifier check) — see GitManager's and LocalBuildRunner's own
// docblocks for the full, instrumented audit. This only ever grants MORE
// time to a build whose own output already shows it succeeded; it never
// weakens detection of an actually-stuck build.
$npmGracePeriodSeconds = (int) ($config['npm_grace_period_seconds'] ?? 120);
// Root-cause fix (customer-site large-project builds): the default
// composer/npm build timeout is 1800s (30 min); each is configurable via
// config.php for projects that legitimately need more. The previous
// deployment-local-agent package default was 600s, which killed a large
// `composer install` right after "Generating optimized autoload files".
$composerTimeoutSeconds = (int) ($config['composer_timeout_seconds'] ?? 1800);
$npmInstallTimeoutSeconds = (int) ($config['npm_install_timeout_seconds'] ?? 1800);
$npmBuildTimeoutSeconds = (int) ($config['npm_build_timeout_seconds'] ?? 1800);
$artifactUploader = new ArtifactUploader(
    new LocalBuildRunner(
        $git,
        $composerCommand,
        $npmCommand,
        $phpBinary,
        composerTimeoutSeconds: $composerTimeoutSeconds,
        npmInstallTimeoutSeconds: $npmInstallTimeoutSeconds,
        npmBuildTimeoutSeconds: $npmBuildTimeoutSeconds,
        logger: $logger,
        npmGracePeriodSeconds: $npmGracePeriodSeconds,
    ),
    new WorkspaceSourcePreparer($git),
    new ArtifactBuilder(),
    $client,
    $logger,
    $workspaceBaseDir,
    $workspaceTtlSeconds,
    agentVersion: LOCAL_AGENT_VERSION,
);
$jobMaxRuntimeSeconds = (int) ($config['job_max_runtime_seconds'] ?? 1800);

// Project config is downloaded from the Hub (single source of truth) and
// cached locally so the agent keeps working while the Hub is temporarily
// unreachable. The manually-authored `projects` array in config.php is
// kept only as a last-resort compatibility fallback — see
// syncProjectConfig() below and ProjectConfigResolver::resolve().
$cachePath = $config['cache_path'] ?? (dirname($config['log_dir']) . '/cache/projects.json');
$cache = new ProjectConfigCache($cachePath);
$projectConfigResolver = new ProjectConfigResolver($cache, $logger);
$legacyProjects = is_array($config['projects'] ?? null) ? $config['projects'] : [];

// Re-syncing on every single poll cycle downloaded and re-validated the
// full project list every 15s for no reason most of the time — this
// scheduler gates a real Hub round trip behind a TTL (default 5 minutes),
// a change in the Hub-reported config_version (piggybacked on the
// heartbeat this agent already sends every cycle), or an explicit
// `reload_config` Control Job. Startup (the first cycle) always syncs
// regardless. See ProjectConfigSyncScheduler.
$projectConfigTtlSeconds = (int) ($config['project_config_ttl_seconds'] ?? 300);
$syncScheduler = new ProjectConfigSyncScheduler($projectConfigTtlSeconds);
$lastSyncedAt = null;
$knownConfigVersion = null;
$reloadRequested = false;
$projects = [];
$projectStateReporter = new LocalProjectStateReporter();

$runOnce = in_array('--once', $argv, true);

// Root-cause fix (Watchdog, section 9): lets the independent
// heartbeat-watchdog.php process find and safely tree-kill THIS specific
// process by PID if it ever hangs unresponsive — never by image name
// (which could kill an unrelated php.exe). Best-effort only: a stale file
// left behind by an unclean exit is harmless, since the watchdog always
// re-validates the PID still belongs to a php.exe process before acting
// on it.
$pidFilePath = dirname($config['log_dir']) . '/agent.pid';
@file_put_contents($pidFilePath, (string) getmypid());

$logger->info('Local Publisher Agent starting' . ($runOnce ? ' (single poll)' : ' (continuous)') . ' (v' . LOCAL_AGENT_VERSION . ')');

/** @var array<string, array<int, string>> $lastReportedChanges project_key => last file list reported this run */
$lastReportedChanges = [];

$shuttingDown = false;
registerGracefulShutdown($logger, $shuttingDown);

do {
    // Heartbeat first (not after sync, as before) so this cycle's
    // config_version is known before deciding whether to sync — see
    // ProjectConfigSyncScheduler::shouldSync().
    $heartbeat = sendHeartbeat($client, $logger, $projectStateReporter->collect($git, $projects, $logger));

    if ($syncScheduler->shouldSync($lastSyncedAt, $knownConfigVersion, $heartbeat['project_config_version'], $reloadRequested, time())) {
        $syncResult = syncProjectConfig($client, $cache, $projectConfigResolver, $logger, $legacyProjects);
        $projects = $syncResult['projects'];
        $lastSyncedAt = time();
        if ($syncResult['synced']) {
            $knownConfigVersion = $syncResult['config_version'];
        }
    }
    // Consumed regardless of what triggered shouldSync() above — a
    // Control Job's reload request only ever needs to force exactly one
    // sync attempt, not every cycle from here on.
    $reloadRequested = false;

    try {
        pollAndExecutePublish($client, $git, $commits, $pushes, $safety, $artifactUploader, $projects, $logger, $jobMaxRuntimeSeconds);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during poll cycle: ' . $e->getMessage());
    }

    try {
        pollAndDetectChanges($client, $git, $projects, $logger, $lastReportedChanges);
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during change-detection cycle: ' . $e->getMessage());
    }

    try {
        // A reload_config job claimed this cycle only takes effect
        // starting next cycle's shouldSync() check — same deferred
        // timing as every other control action here (e.g. `restart`
        // itself doesn't exit until this same call returns).
        if (pollAndHandleControl($client, $config, $logger)) {
            $reloadRequested = true;
        }
    } catch (\Throwable $e) {
        $logger->error('Unhandled error during control-job poll cycle: ' . $e->getMessage());
    }

    // Checked only here — once a full cycle has finished cleanly, never
    // mid-step — so a Ctrl+C (or a `restart` control job's own exit,
    // handled inside pollAndHandleControl()) can't land between `git add`
    // and `git commit`.
    if ($shuttingDown) {
        $logger->info('Local Publisher Agent shutting down gracefully.');
        @unlink($pidFilePath);
        exit(0);
    }

    if (!$runOnce) {
        sleep($config['poll_interval_seconds']);
    }
} while (!$runOnce);

function pollAndExecutePublish(Client $client, GitManager $git, CommitManager $commits, PushManager $pushes, SafetyChecker $safety, ArtifactUploader $artifactUploader, array $projects, Logger $logger, int $jobMaxRuntimeSeconds): void
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
    $projectConfig = $projects[$projectKey] ?? null;
    $reportPath = "/api/local-agent/publish-jobs/{$job['id']}/report";
    $heartbeatPath = "/api/local-agent/publish-jobs/{$job['id']}/heartbeat";

    if ($projectConfig === null) {
        $logger->error("Unknown project: job {$job['id']} references project_key '{$projectKey}', which is not present in the synchronized, cached, or legacy project configuration on this agent.");
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Unknown project_key '{$projectKey}' on this agent",
        ]);

        return;
    }

    $repositoryPath = $projectConfig['local_path'];
    $remoteName = $projectConfig['remote_name'] ?? 'origin';
    $branch = $projectConfig['branch'] ?? 'main';

    if (!is_dir($repositoryPath)) {
        $logger->error("Invalid local path: project '{$projectKey}' is configured with local_path '{$repositoryPath}', which does not exist on this machine.");
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => "Invalid local path '{$repositoryPath}' for project '{$projectKey}'",
        ]);

        return;
    }

    // Root-cause fix: fired periodically by GitManager::run() while a
    // long git command (add/commit/push/revert) is still in progress —
    // renews this job's Hub-side lease (see PublishJobService::heartbeat())
    // so a slow-but-alive command is never mistaken for an abandoned one
    // by deployments:recover-stale. Deliberately a *different* HTTP call
    // than the agent's own general heartbeat() — see agent.php's docblock
    // on sendHeartbeat().
    $onTick = static function () use ($client, $heartbeatPath, $logger, $job): void {
        $response = $client->request('POST', $heartbeatPath);
        if ($response['status'] !== 200) {
            $logger->warn("Job {$job['id']}: heartbeat returned HTTP {$response['status']}");
        }
    };

    $jobStartedAt = microtime(true);

    try {
        if ($job['action'] !== 'status_check' && SafetyChecker::productionSourcePathForbidden($repositoryPath)) {
            $message = "Refusing to use production-like directory '{$repositoryPath}' as a publish/artifact source. Configure this project mapping to a dedicated build checkout; production directories must never push to GitHub or build release artifacts.";
            $logger->error("Job {$job['id']}: {$message}");
            $client->request('POST', $reportPath, [
                'status' => 'failed',
                'error_message' => $message,
                'failure_code' => 'production_source_forbidden',
            ]);

            return;
        }

        if ($job['action'] === 'status_check') {
            $logger->info("Job {$job['id']}: checking git status for {$projectKey} at {$repositoryPath}");
            $rawFiles = $git->changedFiles($repositoryPath);
            $changedFiles = IgnoreRules::filter($rawFiles, IgnoreRules::loadPatterns($repositoryPath));
            // Phase 5.5: report what was discarded too, not just what
            // survived — the operator should see a blocked .env the same
            // way they see an allowed file, never just silence.
            $blockedFiles = array_values(array_diff($rawFiles, $changedFiles));
            $reportResponse = $client->request('POST', $reportPath, [
                'changed_files' => $changedFiles,
                'blocked_files' => $blockedFiles,
                'head_sha' => $git->lastCommitHash($repositoryPath),
                'branch' => $git->currentBranch($repositoryPath),
                'working_tree_clean' => $changedFiles === [] && $blockedFiles === [],
            ]);
            $logger->info("Job {$job['id']}: reported " . count($changedFiles) . ' changed file(s), ' . count($blockedFiles) . ' blocked file(s)' . ($reportResponse['status'] === 200 ? '' : ' (Hub HTTP ' . $reportResponse['status'] . ')'));

            return;
        }

        if ($job['action'] === 'build_artifact') {
            $commitHash = $job['artifact_commit_hash'] ?? null;
            $artifactBranch = $job['artifact_branch'] ?? $branch;
            if (!is_string($commitHash) || preg_match('/^[0-9a-f]{40}$/', $commitHash) !== 1) {
                throw new \RuntimeException('Artifact build job is missing a valid commit SHA.');
            }
            if (!is_string($artifactBranch) || $artifactBranch === '') {
                throw new \RuntimeException('Artifact build job is missing a valid branch.');
            }

            $logger->info("Job {$job['id']}: building artifact for {$projectKey} commit {$commitHash} from {$remoteName}/{$artifactBranch}; no commit or push will be performed.");
            $artifactResult = $artifactUploader->buildAndUploadRemoteCommit($projectKey, $repositoryPath, $remoteName, $commitHash, $artifactBranch, (string) $job['id'], $onTick, $projectConfig['application_root'] ?? '.');

            if (!$artifactResult['succeeded']) {
                $logger->error("Job {$job['id']}: artifact build failed [{$artifactResult['failureCode']}]: {$artifactResult['message']}");
                $client->request('POST', $reportPath, [
                    'status' => 'failed',
                    'error_message' => $artifactResult['message'],
                    'failure_code' => $artifactResult['failureCode'],
                    'output' => '[artifact] FAILED: ' . $artifactResult['message'],
                ]);

                return;
            }

            $client->request('POST', $reportPath, ['status' => 'succeeded', 'commit_hash' => $commitHash, 'output' => '[artifact] uploaded: ' . $artifactResult['message']]);
            $logger->info("Job {$job['id']}: artifact built for {$commitHash}");

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
                $commits->addAndCommit($repositoryPath, $changedFiles, (string) $job['commit_message'], $onTick);
            }

            assertWithinMaxRuntime($jobStartedAt, $jobMaxRuntimeSeconds, (string) $job['id'], $logger);

            $output = $pushes->push($repositoryPath, $remoteName, $branch, $onTick);
            $commitHash = $git->lastCommitHash($repositoryPath);

            // Root-cause fix (production has no access to
            // repo.packagist.org, api.github.com, or the npm registry):
            // the push to GitHub already succeeded at this point, but the
            // publish itself is now only reported `succeeded` once the
            // full local build + artifact pipeline (composer/npm,
            // archive+assembly, upload) has ALSO succeeded — production
            // depends entirely on that artifact existing now that it can
            // never run composer/npm itself, so a broken build here is a
            // genuine publish failure, not a side note.
            $artifactResult = $artifactUploader->buildAndUpload($projectKey, $repositoryPath, $commitHash, $branch, (string) $job['id'], $onTick, $projectConfig['application_root'] ?? '.');

            if (!$artifactResult['succeeded']) {
                $logger->error("Job {$job['id']}: publish failed at the artifact stage [{$artifactResult['failureCode']}]: {$artifactResult['message']}");
                $client->request('POST', $reportPath, [
                    'status' => 'failed',
                    'error_message' => $artifactResult['message'],
                    'failure_code' => $artifactResult['failureCode'],
                    'output' => $output . "\n[artifact] FAILED: " . $artifactResult['message'],
                ]);

                return;
            }

            $output .= "\n[artifact] uploaded: " . $artifactResult['message'];

            $client->request('POST', $reportPath, ['status' => 'succeeded', 'commit_hash' => $commitHash, 'output' => $output]);
            $logger->info("Job {$job['id']}: published as {$commitHash}");

            return;
        }

        if ($job['action'] === 'rollback') {
            $logger->info("Job {$job['id']}: rolling back {$projectKey} ({$repositoryPath})");
            $safety->assertSafeToPublish($git, $repositoryPath, $branch);
            $lastCommit = $git->lastCommitHash($repositoryPath);
            $revertCommit = $commits->revert($repositoryPath, $lastCommit, $onTick);

            assertWithinMaxRuntime($jobStartedAt, $jobMaxRuntimeSeconds, (string) $job['id'], $logger);

            $output = $pushes->push($repositoryPath, $remoteName, $branch, $onTick);

            $client->request('POST', $reportPath, ['status' => 'succeeded', 'commit_hash' => $revertCommit, 'output' => $output]);
            $logger->info("Job {$job['id']}: reverted {$lastCommit} as {$revertCommit}");

            return;
        }

        $logger->error("Job {$job['id']}: unknown action '{$job['action']}'");
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => "Unknown action '{$job['action']}'"]);
    } catch (GitCommandException $e) {
        // Distinct failure_code so the Hub can tell "the agent's own
        // command timed out" (this) apart from a plain git failure (a
        // merge conflict, a rejected push, ...) and from a
        // deployments:recover-stale-inferred `abandoned` (the agent never
        // came back to report anything at all).
        $logger->error("Job {$job['id']} " . ($e->timedOut ? 'timed out' : 'failed') . ': ' . $e->getMessage());
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'failure_code' => $e->timedOut ? 'process_timeout' : null,
        ]);
    } catch (JobMaxRuntimeExceededException $e) {
        $logger->error("Job {$job['id']}: " . $e->getMessage());
        $client->request('POST', $reportPath, [
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'failure_code' => 'process_timeout',
        ]);
    } catch (\Throwable $e) {
        $logger->error("Job {$job['id']} failed: " . $e->getMessage());
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => $e->getMessage()]);
    }
}

/**
 * Root-cause fix (defense in depth): a hard, whole-job wall-clock ceiling
 * on top of every individual git command's own timeout — catches a
 * publish/rollback that's cumulatively run too long even though no single
 * command exceeded its own limit. Checked between steps (add/commit,
 * then push), never mid-command.
 */
function assertWithinMaxRuntime(float $jobStartedAt, int $jobMaxRuntimeSeconds, string $jobId, Logger $logger): void
{
    $elapsed = microtime(true) - $jobStartedAt;

    if ($elapsed >= $jobMaxRuntimeSeconds) {
        throw new JobMaxRuntimeExceededException("Job {$jobId} exceeded the maximum allowed runtime of {$jobMaxRuntimeSeconds}s (elapsed " . round($elapsed) . 's).');
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
function pollAndDetectChanges(Client $client, GitManager $git, array $projects, Logger $logger, array &$lastReportedChanges): void
{
    foreach ($projects as $projectKey => $projectConfig) {
        $repositoryPath = $projectConfig['local_path'];

        if (!is_dir($repositoryPath)) {
            $logger->warn("Invalid local path: project '{$projectKey}' is configured with local_path '{$repositoryPath}', which does not exist on this machine — skipping change detection for it.");

            continue;
        }

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
 *
 * @return bool true when a `reload_config` job was claimed this cycle —
 *     the caller forces a project-config sync attempt starting next
 *     cycle (see ProjectConfigSyncScheduler's $reloadRequested param).
 *     This is also the "manual reload command": an Admin has no other
 *     way to reach this process, so clicking "Force Config Reload" in
 *     the Hub (which queues exactly this job) *is* the manual command.
 */
function pollAndHandleControl(Client $client, array $config, Logger $logger): bool
{
    $response = $client->request('GET', '/api/local-agent/control-jobs/next');

    if ($response['status'] !== 200) {
        $logger->warn("Control-job poll returned HTTP {$response['status']}");

        return false;
    }

    $job = $response['body']['job'] ?? null;
    if ($job === null) {
        return false; // nothing queued
    }

    $reportPath = "/api/local-agent/control-jobs/{$job['id']}/report";

    try {
        if ($job['action'] === 'fetch_logs') {
            $logger->info("Control job {$job['id']}: fetching recent log output");
            $output = LogTail::tail($config['log_dir'], 200);
            $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => $output]);

            return false;
        }

        if ($job['action'] === 'restart') {
            $logger->info("Control job {$job['id']}: restart requested — reporting success, then exiting");
            $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => 'Restarting now.']);
            // Deliberately non-zero: Task Scheduler's RestartOnFailure must
            // reliably relaunch this process regardless of how it defines
            // "successful completion" for a 0-exit process.
            exit(1);
        }

        if ($job['action'] === 'reload_config') {
            $logger->info("Control job {$job['id']}: configuration reload requested — resynchronizing on the next cycle");
            $client->request('POST', $reportPath, ['status' => 'succeeded', 'output' => 'Project configuration will be resynchronized now.']);

            return true;
        }

        $logger->error("Control job {$job['id']}: unknown action '{$job['action']}'");
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => "Unknown action '{$job['action']}'"]);

        return false;
    } catch (\Throwable $e) {
        $logger->error("Control job {$job['id']} failed: " . $e->getMessage());
        $client->request('POST', $reportPath, ['status' => 'failed', 'error_message' => $e->getMessage()]);

        return false;
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

/**
 * Project configuration source of truth: the Hub, via
 * GET /api/local-agent/projects. Downloads and validates this agent's
 * assigned projects, caches them locally, and returns them for this
 * cycle's use. Falls back — in order — to the last good local cache, then
 * to the manually-authored `projects` array in config.php (compatibility
 * only), whenever the Hub can't be reached or its response is malformed.
 * Never throws: every failure path is caught and logged, returning
 * whatever configuration source is still usable (or [] if none is).
 *
 * Only called when ProjectConfigSyncScheduler::shouldSync() says so —
 * not on every cycle — so `synced: false` here always means a *real*
 * sync attempt failed this cycle, not that one was merely skipped.
 *
 * @param array<string, array{local_path: string, remote_name: string, branch: string}> $legacyProjects
 * @return array{projects: array<string, array{local_path: string, remote_name: string, branch: string}>, config_version: string|null, synced: bool}
 */
function syncProjectConfig(Client $client, ProjectConfigCache $cache, ProjectConfigResolver $resolver, Logger $logger, array $legacyProjects): array
{
    try {
        $response = $client->request('GET', '/api/local-agent/projects');
    } catch (\Throwable $e) {
        $logger->warn('Hub unavailable for project sync: ' . $e->getMessage());

        return ['projects' => $resolver->resolve($legacyProjects), 'config_version' => null, 'synced' => false];
    }

    if ($response['status'] !== 200) {
        $logger->error("Synchronization failure: Hub returned HTTP {$response['status']} for /api/local-agent/projects.");

        return ['projects' => $resolver->resolve($legacyProjects), 'config_version' => null, 'synced' => false];
    }

    $rawProjects = $response['body']['projects'] ?? null;
    if (!is_array($rawProjects)) {
        $logger->error('Synchronization failure: malformed response from Hub (missing "projects" array).');

        return ['projects' => $resolver->resolve($legacyProjects), 'config_version' => null, 'synced' => false];
    }

    try {
        $projects = ProjectConfigCache::fromApiResponse($rawProjects);
    } catch (\InvalidArgumentException $e) {
        $logger->error('Synchronization failure: invalid project configuration received from Hub — ' . $e->getMessage());

        return ['projects' => $resolver->resolve($legacyProjects), 'config_version' => null, 'synced' => false];
    }

    try {
        $cache->save($projects);
        $logger->info("Cache updated at {$cache->path()}");
    } catch (\Throwable $e) {
        // A cache write failure doesn't invalidate this cycle's freshly
        // synchronized config — only the *next* Hub-unavailable fallback
        // loses this snapshot. Logged, not fatal.
        $logger->warn('Could not update local project cache: ' . $e->getMessage());
    }

    // A successful sync that legitimately returns zero projects must
    // never be confused with a Hub-unreachable failure — this is the
    // Hub telling us, correctly, "you have no Local Project Mappings
    // yet." Distinguished from every failure branch above, which never
    // reach this line.
    if ($projects === []) {
        $logger->warn('Hub reachable and synchronized successfully, but this agent has zero assigned projects — no Local Project Mappings exist for it yet. Create one in the Hub (Local Agents -> Project mappings) if you expected projects to sync here.');
    } else {
        $logger->info('Configuration synchronized: ' . count($projects) . ' project(s) from Hub.');
    }

    return ['projects' => $projects, 'config_version' => $response['body']['config_version'] ?? null, 'synced' => true];
}

/**
 * @return array{project_config_version: string|null}
 */
function sendHeartbeat(Client $client, Logger $logger, array $projectStates = []): array
{
    try {
        $response = $client->request('POST', '/api/local-agent/heartbeat', [
            'hostname' => gethostname() ?: null,
            'operating_system' => PHP_OS_FAMILY,
            'agent_version' => LOCAL_AGENT_VERSION,
            'capabilities' => ['git' => true, 'php' => PHP_VERSION, 'laravel_release_manifest' => true],
            'project_states' => $projectStates,
        ]);

        $latestVersion = $response['body']['latest_version'] ?? null;
        if ($latestVersion !== null && version_compare(LOCAL_AGENT_VERSION, (string) $latestVersion, '<')) {
            $logger->warn("Update available: v{$latestVersion} (running v" . LOCAL_AGENT_VERSION . ") — run upgrade.ps1 from the deployment-local-agent package to update.");
        }

        return ['project_config_version' => $response['body']['project_config_version'] ?? null];
    } catch (\Throwable $e) {
        $logger->warn('Heartbeat failed: ' . $e->getMessage());

        return ['project_config_version' => null];
    }
}

/**
 * Root-cause fix (v1.4.1): reads an optional argv-array config key
 * (composer_command / npm_command) and validates it strictly before it
 * ever reaches proc_open() — every element must be a non-empty string,
 * since this is passed as an argv array (never a shell string), a
 * malformed entry cannot cause command injection, only a process that
 * fails to start with a clear error. Missing entirely (`not defined`)
 * falls back to $default, preserving the previous, PATH-dependent
 * behavior for every agent that never needed this.
 *
 * @param array<string, mixed> $config
 * @param array<int, string> $default
 * @return array<int, string>
 */
function resolveCommandArrayConfig(array $config, string $key, array $default): array
{
    if (!array_key_exists($key, $config)) {
        return $default;
    }

    $value = $config[$key];

    if (!is_array($value) || $value === []) {
        fwrite(STDERR, "config.php: '{$key}' must be a non-empty array of strings (an argv list, e.g. ['composer'] or ['C:\\\\path\\\\to\\\\php.exe', 'C:\\\\path\\\\to\\\\composer.phar']) — got " . get_debug_type($value) . ". Refusing to start.\n");
        exit(1);
    }

    foreach ($value as $part) {
        if (!is_string($part) || $part === '') {
            fwrite(STDERR, "config.php: '{$key}' must contain only non-empty strings — every element is one argv entry, not a shell command line. Refusing to start.\n");
            exit(1);
        }
    }

    return array_values($value);
}

/**
 * Optional — only meaningful when composer_command points at a bare
 * `*.phar` with no interpreter in front of it (see LocalBuildRunner's own
 * docblock). Absent or blank is valid and simply disables that one
 * fallback.
 *
 * @param array<string, mixed> $config
 */
function resolvePhpBinaryConfig(array $config): ?string
{
    if (!array_key_exists('php_binary', $config)) {
        return null;
    }

    $value = $config['php_binary'];

    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "config.php: 'php_binary', when present, must be a non-empty string (path to a php executable). Refusing to start.\n");
        exit(1);
    }

    return $value;
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
        . "    'request_timeout_seconds' => 30,\n"
        . "    'projects' => [],\n"
        . "    'log_dir' => __DIR__ . '/storage/logs',\n"
        . "];\n";

    file_put_contents($configPath, $configContent);
    @chmod($configPath, 0600);

    echo "ثبت‌نام انجام شد — این کارگزار منتظر تایید ادمین در پنل است.\n";
    echo "config.php در " . $configPath . " ساخته شد.\n";
    echo "پس از تایید در پنل، مقادیر agent_token و signing_secret نمایش‌داده‌شده را در همین فایل جایگزین کنید، سپس یک نگاشت پروژه (projects) اضافه کنید و «php agent.php» را اجرا کنید.\n";
}
