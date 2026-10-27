<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regression guard for the Server Agent's silently-empty runtime snapshot.
 *
 * agent/agent.php's sendHeartbeat() called collectServerRuntimeSnapshot($config ?? []),
 * but $config is a file-scope variable and is never visible inside a PHP
 * function — the `??` fallback hid the undefined variable, so every
 * heartbeat was sent without a runtime_snapshot and Sentinel never received
 * server-side runtime evidence. agent.php runs its poll loop the moment it
 * is included, so this is checked against its source.
 */
class ServerAgentHeartbeatSnapshotWiringTest extends TestCase
{
    public function test_heartbeat_receives_the_agent_config_explicitly(): void
    {
        $source = (string) file_get_contents(base_path('../agent/agent.php'));

        $this->assertMatchesRegularExpression(
            '/function sendHeartbeat\(Client \$client, Logger \$logger, array \$config\): void/',
            $source,
            'sendHeartbeat() must take the agent config as a parameter; it cannot see the file-scope $config.',
        );
        $this->assertStringContainsString(
            'sendHeartbeat($client, $logger, $config);',
            $source,
            'pollAndExecute() must pass its $config through to sendHeartbeat().',
        );
    }

    public function test_heartbeat_does_not_mask_a_missing_config_with_a_null_coalescing_fallback(): void
    {
        $source = (string) file_get_contents(base_path('../agent/agent.php'));

        $this->assertStringNotContainsString('collectServerRuntimeSnapshot($config ?? [])', $source);
        $this->assertStringContainsString('collectServerRuntimeSnapshot($config)', $source);
    }

    /**
     * AgentJobController::heartbeat() aborts with 409 for any parseable
     * snapshot it cannot store (incomplete or stale) before it verifies an
     * awaiting self-upgrade, so an incomplete snapshot must never be sent.
     */
    public function test_only_a_complete_snapshot_is_attached_to_the_heartbeat(): void
    {
        $source = (string) file_get_contents(base_path('../agent/agent.php'));

        $this->assertStringContainsString(
            "if ((\$runtimeSnapshot['snapshot_status'] ?? null) === 'complete') {",
            $source,
        );
    }
}
