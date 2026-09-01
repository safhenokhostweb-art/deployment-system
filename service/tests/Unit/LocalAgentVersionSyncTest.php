<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regression guard for the exact drift found during Production Readiness
 * verification: LOCAL_AGENT_VERSION was bumped across two phases of real
 * functional changes (project-config sync, then the publish-lease/
 * watchdog root-cause fixes) without ever being incremented, and
 * config('deployment.latest_local_agent_version')'s default had silently
 * fallen BELOW the shipped version — meaning the "outdated agent" warning
 * (LocalAgent::isUpdateAvailable(), the heartbeat response's
 * latest_version field) could never fire even though the real installed
 * agent was two releases behind. This asserts the version number can
 * never silently drift apart across its three copies again.
 */
class LocalAgentVersionSyncTest extends TestCase
{
    public function test_both_agent_trees_report_the_same_local_agent_version(): void
    {
        $deploymentLocalAgentVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));
        $localAgentVersion = $this->extractLocalAgentVersion(base_path('../local-agent/agent.php'));

        $this->assertSame(
            $deploymentLocalAgentVersion,
            $localAgentVersion,
            'deployment-local-agent/agent/agent.php and local-agent/agent.php must ship the exact same LOCAL_AGENT_VERSION — they are kept as mirrored copies.',
        );
    }

    public function test_hub_default_latest_version_matches_the_shipped_agent_version(): void
    {
        $shippedVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));

        $this->assertSame(
            $shippedVersion,
            config('deployment.latest_local_agent_version'),
            "config('deployment.latest_local_agent_version') must match the LOCAL_AGENT_VERSION actually shipped in agent.php, or the outdated-agent warning can never fire for a real out-of-date install.",
        );
    }

    public function test_assigned_artifact_protocol_minimum_matches_the_shipped_agent_and_capability(): void
    {
        $shippedVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));
        $source = file_get_contents(base_path('../deployment-local-agent/agent/agent.php'));

        $this->assertSame($shippedVersion, config('deployment.min_local_agent_version_for_assigned_artifact'));
        $this->assertStringContainsString("'assigned_artifact_publish_job_id' => true", $source);
    }

    public function test_both_packaged_uploaders_send_the_claimed_publish_job_public_id(): void
    {
        $local = file_get_contents(base_path('../local-agent/lib/ArtifactUploader.php'));
        $packaged = file_get_contents(base_path('../deployment-local-agent/agent/lib/ArtifactUploader.php'));

        $this->assertSame($local, $packaged, 'ArtifactUploader mirrors must remain byte-identical.');
        $this->assertStringContainsString("'publish_job_id' => \$jobId", $packaged);
    }

    public function test_linux_self_upgrade_uses_the_versioned_packaged_runtime_and_expected_version(): void
    {
        $runner = file_get_contents(base_path('../deployment-local-agent/agent/lib/LinuxUpgradeRunner.php'));
        $upgrader = file_get_contents(base_path('../deployment-local-agent/upgrade-linux.sh'));

        $this->assertStringContainsString("\$projects['deployment-system']", $runner);
        $this->assertStringContainsString("/deployment-local-agent/upgrade-linux.sh", $runner);
        $this->assertStringContainsString("'--expected-version'", $runner);
        $this->assertStringContainsString('SOURCE_DIR="${SCRIPT_DIR}/agent"', $upgrader);
        $this->assertStringContainsString('does not match expected version', $upgrader);
    }

    public function test_hub_default_stays_overridable_via_env(): void
    {
        $configSource = file_get_contents(config_path('deployment.php'));

        $this->assertMatchesRegularExpression(
            "/'latest_local_agent_version' => env\\('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION', '[\\d.]+'\\)/",
            $configSource,
            'latest_local_agent_version must stay overridable via DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION, never hardcoded past env().',
        );
    }

    private function extractLocalAgentVersion(string $path): string
    {
        $this->assertFileExists($path, "Expected an agent.php at {$path}");
        $source = file_get_contents($path);

        $matched = preg_match("/const LOCAL_AGENT_VERSION = '([\\d.]+)';/", $source, $matches);
        $this->assertSame(1, $matched, "Could not find a LOCAL_AGENT_VERSION constant declaration in {$path}");

        return $matches[1];
    }
}
