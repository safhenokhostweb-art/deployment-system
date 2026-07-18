<?php

namespace Tests\Feature\Api;

use App\Models\Server;
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
