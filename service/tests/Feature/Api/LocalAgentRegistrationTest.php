<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

class LocalAgentRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    public function test_valid_registration_fills_in_identity_and_stays_pending(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create();
        $token = LocalAgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'dev-laptop-04', 'hostname' => 'DEV-04', 'operating_system' => 'windows', 'capabilities' => ['git' => true]];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/local-agent/register', $body, $headers)
            ->assertOk()
            ->assertJson(['status' => 'pending_approval']);

        $agent->refresh();
        $this->assertSame('dev-laptop-04', $agent->name);
        $this->assertSame('DEV-04', $agent->hostname);
        $this->assertSame('windows', $agent->operating_system);
        $this->assertSame(['git' => true], $agent->capabilities);
        $this->assertSame(LocalAgent::STATUS_PENDING_APPROVAL, $agent->status);
        // Single-use: the token is cleared immediately on success.
        $this->assertNull($agent->registration_token);
    }

    public function test_registration_token_cannot_be_replayed(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create();
        $token = LocalAgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'dev-laptop-05', 'operating_system' => 'linux'];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/local-agent/register', $body, $headers)->assertOk();
        $this->postJson('/api/local-agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_expired_registration_token_is_rejected(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create([
            'registration_token_expires_at' => now()->subMinute(),
        ]);
        $token = LocalAgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'dev-laptop-06', 'operating_system' => 'linux'];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), $agent->public_id, $token);

        $this->postJson('/api/local-agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_unknown_registration_id_is_rejected(): void
    {
        $body = ['name' => 'ghost-agent', 'operating_system' => 'linux'];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), (string) \Illuminate\Support\Str::uuid(), 'irrelevant-token');

        $this->postJson('/api/local-agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create();
        $token = LocalAgentFactory::$lastPlainRegistrationToken;

        $body = ['name' => 'dev-laptop-07', 'operating_system' => 'linux'];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), $agent->public_id, $token);
        $headers['X-Deployment-Local-Registration-Signature'] = str_repeat('0', 64);

        $this->postJson('/api/local-agent/register', $body, $headers)->assertStatus(401);
    }

    public function test_already_approved_agent_cannot_be_registered_again(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['name' => 'nope', 'operating_system' => 'linux'];
        $headers = $this->localRegistrationHeaders('POST', '/api/local-agent/register', json_encode($body), $agent->public_id, 'anything');

        $this->postJson('/api/local-agent/register', $body, $headers)->assertStatus(401);
    }
}
