<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\SentinelRuntimeEvidenceContract;
use PHPUnit\Framework\TestCase;

class RuntimeIntegrationRegressionTest extends TestCase
{
    public function test_existing_local_release_is_reported_as_relative_by_server_collector(): void
    {
        $root = dirname(__DIR__, 5);
        require_once $root . '/agent/lib/ReleaseManager.php';
        require_once $root . '/agent/lib/ServerAgentRuntimeSnapshotCollector.php';
        $collector = new \DeploymentAgent\ServerAgentRuntimeSnapshotCollector(new \DeploymentAgent\ReleaseManager());
        $snapshot = $collector->collect(['working_directory' => $root . '/test-release'], 'dep-0001', 'project', 'local', 'rel-0001', 'agent-0001', '1.0');
        self::assertSame('releases/dep-0001', $snapshot['current_release_path']);
        self::assertIsInt($snapshot['sequence']);
        self::assertSame('releases/dep-0001', SentinelRuntimeEvidenceContract::fromArray($snapshot)->get('current_release_path'));
    }

    public function test_local_collector_resolves_existing_windows_release_without_changing_it(): void
    {
        $root = dirname(__DIR__, 5);
        require_once $root . '/local-agent/lib/RuntimeSnapshotCollector.php';
        $collector = new \LocalAgent\RuntimeSnapshotCollector($root . '/test-release', 'dep-0001', 'project', 'local', 'rel-0001', 'agent-0001', '1.0');
        $method = new \ReflectionMethod($collector, 'readCurrentReleasePath');
        self::assertSame(realpath($root . '/test-release/releases/dep-0001'), $method->invoke($collector));
        $wrongDeployment = new \LocalAgent\RuntimeSnapshotCollector($root . '/test-release', 'dep-other', 'project', 'local', 'rel-0001', 'agent-0001', '1.0');
        self::assertNull($method->invoke($wrongDeployment));
    }

    public function test_both_controller_validation_rules_preserve_optional_snapshot(): void
    {
        $root = dirname(__DIR__, 4);
        foreach (['LocalAgentJobController', 'AgentJobController'] as $controller) {
            $source = file_get_contents($root . '/app/Http/Controllers/Api/' . $controller . '.php');
            self::assertStringContainsString("'runtime_snapshot' => 'nullable|array'", $source);
            self::assertStringContainsString("\$runtimeSnapshot = \$validated['runtime_snapshot'] ?? null", $source);
            self::assertStringContainsString('SentinelRuntimeEvidenceContract::fromArray($runtimeSnapshot)', $source);
        }
    }

    public function test_contract_rejects_absolute_and_traversal_paths_without_echoing_payload(): void
    {
        foreach (['C:\\dep-0001', 'C:/dep-0001', 'C:dep-0001', 'C:', '\\\\server\\share\\release', '//server/share/release', '/var/www/release', '\\release', '../release', 'releases/../../secret'] as $path) {
            try {
                SentinelRuntimeEvidenceContract::fromArray(['current_release_path' => $path]);
                self::fail('Unsafe path accepted');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringNotContainsString($path, $exception->getMessage());
            }
        }
    }

    public function test_mirrors_remain_identical(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertFileEquals($root . '/local-agent/lib/RuntimeSnapshotCollector.php', $root . '/deployment-local-agent/agent/lib/RuntimeSnapshotCollector.php');
    }

    public function test_contract_accepts_safe_relative_paths(): void
    {
        foreach (['releases/dep-0001', 'workspace/project-name'] as $path) {
            self::assertSame($path, SentinelRuntimeEvidenceContract::fromArray(['current_release_path' => $path])->get('current_release_path'));
        }
    }

    public function test_signers_agree_for_snapshot_payload(): void
    {
        $root = dirname(__DIR__, 5);
        require_once $root . '/local-agent/lib/Security.php';
        require_once $root . '/agent/lib/Signer.php';
        $body = json_encode(['runtime_snapshot' => ['current_release_path' => 'releases/dep-0001']], JSON_THROW_ON_ERROR);
        $key = 'unit-test-signing-key';
        $signature = \LocalAgent\Security::sign('POST', '/heartbeat', '1', 'test-nonce', $body, $key);
        self::assertTrue(\DeploymentAgent\Signer::verify('POST', '/heartbeat', '1', 'test-nonce', $body, $key, $signature));
        self::assertTrue((new \App\Services\Security\RequestSigner())->verify('POST', '/heartbeat', '1', 'test-nonce', $body, $key, $signature));
    }
}
