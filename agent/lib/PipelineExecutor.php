<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * The legacy flat `pipeline` execution loop — extracted verbatim out of
 * agent.php's pollAndExecute() (a behavior-preserving move, not a
 * rewrite) so it can be exercised by a real, direct test. A monolithic
 * top-level script has no function-level testability of its own; this
 * class is the smallest reasonable seam that lets
 * "production skips git/composer/npm ONLY when an artifact is present",
 * "no pipeline step runs after a failed artifact activation", and
 * "a project with no artifact runs every step exactly as before" all be
 * proven directly rather than only by code review.
 *
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry): when `job['artifact']` is
 * present, ArtifactManager downloads/verifies/extracts/activates it
 * BEFORE any pipeline step runs; any step flagged `skip_when_artifact`
 * in the Project's pipeline JSON (the git-checkout, composer install,
 * and asset-build steps in the updated presets) is then skipped — every
 * other step (migrate, cache clear, queue restart) still runs against
 * the newly-activated code. A project with no artifact never sets
 * `$usedArtifact`, so nothing is skipped and the exact legacy pipeline
 * runs unchanged.
 */
final class PipelineExecutor
{
    /** Max bytes of helper output written to the local journal on failure. */
    private const RUNTIME_PERMISSIONS_LOCAL_OUTPUT_MAX_BYTES = 4096;

    public function __construct(
        private readonly Client $client,
        private readonly Executor $executor,
        private readonly ArtifactManager $artifactManager,
        private readonly Logger $logger,
        /** @var (\Closure(string, string, int): array{exitCode: int, output: string, timedOut: bool, durationMs: int})|null Test seam; production always uses the fixed helper argv below. */
        private readonly ?\Closure $runtimePermissionsRunner = null,
        private readonly ?HttpHealthCheckRunner $httpHealth = null,
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $projectConfig
     */
    public function execute(array $job, array $projectConfig, int $defaultStepTimeoutSeconds): void
    {
        $this->logger->info("Starting job {$job['id']} ({$job['action']}) for {$job['project_key']} @ {$job['commit_hash']}");
        $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", ['status' => 'running']);

        $workingDirectory = $projectConfig['working_directory'];
        if (ProductionPathGuard::isProductionLike((string) $workingDirectory) && empty($job['artifact'])) {
            $message = "Production target '{$workingDirectory}' accepts deployment artifacts only; server-side Git/build deployment is forbidden.";
            $this->logger->error("Job {$job['id']}: {$message}");
            $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                'status' => 'failed',
                'message' => $message,
                'failure_code' => 'artifact_required_missing',
            ]);

            return;
        }
        $stepTimeout = $projectConfig['step_timeout_seconds'] ?? $defaultStepTimeoutSeconds;

        $replacements = ShellPlaceholders::escape([
            '{branch}' => $job['branch'] ?? '',
            '{commit}' => $job['commit_hash'] ?? '',
            '{previous_commit}' => $job['previous_commit_hash'] ?? '',
            '{working_directory}' => $workingDirectory,
            '{php_binary}' => $projectConfig['php_binary'] ?? 'php',
        ]);

        $sequence = 0;
        $failed = false;
        $maintenanceEnabled = false;

        $usedArtifact = false;
        $runtimePermissionsNormalized = false;
        if (!empty($job['artifact'])) {
            $hasSignedPersistence = is_array($job['artifact_persistence'] ?? null);
            $persistence = $hasSignedPersistence
                ? $job['artifact_persistence']
                : [];
            $persistentPaths = is_array($persistence['paths'] ?? null)
                ? $persistence['paths']
                : ($projectConfig['artifact_persistent_paths'] ?? []);
            $persistentRoot = is_string($persistence['root'] ?? null)
                ? $persistence['root']
                : ($projectConfig['artifact_persistent_root'] ?? null);
            if (array_key_exists('preserve_paths', $persistence) && is_array($persistence['preserve_paths'])) {
                $preservePaths = $persistence['preserve_paths'];
            } elseif ($hasSignedPersistence) {
                // Compatibility for Hub 1.3.1's partial signed contract:
                // retain legacy local preserve entries except those now
                // owned by the signed persistent paths. This keeps .env but
                // removes stale database/database.sqlite-style overlaps,
                // allowing the Hub to deploy the protocol-completion fix.
                $preservePaths = self::withoutPersistentOverlaps(
                    $projectConfig['artifact_preserve_paths'] ?? [],
                    $persistentPaths,
                );
            } else {
                $preservePaths = $projectConfig['artifact_preserve_paths'] ?? [];
            }

            // Persistent targets are privileged runtime state. Prepare and
            // normalize them through the fixed project-key allowlist before
            // extracting or activating a candidate release. A missing helper,
            // invalid project key, ownership failure, or permission failure
            // therefore leaves the current release completely untouched.
            if ($persistentPaths !== []) {
                $sequence++;
                $command = 'sudo -n /usr/local/sbin/deployment-runtime-permissions ' . $job['project_key'];
                $contractError = self::persistentContractError($job['project_key'], $persistentPaths, $persistentRoot, $workingDirectory);
                if ($contractError !== null) {
                    $this->sendLog($job, [
                        'sequence' => $sequence,
                        'step_name' => 'prepare persistent runtime',
                        'command' => $command,
                        'output' => $contractError,
                        'exit_code' => 1,
                        'duration_ms' => 0,
                        'timed_out' => false,
                        'failure_code' => 'artifact_persistent_config_invalid',
                    ]);
                    $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                        'status' => 'failed',
                        'message' => $contractError,
                        'failure_code' => 'artifact_persistent_config_invalid',
                    ]);

                    return;
                }
                $result = $this->runRuntimePermissions($job['project_key'], $workingDirectory, $stepTimeout);
                $this->sendLog($job, [
                    'sequence' => $sequence,
                    'step_name' => 'prepare persistent runtime',
                    'command' => $command,
                    'output' => mb_substr($result['output'], -50000),
                    'exit_code' => $result['exitCode'],
                    'duration_ms' => $result['durationMs'],
                    'timed_out' => $result['timedOut'],
                    'failure_code' => $result['exitCode'] === 0 ? null : 'artifact_persistent_permissions_invalid',
                ]);

                if ($result['exitCode'] !== 0) {
                    $message = 'Persistent runtime preparation failed before artifact activation; the live release was not changed.';
                    $this->logger->error("Job {$job['id']}: {$message} " . self::formatRuntimePermissionsFailureOutput($result['output']));
                    $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                        'status' => 'failed',
                        'message' => $message,
                        'failure_code' => 'artifact_persistent_permissions_invalid',
                    ]);

                    return;
                }
            }

            $sequence++;

            // PHASE 1 — PREPARE: download/verify/extract + carry over legacy
            // preserve paths + install persistent references into a staging
            // sibling. No live release mutation happens here — every failure
            // in this phase leaves the currently-active release completely
            // untouched, and maintenance has not been entered yet.
            $prepared = $this->artifactManager->prepare(
                $job['artifact'],
                $workingDirectory,
                $preservePaths,
                $persistentPaths,
                $persistentRoot,
                null,
                $projectConfig['php_binary'] ?? 'php',
            );

            if ($prepared['status'] !== 'succeeded' || $prepared['stagingPath'] === null) {
                $this->sendLog($job, [
                    'sequence' => $sequence,
                    'step_name' => 'prepare artifact',
                    'command' => "prepare artifact {$job['artifact']['public_id']}",
                    'output' => mb_substr((string) ($prepared['message'] ?? 'Artifact preparation failed.'), -50000),
                    'exit_code' => 1,
                    'duration_ms' => null,
                    'timed_out' => false,
                    'failure_code' => $prepared['failureCode'] ?? null,
                ]);
                $this->logger->error("Job {$job['id']}: artifact preparation failed: {$prepared['message']}");
                $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                    'status' => 'failed',
                    'message' => $prepared['message'],
                    'failure_code' => $prepared['failureCode'] ?? null,
                ]);

                return;
            }

            // Root-cause fix (Deployment #183 observability gap): a
            // successful PREPARE used to be invisible in the Hub's own
            // log — this sequence number was silently consumed and no row
            // was ever written for it, so an operator reading the Hub log
            // could not tell prepare ever ran versus never having reached
            // this phase at all. Every phase of an artifact deploy
            // (prepare, each before_activation step, activate) is now a
            // distinct, always-logged, ordered event.
            $this->sendLog($job, [
                'sequence' => $sequence,
                'step_name' => 'prepare artifact',
                'command' => "prepare artifact {$job['artifact']['public_id']}",
                'output' => mb_substr((string) ($prepared['message'] ?? 'Artifact prepared successfully.'), -50000),
                'exit_code' => 0,
                'duration_ms' => null,
                'timed_out' => false,
            ]);

            // Before-activation steps (e.g. `artisan down` maintenance entry
            // marked 'before_activation') run against the CURRENT release
            // while the prepared staging is not yet live. A failure here means
            // maintenance may have been entered but the release was never
            // touched: discard the staging, clean up maintenance, fail.
            foreach ($job['pipeline'] as $step) {
                if (($step['before_activation'] ?? false) !== true) {
                    continue;
                }

                $sequence++;
                $command = strtr($step['command'], $replacements);

                // Root-cause fix (Deployment #183 — masked maintenance
                // failure): a before_activation step's exit code is
                // safety-load-bearing — it is the ONLY signal that gates
                // whether activation is allowed to proceed (below). A
                // trailing unconditional-success shell suffix (`|| true`,
                // `|| :`) defeats that signal for ANY before_activation
                // command, regardless of what it runs, by forcing the
                // shell's own exit code to 0 even when the real command
                // failed. Stripped ONLY for before_activation steps —
                // every other pipeline step keeps whatever best-effort
                // semantics its own command expresses.
                $command = self::withoutUnconditionalSuccessSuffix($command);
                $result = $this->executor->run($command, $workingDirectory, $stepTimeout);
                $this->logger->info("Job {$job['id']} step {$sequence} ({$step['name']}) [before activation]: {$command}");

                // A before_activation step is, by definition, the
                // mechanism that makes activation safe — whether it
                // succeeds or fails below, maintenance cleanup (a
                // harmless, idempotent `artisan up`) must be considered
                // eligible on any later failure. This generic contract
                // holds for every before_activation step regardless of
                // what command it runs or which project it belongs to —
                // no command-text inspection needed.
                $maintenanceEnabled = true;

                $this->sendLog($job, [
                    'sequence' => $sequence,
                    'step_name' => $step['name'],
                    'command' => $command,
                    'output' => mb_substr($result['output'], -50000),
                    'exit_code' => $result['exitCode'],
                    'duration_ms' => $result['durationMs'],
                    'timed_out' => $result['timedOut'],
                ]);

                if ($result['exitCode'] !== 0) {
                    $this->logger->error("Job {$job['id']} pre-activation step {$sequence} ({$step['name']}) failed with exit code {$result['exitCode']}");
                    $this->runMaintenanceCleanup($job, $sequence, $workingDirectory, $projectConfig, $stepTimeout, $maintenanceEnabled);
                    $this->artifactManager->discardStaging($prepared['stagingPath']);
                    $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                        'status' => 'failed',
                        'message' => "Pre-activation step '{$step['name']}' failed — the live release was not changed.",
                    ]);

                    return;
                }
            }

            // PHASE 2 — ACTIVATE: atomic swap of the prepared staging.
            //
            // Root-cause fix (Deployment #183 — sequence collision): this
            // used to log at the SAME $sequence value as the
            // before_activation step just above (no increment between
            // them), which the Hub's own unique(agent_job_id, sequence)
            // constraint cannot accept — one of the two log writes was
            // guaranteed to be silently lost (an uncaught, never-checked
            // HTTP 500 to a call site that discarded its response).
            // Activation now always gets its own, distinct sequence.
            $sequence++;
            $artifactResult = $this->artifactManager->activate(
                $workingDirectory,
                $prepared['stagingPath'],
                (int) ($projectConfig['artifact_retention_count'] ?? 3),
                $persistentPaths,
                $persistentRoot,
            );

            $this->sendLog($job, [
                'sequence' => $sequence,
                'step_name' => 'deploy artifact',
                'command' => "download+extract+activate artifact {$job['artifact']['public_id']}",
                'output' => $artifactResult['message'] ?? 'Artifact activated successfully.',
                'exit_code' => $artifactResult['status'] === 'succeeded' ? 0 : 1,
                'duration_ms' => null,
                'timed_out' => false,
            ]);

            if ($artifactResult['status'] !== 'succeeded') {
                $this->logger->error("Job {$job['id']}: artifact activation failed: {$artifactResult['message']}");
                $this->runMaintenanceCleanup($job, $sequence, $workingDirectory, $projectConfig, $stepTimeout, $maintenanceEnabled);
                $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                    'status' => 'failed',
                    'message' => $artifactResult['message'],
                    'failure_code' => $artifactResult['failureCode'],
                ]);

                return;
            }

            $usedArtifact = true;
            $this->logger->info("Job {$job['id']}: artifact activated successfully.");

            // Preserved runtime paths are copied by this unprivileged agent
            // into the staging release before its atomic activation.  Run the
            // project's fixed, sudoers-constrained normalizer immediately
            // after that activation, before *any* application pipeline
            // command (migrate, Artisan, cache, etc.) can touch the new tree.
            // A typed step remains the opt-in contract for legacy preserve
            // projects. Signed persistence always requires the post-swap run:
            // its release-visible symlinks and writable paths do not exist at
            // the live path until activation has completed.
            if ($persistentPaths !== [] || self::hasRuntimePermissionsStep($job['pipeline'] ?? [])) {
                $sequence++;
                $command = 'sudo -n /usr/local/sbin/deployment-runtime-permissions ' . $job['project_key'];
                $result = $this->runRuntimePermissions($job['project_key'], $workingDirectory, $stepTimeout);

                // Local failure diagnostic FIRST, before any Hub call.  The
                // Hub API is known to become unavailable (HTTP 500, or even
                // an outright connection failure) the instant this failure
                // leaves the runtime tree owned by deployment-agent — the
                // AgentJob log POST below is the only other carrier of the
                // helper's stderr diagnostic, and it is exactly the channel
                // that breaks in that window.  Writing locally first means a
                // Hub failure can never erase the copy of the diagnostic that
                // survives on this server.
                if ($result['exitCode'] !== 0) {
                    $this->logger->error(
                        'Job ' . $job['id']
                        . ': Artifact activated, but immediate runtime permission normalization failed. No application pipeline steps were run. '
                        . 'project=' . $job['project_key']
                        . ' exit_code=' . $result['exitCode']
                        . ' timed_out=' . ($result['timedOut'] ? 'true' : 'false')
                        . ' ' . self::formatRuntimePermissionsFailureOutput($result['output'])
                    );
                }

                $this->sendLog($job, [
                    'sequence' => $sequence,
                    'step_name' => 'runtime permissions (post-artifact)',
                    'command' => $command,
                    'output' => mb_substr($result['output'], -50000),
                    'exit_code' => $result['exitCode'],
                    'duration_ms' => $result['durationMs'],
                    'timed_out' => $result['timedOut'],
                ]);

                if ($result['exitCode'] !== 0) {
                    $rollbackError = $this->artifactManager->rollback($workingDirectory);
                    $this->runMaintenanceCleanup($job, $sequence, $workingDirectory, $projectConfig, $stepTimeout, $maintenanceEnabled);
                    $message = 'Artifact activated, but immediate runtime permission normalization failed. No application pipeline steps were run. '
                        . ($rollbackError === null
                            ? 'The previous release was restored.'
                            : "Automatic restore failed: {$rollbackError}");
                    $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                        'status' => 'failed',
                        'message' => $message,
                        'failure_code' => 'runtime_permissions_failed',
                    ]);

                    return;
                }

                $runtimePermissionsNormalized = true;
                $this->logger->info("Job {$job['id']}: runtime permissions normalized immediately after artifact activation.");
            }
        }

        foreach ($job['pipeline'] as $step) {
            if ($usedArtifact && ($step['before_activation'] ?? false) === true) {
                $this->logger->info("Job {$job['id']}: skipping step '{$step['name']}' — it already ran before artifact activation.");

                continue;
            }
            if ($usedArtifact && ($step['skip_when_artifact'] ?? false)) {
                $this->logger->info("Job {$job['id']}: skipping step '{$step['name']}' — its work was already done by the artifact deploy above.");

                continue;
            }

            if ($runtimePermissionsNormalized && ($step['type'] ?? null) === 'runtime_permissions') {
                $this->logger->info("Job {$job['id']}: skipping step '{$step['name']}' — runtime permissions were already normalized immediately after artifact activation.");

                continue;
            }

            $sequence++;
            if (($step['type'] ?? null) === 'runtime_permissions') {
                $command = 'sudo -n /usr/local/sbin/deployment-runtime-permissions ' . $job['project_key'];
                $result = $this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', $job['project_key']], $workingDirectory, $stepTimeout);
            } elseif (($step['type'] ?? null) === 'service_reload') {
                // This is deliberately an argv call to one fixed sudoers-approved
                // helper. Pipeline JSON never controls a service name or command.
                $command = 'sudo -n /usr/local/sbin/deployment-service-reload ' . $job['project_key'];
                $result = $this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-service-reload', $job['project_key']], $workingDirectory, $stepTimeout);
            } else {
                $command = strtr($step['command'], $replacements);
                $result = $this->executor->run($command, $workingDirectory, $stepTimeout);
            }

            $this->logger->info("Job {$job['id']} step {$sequence} ({$step['name']}): {$command}");

            if (str_contains($command, 'artisan down') && $result['exitCode'] === 0) {
                $maintenanceEnabled = true;
            }

            $this->sendLog($job, [
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
                $this->logger->error("Job {$job['id']} step {$sequence} ({$step['name']}) failed with exit code {$result['exitCode']}");
                // Post-activation failure: best-effort restore of the previous
                // release so the site never keeps running a half-activated
                // release, then the guaranteed maintenance cleanup. Persistent
                // DB/storage stay current — rollback switches code only.
                if ($usedArtifact) {
                    $rollbackError = $this->artifactManager->rollback($workingDirectory);
                    $this->logger->error("Job {$job['id']}: post-activation restore " . ($rollbackError === null ? 'succeeded (previous release restored)' : "failed: {$rollbackError}"));
                }
                $this->runMaintenanceCleanup($job, $sequence, $workingDirectory, $projectConfig, $stepTimeout, $maintenanceEnabled);
                break;
            }
        }

        $healthFailure = null;
        if (!$failed && (($job['http_health_check']['enabled'] ?? false) === true)) {
            $sequence++;
            $health = ($this->httpHealth ?? new HttpHealthCheckRunner())->run($job['http_health_check']);
            $this->sendLog($job, [
                'sequence' => $sequence,
                'step_name' => 'customer-facing HTTP health',
                'step_type' => 'http_health_check',
                'command' => 'GET ' . $job['http_health_check']['url'],
                'output' => mb_substr($health['output'], -50000),
                'exit_code' => $health['status'] === 'succeeded' ? 0 : 1,
                'duration_ms' => $health['durationMs'],
                'timed_out' => $health['timedOut'],
                'failure_code' => $health['failureCode'],
            ]);

            if ($health['status'] !== 'succeeded') {
                $failed = true;
                $healthFailure = $health;
                if ($usedArtifact) {
                    $rollbackError = $this->artifactManager->rollback($workingDirectory);
                    $this->logger->error("Job {$job['id']}: HTTP health failed; previous release restore " . ($rollbackError === null ? 'succeeded' : "failed: {$rollbackError}"));
                }
                $this->runMaintenanceCleanup($job, $sequence, $workingDirectory, $projectConfig, $stepTimeout, $maintenanceEnabled);
            }
        }

        $statusPayload = ['status' => $failed ? 'failed' : 'succeeded'];
        if ($healthFailure !== null) {
            $statusPayload['message'] = $healthFailure['message'];
            $statusPayload['failure_code'] = $healthFailure['failureCode'];
        }
        $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", $statusPayload);

        $this->logger->info("Job {$job['id']} finished: " . ($failed ? 'failed' : 'succeeded'));
    }

    /**
     * Guaranteed maintenance cleanup: `artisan up` runs whenever maintenance
     * mode was entered and the current release still has an artisan binary.
     * Used on every failure path (pre-activation, activation, or a later
     * pipeline step) so a failed deploy can never leave the site permanently
     * down. Maintenance mode lives in persistent storage when the project is
     * persistent-configured, so it survives the release swap and this
     * cleanup is the single point that exits it.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $projectConfig
     */
    private function runMaintenanceCleanup(array $job, int &$sequence, string $workingDirectory, array $projectConfig, int $stepTimeout, bool $maintenanceEnabled): void
    {
        if (!$maintenanceEnabled) {
            return;
        }
        if (!is_file(rtrim($workingDirectory, '/') . '/artisan')) {
            return;
        }

        $cleanup = $this->executor->runArgv([$projectConfig['php_binary'] ?? 'php', 'artisan', 'up'], $workingDirectory, min($stepTimeout, 60));
        $this->sendLog($job, [
            'sequence' => ++$sequence, 'step_name' => 'maintenance cleanup', 'command' => 'artisan up',
            'output' => mb_substr($cleanup['output'], -50000), 'exit_code' => $cleanup['exitCode'],
            'duration_ms' => $cleanup['durationMs'], 'timed_out' => $cleanup['timedOut'],
        ]);
        $this->logger->error("Job {$job['id']}: maintenance cleanup " . ($cleanup['exitCode'] === 0 ? 'succeeded' : 'failed') . ' after the original failure.');
    }

    /**
     * Every call site in this file posts a deploy-log entry and discards
     * the response. A Hub-side rejection (e.g. Deployment #183's sequence
     * collision, a unique(agent_job_id, sequence) violation surfacing as
     * an HTTP 500) was therefore completely invisible — the Hub's own
     * record silently lost the entry with no trace anywhere. This does
     * not retry or redesign the transport: it only guarantees a local,
     * server-side diagnostic survives whenever the Hub could not persist
     * the entry, so deployment execution evidence is never silently
     * misleading about what this server actually did.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     */
    private function sendLog(array $job, array $payload): void
    {
        $response = $this->client->request('POST', "/api/agent/jobs/{$job['id']}/log", $payload);

        if ($response['status'] >= 300) {
            $this->logger->error(
                "Job {$job['id']}: Hub rejected log entry for sequence {$payload['sequence']} ('{$payload['step_name']}') with HTTP {$response['status']} "
                . '— this step\'s evidence may be missing from the Hub\'s own record. '
                . 'command=' . $payload['command'] . ' exit_code=' . ($payload['exit_code'] ?? 'null')
            );
        }
    }

    /**
     * Root-cause fix (Deployment #183 — masked maintenance failure): strips
     * a trailing unconditional-success shell suffix (`|| true`, `|| :`)
     * so a before_activation step's real exit code — the sole signal that
     * gates whether artifact activation is allowed to proceed — can never
     * be silently forced to 0 by the command text itself. Generic by
     * design: it inspects only the shell construct, never any specific
     * command (e.g. "artisan down") or project, so it applies uniformly
     * to every before_activation step regardless of what it runs — old,
     * already-persisted pipeline commands are covered automatically, with
     * no Dashboard resave required.
     */
    private static function withoutUnconditionalSuccessSuffix(string $command): string
    {
        return (string) preg_replace('/\s*\|\|\s*(true|:)\s*$/', '', $command);
    }

    /** @param array<int, mixed> $pipeline */
    private static function hasRuntimePermissionsStep(array $pipeline): bool
    {
        foreach ($pipeline as $step) {
            if (is_array($step) && ($step['type'] ?? null) === 'runtime_permissions') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int|string, string> $persistentPaths */
    private static function persistentContractError(string $projectKey, array $persistentPaths, ?string $persistentRoot, string $workingDirectory): ?string
    {
        if (!is_string($persistentRoot) || !str_starts_with($persistentRoot, '/')) {
            return 'Persistent runtime root must be an absolute Unix path; the live release was not changed.';
        }

        if ($projectKey === 'customer-site' && (
            rtrim($persistentRoot, '/') !== '/var/www/customer-site-shared'
            || $persistentPaths !== ['public/uploads' => 'uploads']
        )) {
            return 'customer-site persistent runtime must map public/uploads to /var/www/customer-site-shared/uploads; the live release was not changed.';
        }

        $saasPaths = [
            'storage/app' => 'storage/app',
            'storage/logs' => 'storage/logs',
            'public/storage' => 'public/storage',
            'public/uploads' => 'public/uploads',
        ];
        if ($projectKey === 'saas-platform' && (
            rtrim($persistentRoot, '/') !== '/var/www/saas-platform-shared'
            || $persistentPaths != $saasPaths
        )) {
            return 'saas-platform persistent runtime must use the reviewed storage/app, storage/logs, public/storage, and public/uploads mappings under /var/www/saas-platform-shared; the live release was not changed.';
        }

        // The production customer-site release currently uses the legacy
        // atomic-swap root directly. There is deliberately no `current/`
        // indirection. Fail closed instead of creating a valid-looking link
        // in an unserved release layout; supporting another layout requires
        // a separately implemented and reviewed release-layout migration.
        if ($projectKey === 'customer-site' && rtrim($workingDirectory, '/') !== '/var/www/customer-site') {
            return "customer-site working_directory must be /var/www/customer-site for the current release layout; '/var/www/customer-site/current' is not supported. The live release was not changed.";
        }

        if ($projectKey === 'saas-platform' && rtrim($workingDirectory, '/') !== '/var/www/saas-platform') {
            return "saas-platform working_directory must be /var/www/saas-platform for the current release layout; '/var/www/saas-platform/current' is not supported. The live release was not changed.";
        }

        return null;
    }

    /**
     * @param array<int, string> $preservePaths
     * @param array<int|string, string> $persistentPaths
     * @return array<int, string>
     */
    private static function withoutPersistentOverlaps(array $preservePaths, array $persistentPaths): array
    {
        $releasePaths = [];
        foreach ($persistentPaths as $releaseKey => $targetValue) {
            $releasePaths[] = rtrim(str_replace('\\', '/', trim(is_string($releaseKey) ? $releaseKey : $targetValue)), '/');
        }

        return array_values(array_filter($preservePaths, static function (string $preserve) use ($releasePaths): bool {
            $preserve = rtrim(str_replace('\\', '/', trim($preserve)), '/');
            foreach ($releasePaths as $persistent) {
                if ($preserve === $persistent
                    || str_starts_with($preserve . '/', $persistent . '/')
                    || str_starts_with($persistent . '/', $preserve . '/')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return array{exitCode: int, output: string, timedOut: bool, durationMs: int} */
    private function runRuntimePermissions(string $projectKey, string $workingDirectory, int $stepTimeout): array
    {
        if ($this->runtimePermissionsRunner !== null) {
            return ($this->runtimePermissionsRunner)($projectKey, $workingDirectory, $stepTimeout);
        }

        // This is deliberately a literal argv call to the root-owned helper.
        // No project-provided pipeline field can change its command or args.
        return $this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', $projectKey], $workingDirectory, $stepTimeout);
    }

    /**
     * Bounds the runtime-permissions helper's output for one local journal
     * line. The helper's diagnostics are short by construction, but a
     * misbehaving/looping helper must not be able to flood the journal. An
     * empty result is explicitly marked so "helper produced no diagnostic"
     * is distinguishable from "the diagnostic transport was lost".
     */
    private static function formatRuntimePermissionsFailureOutput(string $output): string
    {
        $output = trim($output);

        if ($output === '') {
            return 'output=<empty>';
        }

        // mb_strcut() is byte-counted and never splits a multi-byte sequence.
        $bounded = mb_strcut($output, 0, self::RUNTIME_PERMISSIONS_LOCAL_OUTPUT_MAX_BYTES);

        return 'output=' . $bounded . ($bounded === $output ? '' : '...[truncated]');
    }
}
