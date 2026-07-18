<?php

namespace Tests\Feature\Console;

use App\Models\AgentRequestNonce;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneAgentNoncesTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_nonces_older_than_the_retention_window(): void
    {
        config(['deployment.nonce_retention_hours' => 2]);
        $server = Server::factory()->create();

        $old = AgentRequestNonce::create(['server_id' => $server->id, 'nonce' => 'old-nonce']);
        $old->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

        $recent = AgentRequestNonce::create(['server_id' => $server->id, 'nonce' => 'recent-nonce']);

        $this->artisan('agent-nonces:prune')->assertSuccessful();

        $this->assertDatabaseMissing('agent_request_nonces', ['id' => $old->id]);
        $this->assertDatabaseHas('agent_request_nonces', ['id' => $recent->id]);
    }
}
