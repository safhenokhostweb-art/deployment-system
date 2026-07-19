<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\Server;
use Database\Factories\AgentFactory;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

class AgentHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_heartbeat_records_agent_version(): void
    {
        $server = Server::factory()->create();
        $token = ServerFactory::$lastPlainToken;
        $secret = ServerFactory::$lastPlainSecret;

        $body = json_encode(['agent_version' => '1.1.0']);
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', $body, $server->public_id, $token, $secret);

        $this->postJson('/api/agent/heartbeat', ['agent_version' => '1.1.0'], $headers)->assertOk();

        $this->assertSame('1.1.0', $server->fresh()->agent_version);
    }

    public function test_legacy_server_heartbeat_ignores_unexpected_fields(): void
    {
        // "Old Agent" behavior must stay exactly as it was — sending only
        // agent_version, never touching os/architecture/capabilities on
        // Server, which don't even have columns for most of them.
        $server = Server::factory()->create();
        $token = ServerFactory::$lastPlainToken;
        $secret = ServerFactory::$lastPlainSecret;

        $body = ['agent_version' => '1.1.0', 'os' => 'linux', 'capabilities' => ['docker' => true]];
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $server->public_id, $token, $secret);

        $this->postJson('/api/agent/heartbeat', $body, $headers)->assertOk();

        $this->assertSame('1.1.0', $server->fresh()->agent_version);
    }

    public function test_new_agent_heartbeat_records_full_metadata(): void
    {
        $agent = Agent::factory()->approved()->create();
        $token = AgentFactory::$lastPlainToken;
        $secret = AgentFactory::$lastPlainSecret;

        $body = [
            'agent_version' => '1.2.0',
            'os' => 'linux',
            'architecture' => 'arm64',
            'capabilities' => ['php' => '8.4', 'docker' => true, 'extensions' => ['pdo', 'redis']],
        ];
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, $token, $secret);

        $this->postJson('/api/agent/heartbeat', $body, $headers)->assertOk();

        $fresh = $agent->fresh();
        $this->assertSame('1.2.0', $fresh->agent_version);
        $this->assertSame('linux', $fresh->os);
        $this->assertSame('arm64', $fresh->architecture);
        $this->assertSame(['php' => '8.4', 'docker' => true, 'extensions' => ['pdo', 'redis']], $fresh->capabilities);
        $this->assertNotNull($fresh->ip_address);
        $this->assertNotNull($fresh->last_seen_at);
    }

    public function test_agent_is_online_only_within_the_configured_threshold(): void
    {
        config(['deployment.server_offline_threshold_seconds' => 90]);

        $onlineAgent = Agent::factory()->approved()->create(['last_seen_at' => now()->subSeconds(30)]);
        $offlineAgent = Agent::factory()->approved()->create(['last_seen_at' => now()->subMinutes(10)]);

        $this->assertTrue($onlineAgent->isOnline());
        $this->assertFalse($offlineAgent->isOnline());
    }

    public function test_server_is_online_only_within_the_configured_threshold(): void
    {
        config(['deployment.server_offline_threshold_seconds' => 90]);

        $onlineServer = Server::factory()->create(['last_seen_at' => now()->subSeconds(30)]);
        $offlineServer = Server::factory()->create(['last_seen_at' => now()->subMinutes(10)]);
        $neverSeenServer = Server::factory()->create(['last_seen_at' => null]);

        $this->assertTrue($onlineServer->isOnline());
        $this->assertFalse($offlineServer->isOnline());
        $this->assertFalse($neverSeenServer->isOnline());
    }

    public function test_heartbeat_requires_a_valid_signature(): void
    {
        $server = Server::factory()->create();
        $token = ServerFactory::$lastPlainToken;
        $secret = ServerFactory::$lastPlainSecret;

        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode(['agent_version' => '1.0.0']), $server->public_id, $token, $secret);
        $headers['X-Deployment-Signature'] = str_repeat('0', 64);

        $this->postJson('/api/agent/heartbeat', ['agent_version' => '1.0.0'], $headers)->assertStatus(401);
    }
}
