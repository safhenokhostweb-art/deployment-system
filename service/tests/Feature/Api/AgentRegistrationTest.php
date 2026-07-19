<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

class AgentRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_valid_registration_fills_in_identity_and_stays_pending(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create();
        $token = AgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'web-04-agent', 'os' => 'linux', 'architecture' => 'x86_64', 'capabilities' => ['docker' => true]];
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/agent/register', $body, $headers)
            ->assertOk()
            ->assertJson(['status' => 'pending_approval']);

        $agent->refresh();
        $this->assertSame('web-04-agent', $agent->name);
        $this->assertSame('linux', $agent->os);
        $this->assertSame('x86_64', $agent->architecture);
        $this->assertSame(['docker' => true], $agent->capabilities);
        $this->assertSame(Agent::STATUS_PENDING_APPROVAL, $agent->status);
        $this->assertNotNull($agent->ip_address);
        // Single-use: the token is cleared immediately on success.
        $this->assertNull($agent->registration_token);
    }

    public function test_registration_token_cannot_be_replayed(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create();
        $token = AgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'web-05-agent', 'os' => 'linux', 'architecture' => 'x86_64'];
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/agent/register', $body, $headers)->assertOk();
        // Second attempt: same request, token already cleared server-side.
        $this->postJson('/api/agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_expired_registration_token_is_rejected(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create([
            'registration_token_expires_at' => now()->subMinute(),
        ]);
        $token = AgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'web-06-agent', 'os' => 'linux', 'architecture' => 'x86_64'];
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_unknown_registration_id_is_rejected(): void
    {
        $body = ['name' => 'ghost-agent', 'os' => 'linux', 'architecture' => 'x86_64'];
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), (string) \Illuminate\Support\Str::uuid(), 'irrelevant-token');

        $this->postJson('/api/agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create();
        $token = AgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'web-07-agent', 'os' => 'linux', 'architecture' => 'x86_64'];
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), $agent->public_id, $token);
        $headers['X-Deployment-Registration-Signature'] = str_repeat('0', 64);

        $this->postJson('/api/agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_already_approved_agent_cannot_be_registered_again(): void
    {
        $agent = Agent::factory()->approved()->create();

        $body = ['name' => 'nope', 'os' => 'linux', 'architecture' => 'x86_64'];
        // Even with a fabricated signature, the lookup itself requires
        // status = pending_approval, so an approved agent's public_id
        // never matches here regardless of what token is presented.
        $headers = $this->registrationHeaders('POST', '/api/agent/register', json_encode($body), $agent->public_id, 'anything');

        $this->postJson('/api/agent/register', $body, $headers)->assertStatus(401);
    }
}
