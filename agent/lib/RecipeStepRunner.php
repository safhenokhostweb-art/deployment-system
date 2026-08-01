<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Executes a `deployment_recipe` job — the typed-step alternative to the
 * legacy flat `pipeline` loop in agent.php's pollAndExecute(). Every
 * concept from the audit's root-cause finding is addressed here: an
 * immutable per-deployment release directory (ReleaseManager), an atomic
 * symlink activation, a real health-check gate before anything is
 * reported `succeeded`, and automatic rollback the instant a
 * post-activation step fails — so "Deployment succeeded" can no longer
 * mean anything other than "the new code is actually running and
 * healthy".
 *
 * Every typed step (everything except `shell`) runs via
 * Executor::runArgv() — a literal argv array, no shell involved — so
 * there is no shell-escaping surface for any of it; only `shell` (kept
 * for parity with the legacy `pipeline` column) goes through
 * ShellPlaceholders + Executor::run()'s string form.
 */
final class RecipeStepRunner
{
    /** Deployment::FAILURE_CODE_* value strings, duplicated here (no shared autoload between the Hub and this dependency-free agent) — see the Hub-side Deployment model for the authoritative list. */
    private const FAILURE_BUILD_FAILED = 'build_failed';
    private const FAILURE_MIGRATION_FAILED = 'migration_failed';
    private const FAILURE_MIGRATION_REQUIRES_APPROVAL = 'migration_requires_approval';
    private const FAILURE_ACTIVATION_FAILED = 'activation_failed';
    private const FAILURE_SERVICE_RESTART_FAILED = 'service_restart_failed';
    private const FAILURE_HEALTH_CHECK_FAILED = 'health_check_failed';

    private const PHASE_BY_STEP_TYPE = [
        'checkout_release' => 'building',
        'verify_runtime' => 'building',
        'verify_disk_space' => 'building',
        'verify_path_exists' => 'building',
        'verify_file_not_contains' => 'building',
        'install_dependencies' => 'building',
        'run_script' => 'building',
        'assemble_next_standalone' => 'building',
        'prisma_migrate' => 'migrating',
        'activate_release' => 'activating',
        'set_ownership' => 'activating',
        'restart_service' => 'activating',
        'verify_service_active' => 'verifying',
        'http_health_check' => 'verifying',
    ];

    private const DESTRUCTIVE_SQL_KEYWORDS = ['DROP TABLE', 'DROP COLUMN', 'DROP DATABASE', 'TRUNCATE'];

    /** Set by runCheckoutRelease() so a later activate_release/cleanup in the same job knows which persistent clone to `git worktree remove` old releases from. */
    private ?string $lastCheckoutSourceRepository = null;

    public function __construct(
        private readonly Executor $executor,
        private readonly Client $client,
        private readonly Logger $logger,
        private readonly ReleaseManager $releases,
    ) {
    }

    /**
     * @param array<string, mixed> $job the verified, claimed job payload
     * @param array<string, mixed> $projectConfig this agent's local config entry for the project
     */
    public function run(array $job, array $projectConfig): void
    {
        if ($job['recipe_rollback'] ?? false) {
            $this->runRollback($job, $projectConfig);

            return;
        }

        $jobId = $job['id'];
        $recipe = $job['deployment_recipe'];
        $releaseRoot = rtrim($projectConfig['working_directory'], '/');
        $releasePath = $this->releases->releasePath($releaseRoot, $jobId);

        $tokens = [
            '{release_path}' => $releasePath,
            '{project_root}' => $releaseRoot,
            '{commit}' => $job['commit_hash'] ?? '',
            '{previous_commit}' => $job['previous_commit_hash'] ?? '',
            '{branch}' => $job['branch'] ?? '',
        ];

        $jobDeadline = microtime(true) + (float) ($recipe['timeout_seconds'] ?? 7200);
        $onTick = $this->makeHeartbeatTick($jobId, (float) ($projectConfig['job_heartbeat_interval_seconds'] ?? 30));

        $this->reportStatus($jobId, 'running');

        $activationHappened = false;
        $restartedServices = [];
        /** @var array<int, array<string, mixed>> $verificationSteps */
        $verificationSteps = [];
        $currentPhase = 'running';
        $sequence = 0;

        foreach ($recipe['steps'] as $step) {
            $sequence++;

            $remainingBudget = $jobDeadline - microtime(true);
            if ($remainingBudget <= 0) {
                $this->handleFailure(
                    $jobId, $releaseRoot, $activationHappened, $restartedServices, $verificationSteps,
                    $projectConfig, $tokens, $onTick, self::FAILURE_BUILD_FAILED,
                    "Deployment exceeded its total configured timeout of {$recipe['timeout_seconds']}s.",
                );

                return;
            }

            $phase = self::PHASE_BY_STEP_TYPE[$step['type']] ?? $currentPhase;
            if ($phase !== $currentPhase) {
                $currentPhase = $phase;
                $this->reportStatus($jobId, $phase);
            }

            if (in_array($step['type'], ['http_health_check', 'verify_service_active'], true)) {
                $verificationSteps[] = $step;
            }

            $stepTimeout = (int) max(1, min((int) $step['timeout_seconds'], (int) $remainingBudget));
            $result = $this->executeStep($step, $tokens, $releaseRoot, $releasePath, $projectConfig, $stepTimeout, $onTick);

            $this->reportLog($jobId, $sequence, $step['name'], $step['type'], $result);

            if ($step['type'] === 'restart_service') {
                $restartedServices[] = strtr($step['service'], $tokens);
            }
            if ($step['type'] === 'activate_release' && $result['status'] === 'succeeded') {
                $activationHappened = true;
            }

            if ($result['status'] !== 'succeeded') {
                $this->handleFailure(
                    $jobId, $releaseRoot, $activationHappened, $restartedServices, $verificationSteps,
                    $projectConfig, $tokens, $onTick, $result['failureCode'], $result['message'],
                );

                return;
            }
        }

        $this->reportStatus($jobId, 'succeeded');
        $this->logger->info("Recipe deployment {$jobId} finished: succeeded, release {$releasePath}");
    }

    /**
     * A fast, artifact-reuse rollback: no recipe steps are (re)built —
     * `current` is swapped back to whatever `previous` points at, the
     * project's services are restarted, and every verification step in
     * the CURRENT recipe (health checks, service-active checks) is
     * replayed against the restored release. Used both for an automatic
     * post-activation-failure rollback and a manual "Rollback" request
     * from the dashboard — recipe_rollback:true is the only signal that
     * distinguishes this from a normal build.
     */
    private function runRollback(array $job, array $projectConfig): void
    {
        $jobId = $job['id'];
        $releaseRoot = rtrim($projectConfig['working_directory'], '/');
        $previousPath = $this->releases->previousReleasePath($releaseRoot);

        if ($previousPath === null || !is_dir($previousPath)) {
            $this->reportStatus($jobId, 'failed', 'No previous release is available to roll back to.', 'activation_failed');

            return;
        }

        $this->reportStatus($jobId, 'activating');
        $this->releases->activate($releaseRoot, $previousPath);

        $recipe = $job['deployment_recipe'] ?? null;
        $steps = $recipe['steps'] ?? [];
        $tokens = ['{release_path}' => $previousPath, '{project_root}' => $releaseRoot];
        $onTick = $this->makeHeartbeatTick($jobId, (float) ($projectConfig['job_heartbeat_interval_seconds'] ?? 30));

        $sequence = 0;
        foreach ($steps as $step) {
            if ($step['type'] !== 'restart_service') {
                continue;
            }
            $sequence++;
            $result = $this->executeStep($step, $tokens, $releaseRoot, $previousPath, $projectConfig, (int) $step['timeout_seconds'], $onTick);
            $this->reportLog($jobId, $sequence, $step['name'], $step['type'], $result);
            if ($result['status'] !== 'succeeded') {
                $this->reportStatus($jobId, 'failed', "Rollback restart of the previous release's services failed: {$result['message']}", self::FAILURE_SERVICE_RESTART_FAILED);

                return;
            }
        }

        $this->reportStatus($jobId, 'verifying');
        foreach ($steps as $step) {
            if (!in_array($step['type'], ['http_health_check', 'verify_service_active'], true)) {
                continue;
            }
            $sequence++;
            $result = $this->executeStep($step, $tokens, $releaseRoot, $previousPath, $projectConfig, (int) $step['timeout_seconds'], $onTick);
            $this->reportLog($jobId, $sequence, $step['name'], $step['type'], $result);
            if ($result['status'] !== 'succeeded') {
                $this->reportStatus($jobId, 'failed', "The previous release failed verification after rollback: {$result['message']}", self::FAILURE_HEALTH_CHECK_FAILED);

                return;
            }
        }

        $this->reportStatus($jobId, 'rolled_back', "Rolled back to release at {$previousPath} and verified it is healthy.");
        $this->logger->info("Deployment {$jobId} rolled back to {$previousPath}.");
    }

    /**
     * Reached only when a step other than the initial ones has failed
     * mid-recipe. If activation had already happened, this is not just
     * "report failed" — the new (bad) release is live, so the agent
     * reverts it immediately rather than leaving a broken production
     * service for a human to notice later.
     *
     * @param array<int, string> $restartedServices
     * @param array<int, array<string, mixed>> $verificationSteps
     */
    private function handleFailure(
        string $jobId,
        string $releaseRoot,
        bool $activationHappened,
        array $restartedServices,
        array $verificationSteps,
        array $projectConfig,
        array $tokens,
        callable $onTick,
        ?string $failureCode,
        ?string $message,
    ): void {
        if (!$activationHappened) {
            $this->reportStatus($jobId, 'failed', $message, $failureCode);
            $this->logger->error("Recipe deployment {$jobId} failed before activation: {$message}");

            return;
        }

        $this->logger->error("Recipe deployment {$jobId} failed after activation ({$message}); attempting automatic rollback.");

        $previousPath = $this->releases->previousReleasePath($releaseRoot);
        if ($previousPath === null || !is_dir($previousPath)) {
            $this->reportStatus($jobId, 'failed', "Activation succeeded but a later step failed ({$message}), and no previous release exists to roll back to — manual intervention required.", $failureCode);

            return;
        }

        $this->releases->activate($releaseRoot, $previousPath);

        $rollbackTokens = ['{release_path}' => $previousPath] + $tokens;
        foreach ($restartedServices as $service) {
            $restartStep = ['type' => 'restart_service', 'name' => 'rollback restart', 'service' => $service, 'timeout_seconds' => 60];
            $this->executeStep($restartStep, $rollbackTokens, $releaseRoot, $previousPath, $projectConfig, 60, $onTick);
        }

        $verified = true;
        foreach ($verificationSteps as $step) {
            $result = $this->executeStep($step, $rollbackTokens, $releaseRoot, $previousPath, $projectConfig, (int) $step['timeout_seconds'], $onTick);
            if ($result['status'] !== 'succeeded') {
                $verified = false;
                break;
            }
        }

        if ($verified) {
            $this->reportStatus(
                $jobId,
                'rolled_back',
                "Deployment failed after activation ({$message}) — automatically rolled back to the previous release, which is verified healthy.",
                $failureCode,
            );
            $this->logger->info("Recipe deployment {$jobId} automatically rolled back to {$previousPath} after: {$message}");

            return;
        }

        $this->reportStatus(
            $jobId,
            'failed',
            "Deployment failed after activation ({$message}), and the automatic rollback to the previous release ALSO failed verification — manual intervention required immediately.",
            $failureCode,
        );
        $this->logger->error("Recipe deployment {$jobId}: automatic rollback verification also failed.");
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, string> $tokens
     * @param array<string, mixed> $projectConfig
     * @return array{status: string, exitCode: ?int, output: string, durationMs: int, timedOut: bool, failureCode: ?string, message: ?string}
     */
    private function executeStep(array $step, array $tokens, string $releaseRoot, string $releasePath, array $projectConfig, int $timeoutSeconds, callable $onTick): array
    {
        try {
            return match ($step['type']) {
                'shell' => $this->runShell($step, $tokens, $releasePath, $timeoutSeconds, $onTick),
                'checkout_release' => $this->runCheckoutRelease($step, $tokens, $releasePath, $timeoutSeconds, $onTick),
                'verify_runtime' => $this->runVerifyRuntime($step, $timeoutSeconds, $onTick),
                'verify_disk_space' => $this->runVerifyDiskSpace($step, $tokens),
                'verify_path_exists' => $this->runVerifyPathExists($step, $tokens),
                'verify_file_not_contains' => $this->runVerifyFileNotContains($step, $tokens),
                'install_dependencies' => $this->runInstallDependencies($step, $tokens, $timeoutSeconds, $onTick),
                'run_script' => $this->runRunScript($step, $tokens, $timeoutSeconds, $onTick),
                'prisma_migrate' => $this->runPrismaMigrate($step, $tokens, $timeoutSeconds, $onTick),
                'assemble_next_standalone' => $this->runAssembleNextStandalone($step, $tokens),
                'activate_release' => $this->runActivateRelease($releaseRoot, $releasePath, $projectConfig, $this->lastCheckoutSourceRepository ?? null),
                'restart_service' => $this->runRestartService($step, $tokens, $projectConfig, $timeoutSeconds, $onTick),
                'verify_service_active' => $this->runVerifyServiceActive($step, $tokens, $projectConfig, $timeoutSeconds, $onTick),
                'http_health_check' => $this->runHttpHealthCheck($step),
                'set_ownership' => $this->runSetOwnership($step, $tokens, $timeoutSeconds, $onTick),
                default => $this->failed(self::FAILURE_BUILD_FAILED, "Unknown step type '{$step['type']}' — this agent does not implement it."),
            };
        } catch (\Throwable $e) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Step '{$step['name']}' threw an unexpected error: {$e->getMessage()}");
        }
    }

    private function runShell(array $step, array $tokens, string $releasePath, int $timeoutSeconds, callable $onTick): array
    {
        $escaped = ShellPlaceholders::escape($tokens);
        $command = strtr($step['command'], $escaped);
        $cwd = isset($step['working_directory']) ? strtr($step['working_directory'], $tokens) : $releasePath;

        $result = $this->executor->run($command, $cwd, $timeoutSeconds, $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_BUILD_FAILED, $command);
    }

    private function runCheckoutRelease(array $step, array $tokens, string $releasePath, int $timeoutSeconds, callable $onTick): array
    {
        $sourceRepository = strtr($step['source_repository'], $tokens);
        $this->lastCheckoutSourceRepository = $sourceRepository;
        $commit = $tokens['{commit}'] ?? '';

        $result = $this->releases->checkout($this->executor, $sourceRepository, $releasePath, $commit, $timeoutSeconds, $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_BUILD_FAILED, "checkout {$commit} from {$sourceRepository} into {$releasePath}");
    }

    private function runVerifyRuntime(array $step, int $timeoutSeconds, callable $onTick): array
    {
        $binary = $step['runtime'] === 'php' ? 'php' : $step['runtime'];
        $result = $this->executor->runArgv([$binary, '--version'], sys_get_temp_dir(), $timeoutSeconds, [], $onTick);

        if ($result['exitCode'] !== 0) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Could not determine {$step['runtime']} version — is it installed and on PATH?");
        }

        if (!preg_match('/(\d+\.\d+\.\d+)/', $result['output'], $matches)) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Could not parse a version number out of: {$result['output']}");
        }

        if (version_compare($matches[1], $step['min_version'], '<')) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "{$step['runtime']} {$matches[1]} is older than the required minimum {$step['min_version']}.");
        }

        return $this->succeeded("{$step['runtime']} {$matches[1]} satisfies minimum {$step['min_version']}.");
    }

    private function runVerifyDiskSpace(array $step, array $tokens): array
    {
        $path = strtr($step['path'], $tokens);
        $checkPath = is_dir($path) ? $path : dirname($path);

        $free = @disk_free_space($checkPath);
        if ($free === false) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Could not determine free disk space at {$checkPath}.");
        }

        $freeMb = (int) ($free / 1024 / 1024);
        if ($freeMb < $step['min_free_mb']) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Only {$freeMb}MB free at {$checkPath}, need at least {$step['min_free_mb']}MB.");
        }

        return $this->succeeded("{$freeMb}MB free at {$checkPath}.");
    }

    private function runVerifyPathExists(array $step, array $tokens): array
    {
        $path = strtr($step['path'], $tokens);
        $exists = $step['kind'] === 'directory' ? is_dir($path) : is_file($path);

        if (!$exists) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Expected {$step['kind']} does not exist: {$path}");
        }

        return $this->succeeded("{$path} exists.");
    }

    private function runVerifyFileNotContains(array $step, array $tokens): array
    {
        $path = strtr($step['path'], $tokens);
        $forbidden = $step['forbidden'];

        if (!file_exists($path)) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Cannot scan {$path} for '{$forbidden}' — it does not exist.");
        }

        $offender = is_dir($path) ? self::findForbiddenInDirectory($path, $forbidden) : self::fileContains($path, $forbidden);

        if ($offender !== null) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Found forbidden string '{$forbidden}' in {$offender} — the build likely used the wrong environment configuration.");
        }

        return $this->succeeded("No occurrence of '{$forbidden}' found under {$path}.");
    }

    private static function findForbiddenInDirectory(string $dir, string $forbidden, int $depth = 0): ?string
    {
        if ($depth > 12) {
            return null;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..', 'node_modules', 'cache']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = self::findForbiddenInDirectory($path, $forbidden, $depth + 1);
                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if (self::fileContains($path, $forbidden) !== null) {
                return $path;
            }
        }

        return null;
    }

    private static function fileContains(string $path, string $forbidden): ?string
    {
        if (!is_file($path) || (filesize($path) ?: 0) > 20_000_000) {
            return null;
        }

        $contents = @file_get_contents($path);

        return ($contents !== false && str_contains($contents, $forbidden)) ? $path : null;
    }

    private function runInstallDependencies(array $step, array $tokens, int $timeoutSeconds, callable $onTick): array
    {
        // RecipeValidator only ever allows manager:'npm' today — the
        // explicit check (rather than assuming) means this fails loudly
        // instead of silently misbehaving if that ever changes without a
        // matching update here.
        if ($step['manager'] !== 'npm') {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Unsupported dependency manager '{$step['manager']}'.");
        }

        $cwd = strtr($step['working_directory'], $tokens);
        $argv = ['npm', $step['mode'] === 'ci' ? 'ci' : 'install'];

        $result = $this->executor->runArgv($argv, $cwd, $timeoutSeconds, [], $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_BUILD_FAILED, implode(' ', $argv) . " in {$cwd}");
    }

    private function runRunScript(array $step, array $tokens, int $timeoutSeconds, callable $onTick): array
    {
        $cwd = strtr($step['working_directory'], $tokens);
        $result = $this->executor->runArgv(['npm', 'run', $step['script']], $cwd, $timeoutSeconds, $step['env'] ?? [], $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_BUILD_FAILED, "npm run {$step['script']} in {$cwd}");
    }

    /**
     * Approximates "destructive migration detection" by scanning every
     * migration.sql file under prisma/migrations for a destructive
     * keyword — not a precise pending-vs-applied diff (that would need
     * to parse `prisma migrate status` output, a meaningfully bigger
     * undertaking), but a conservative, agent-local safety net: any
     * destructive keyword anywhere in the migrations directory blocks an
     * `allow_destructive: false` recipe from proceeding at all. Flagged
     * as a known limitation, not silently treated as exact.
     */
    private function runPrismaMigrate(array $step, array $tokens, int $timeoutSeconds, callable $onTick): array
    {
        $cwd = strtr($step['working_directory'], $tokens);

        if ($step['mode'] === 'status') {
            $result = $this->executor->runArgv(['npx', 'prisma', 'migrate', 'status'], $cwd, $timeoutSeconds, [], $onTick);

            return $this->fromExecutorResult($result, self::FAILURE_MIGRATION_FAILED, "prisma migrate status in {$cwd}");
        }

        if (!$step['allow_destructive']) {
            $offender = self::findDestructiveMigration($cwd . '/prisma/migrations');
            if ($offender !== null) {
                return [
                    'status' => 'failed',
                    'exitCode' => null,
                    'output' => "Destructive keyword found in {$offender}.",
                    'durationMs' => 0,
                    'timedOut' => false,
                    'failureCode' => self::FAILURE_MIGRATION_REQUIRES_APPROVAL,
                    'message' => "Migration {$offender} contains a potentially destructive statement (DROP/TRUNCATE). Re-run with allow_destructive:true after review to proceed.",
                ];
            }
        }

        $result = $this->executor->runArgv(['npx', 'prisma', 'migrate', 'deploy'], $cwd, $timeoutSeconds, [], $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_MIGRATION_FAILED, "prisma migrate deploy in {$cwd}");
    }

    private static function findDestructiveMigration(string $migrationsDir): ?string
    {
        if (!is_dir($migrationsDir)) {
            return null;
        }

        foreach (array_diff(scandir($migrationsDir) ?: [], ['.', '..']) as $entry) {
            $sqlFile = $migrationsDir . '/' . $entry . '/migration.sql';
            if (!is_file($sqlFile)) {
                continue;
            }

            $sql = strtoupper((string) @file_get_contents($sqlFile));
            foreach (self::DESTRUCTIVE_SQL_KEYWORDS as $keyword) {
                if (str_contains($sql, $keyword)) {
                    return $entry . '/migration.sql';
                }
            }
        }

        return null;
    }

    /**
     * Copies what Next.js's `output: 'standalone'` documented behavior
     * requires but does not do automatically: static assets and public
     * files are excluded from the standalone trace and must be copied in
     * by hand. This is the officially documented Next.js requirement,
     * not a guess about sandogh-family-fund's specific layout — see the
     * final report's "remaining risks" for what should be confirmed
     * against the real repository during the Phase 7 dry run.
     */
    private function runAssembleNextStandalone(array $step, array $tokens): array
    {
        $appPath = strtr($step['app_path'], $tokens);
        $releasePath = $tokens['{release_path}'];
        $appDir = "{$releasePath}/{$appPath}";
        $standaloneAppDir = "{$appDir}/.next/standalone/{$appPath}";

        if (!is_dir($standaloneAppDir)) {
            return $this->failed(self::FAILURE_BUILD_FAILED, "Standalone output not found at {$standaloneAppDir} — did `next build` run with output:'standalone'?");
        }

        $copied = [];

        if (is_dir("{$appDir}/.next/static")) {
            self::copyRecursive("{$appDir}/.next/static", "{$standaloneAppDir}/.next/static");
            $copied[] = '.next/static';
        }

        if (is_dir("{$appDir}/public")) {
            self::copyRecursive("{$appDir}/public", "{$standaloneAppDir}/public");
            $copied[] = 'public';
        }

        if (!is_dir("{$standaloneAppDir}/.next/cache")) {
            @mkdir("{$standaloneAppDir}/.next/cache", 0755, true);
        }

        return $this->succeeded('Assembled standalone output: ' . (implode(', ', $copied) ?: '(nothing to copy)'));
    }

    private static function copyRecursive(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }

        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            $fromPath = "{$from}/{$entry}";
            $toPath = "{$to}/{$entry}";

            if (is_dir($fromPath)) {
                self::copyRecursive($fromPath, $toPath);
            } else {
                copy($fromPath, $toPath);
            }
        }
    }

    private function runActivateRelease(string $releaseRoot, string $releasePath, array $projectConfig, ?string $sourceRepository): array
    {
        $this->releases->activate($releaseRoot, $releasePath);

        $retentionCount = (int) ($projectConfig['release_retention_count'] ?? 5);
        $removed = $this->releases->cleanupOldReleases($this->executor, $releaseRoot, $retentionCount, $sourceRepository);

        return $this->succeeded("Activated {$releasePath}." . ($removed === [] ? '' : ' Cleaned up ' . count($removed) . ' old release(s).'));
    }

    private function runRestartService(array $step, array $tokens, array $projectConfig, int $timeoutSeconds, callable $onTick): array
    {
        $service = strtr($step['service'], $tokens);
        $allowed = $projectConfig['allowed_services'] ?? [];

        if (!ServiceAllowlist::isAllowed($service, $allowed)) {
            return [
                'status' => 'failed', 'exitCode' => null, 'output' => '', 'durationMs' => 0, 'timedOut' => false,
                'failureCode' => self::FAILURE_SERVICE_RESTART_FAILED,
                'message' => "'{$service}' is not in this agent's allowed_services for this project — refusing to restart it.",
            ];
        }

        $result = $this->executor->runArgv(['systemctl', 'restart', $service], sys_get_temp_dir(), $timeoutSeconds, [], $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_SERVICE_RESTART_FAILED, "systemctl restart {$service}");
    }

    private function runVerifyServiceActive(array $step, array $tokens, array $projectConfig, int $timeoutSeconds, callable $onTick): array
    {
        $service = strtr($step['service'], $tokens);
        $allowed = $projectConfig['allowed_services'] ?? [];

        if (!ServiceAllowlist::isAllowed($service, $allowed)) {
            return [
                'status' => 'failed', 'exitCode' => null, 'output' => '', 'durationMs' => 0, 'timedOut' => false,
                'failureCode' => self::FAILURE_HEALTH_CHECK_FAILED,
                'message' => "'{$service}' is not in this agent's allowed_services for this project.",
            ];
        }

        $result = $this->executor->runArgv(['systemctl', 'is-active', $service], sys_get_temp_dir(), $timeoutSeconds, [], $onTick);
        $isActive = $result['exitCode'] === 0 && trim($result['output']) === 'active';

        if (!$isActive) {
            return $this->failed(self::FAILURE_HEALTH_CHECK_FAILED, "systemctl reports '{$service}' is not active: " . trim($result['output']));
        }

        return $this->succeeded("'{$service}' is active.");
    }

    private function runHttpHealthCheck(array $step): array
    {
        $attempts = 1 + (int) $step['retries'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($step['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $step['request_timeout_seconds'],
                CURLOPT_CONNECTTIMEOUT => $step['request_timeout_seconds'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $statusOk = $body !== false && $status === $step['expected_status'];
            $bodyOk = $step['must_not_contain'] === null || ($body !== false && !str_contains($body, $step['must_not_contain']));

            if ($statusOk && $bodyOk) {
                return $this->succeeded("{$step['url']} returned {$status} as expected.");
            }

            if ($attempt < $attempts) {
                sleep((int) $step['retry_delay_seconds']);
            } else {
                $reason = $body === false
                    ? "request failed: {$error}"
                    : (!$statusOk ? "expected status {$step['expected_status']}, got {$status}" : "response contained forbidden string '{$step['must_not_contain']}'");

                return $this->failed(self::FAILURE_HEALTH_CHECK_FAILED, "{$step['url']} failed health check after {$attempts} attempt(s): {$reason}");
            }
        }

        return $this->failed(self::FAILURE_HEALTH_CHECK_FAILED, "{$step['url']} failed health check.");
    }

    private function runSetOwnership(array $step, array $tokens, int $timeoutSeconds, callable $onTick): array
    {
        $path = strtr($step['path'], $tokens);
        $result = $this->executor->runArgv(['chown', '-R', $step['owner'], $path], sys_get_temp_dir(), $timeoutSeconds, [], $onTick);

        return $this->fromExecutorResult($result, self::FAILURE_ACTIVATION_FAILED, "chown -R {$step['owner']} {$path}");
    }

    /**
     * @return array{status: string, exitCode: ?int, output: string, durationMs: int, timedOut: bool, failureCode: ?string, message: ?string}
     */
    private function fromExecutorResult(array $result, string $failureCode, string $label): array
    {
        if ($result['exitCode'] !== 0) {
            $reason = $result['timedOut'] ? 'timed out' : "exited with code {$result['exitCode']}";

            return [
                'status' => 'failed',
                'exitCode' => $result['exitCode'],
                'output' => $result['output'],
                'durationMs' => $result['durationMs'],
                'timedOut' => $result['timedOut'],
                'failureCode' => $failureCode,
                'message' => "{$label} {$reason}.",
            ];
        }

        return [
            'status' => 'succeeded',
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'durationMs' => $result['durationMs'],
            'timedOut' => $result['timedOut'],
            'failureCode' => null,
            'message' => null,
        ];
    }

    /** @return array{status: string, exitCode: ?int, output: string, durationMs: int, timedOut: bool, failureCode: ?string, message: ?string} */
    private function succeeded(string $message): array
    {
        return ['status' => 'succeeded', 'exitCode' => 0, 'output' => $message, 'durationMs' => 0, 'timedOut' => false, 'failureCode' => null, 'message' => null];
    }

    /** @return array{status: string, exitCode: ?int, output: string, durationMs: int, timedOut: bool, failureCode: ?string, message: ?string} */
    private function failed(string $failureCode, string $message): array
    {
        return ['status' => 'failed', 'exitCode' => null, 'output' => $message, 'durationMs' => 0, 'timedOut' => false, 'failureCode' => $failureCode, 'message' => $message];
    }

    private function makeHeartbeatTick(string $jobId, float $intervalSeconds): callable
    {
        $last = 0.0;

        return function () use ($jobId, $intervalSeconds, &$last): void {
            $now = microtime(true);
            if ($now - $last < $intervalSeconds) {
                return;
            }
            $last = $now;
            $this->client->request('POST', "/api/agent/jobs/{$jobId}/heartbeat");
        };
    }

    private function reportStatus(string $jobId, string $status, ?string $message = null, ?string $failureCode = null): void
    {
        $payload = ['status' => $status];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($failureCode !== null) {
            $payload['failure_code'] = $failureCode;
        }

        $this->client->request('POST', "/api/agent/jobs/{$jobId}/status", $payload);
    }

    /**
     * @param array{status: string, exitCode: ?int, output: string, durationMs: int, timedOut: bool, failureCode: ?string, message: ?string} $result
     */
    private function reportLog(string $jobId, int $sequence, string $stepName, string $stepType, array $result): void
    {
        $this->client->request('POST', "/api/agent/jobs/{$jobId}/log", [
            'sequence' => $sequence,
            'step_name' => $stepName,
            'command' => $stepName,
            'output' => mb_substr($result['output'] ?? '', -50000),
            'exit_code' => $result['exitCode'],
            'duration_ms' => $result['durationMs'],
            'timed_out' => $result['timedOut'],
            'status' => $result['status'],
            'step_type' => $stepType,
            'failure_code' => $result['failureCode'],
        ]);
    }
}
