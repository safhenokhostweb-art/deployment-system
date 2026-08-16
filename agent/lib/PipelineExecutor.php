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
    public function __construct(
        private readonly Client $client,
        private readonly Executor $executor,
        private readonly ArtifactManager $artifactManager,
        private readonly Logger $logger,
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
        if (!empty($job['artifact'])) {
            $sequence++;
            $artifactResult = $this->artifactManager->deploy(
                $job['artifact'],
                $workingDirectory,
                $projectConfig['artifact_preserve_paths'] ?? [],
                (int) ($projectConfig['artifact_retention_count'] ?? 3),
            );

            $this->client->request('POST', "/api/agent/jobs/{$job['id']}/log", [
                'sequence' => $sequence,
                'step_name' => 'deploy artifact',
                'command' => "download+extract+activate artifact {$job['artifact']['public_id']}",
                'output' => $artifactResult['message'] ?? 'Artifact activated successfully.',
                'exit_code' => $artifactResult['status'] === 'succeeded' ? 0 : 1,
                'duration_ms' => null,
                'timed_out' => false,
            ]);

            if ($artifactResult['status'] !== 'succeeded') {
                $this->logger->error("Job {$job['id']}: artifact deployment failed: {$artifactResult['message']}");
                $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
                    'status' => 'failed',
                    'message' => $artifactResult['message'],
                    'failure_code' => $artifactResult['failureCode'],
                ]);

                return;
            }

            $usedArtifact = true;
            $this->logger->info("Job {$job['id']}: artifact activated successfully.");
        }

        foreach ($job['pipeline'] as $step) {
            if ($usedArtifact && ($step['skip_when_artifact'] ?? false)) {
                $this->logger->info("Job {$job['id']}: skipping step '{$step['name']}' — its work was already done by the artifact deploy above.");

                continue;
            }

            $sequence++;
            if (($step['type'] ?? null) === 'runtime_permissions') {
                $command = 'sudo -n /usr/local/sbin/deployment-runtime-permissions ' . $job['project_key'];
                $result = $this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', $job['project_key']], $workingDirectory, $stepTimeout);
            } else {
                $command = strtr($step['command'], $replacements);
                $result = $this->executor->run($command, $workingDirectory, $stepTimeout);
            }

            $this->logger->info("Job {$job['id']} step {$sequence} ({$step['name']}): {$command}");

            if (str_contains($command, 'artisan down') && $result['exitCode'] === 0) {
                $maintenanceEnabled = true;
            }

            $this->client->request('POST', "/api/agent/jobs/{$job['id']}/log", [
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
                if ($maintenanceEnabled && is_file(rtrim($workingDirectory, '/') . '/artisan')) {
                    $cleanup = $this->executor->runArgv([$projectConfig['php_binary'] ?? 'php', 'artisan', 'up'], $workingDirectory, min($stepTimeout, 60));
                    $this->client->request('POST', "/api/agent/jobs/{$job['id']}/log", [
                        'sequence' => ++$sequence, 'step_name' => 'maintenance cleanup', 'command' => 'artisan up',
                        'output' => mb_substr($cleanup['output'], -50000), 'exit_code' => $cleanup['exitCode'],
                        'duration_ms' => $cleanup['durationMs'], 'timed_out' => $cleanup['timedOut'],
                    ]);
                    $this->logger->error("Job {$job['id']}: maintenance cleanup " . ($cleanup['exitCode'] === 0 ? 'succeeded' : 'failed') . ' after the original failure.');
                }
                break;
            }
        }

        $this->client->request('POST', "/api/agent/jobs/{$job['id']}/status", [
            'status' => $failed ? 'failed' : 'succeeded',
        ]);

        $this->logger->info("Job {$job['id']} finished: " . ($failed ? 'failed' : 'succeeded'));
    }
}
