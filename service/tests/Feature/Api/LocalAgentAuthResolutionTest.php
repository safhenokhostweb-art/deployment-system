<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 5: covers VerifyLocalAgentRequestSignature — a deliberately
 * simpler, fully independent counterpart to AgentAuthResolutionTest
 * (there is no legacy fallback here, since a Local Agent has no earlier
 * credential shape to stay compatible with).
 */
class LocalAgentAuthResolutionTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    public function test_approved_local_agent_authenticates(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $token = LocalAgentFactory::$lastPlainToken;
        $secret = LocalAgentFactory::$lastPlainSecret;

        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', $agent->public_id, $token, $secret);

        $this->get('/api/local-agent/publish-jobs/next', $headers)
            ->assertOk()
            ->assertJson(['job' => null]);

        $this->assertNotNull($agent->fresh()->last_seen_at);
    }

    public function test_pending_local_agent_is_rejected(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', $agent->public_id, 'fabricated-token', 'fabricated-secret');

        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertStatus(401);
    }

    public function test_disabled_local_agent_is_rejected(): void
    {
        $agent = LocalAgent::factory()->disabled()->create();

        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertStatus(401);
    }

    public function test_unknown_local_agent_is_rejected(): void
    {
        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', (string) \Illuminate\Support\Str::uuid(), 'token', 'secret');

        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertStatus(401);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $headers['X-Deployment-Signature'] = str_repeat('0', 64);

        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertStatus(401);
    }

    public function test_replay_protection_rejects_a_repeated_nonce(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $headers = $this->localAgentHeaders('GET', '/api/local-agent/publish-jobs/next', '', $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret, $timestamp, $nonce);

        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk();
        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertStatus(401);
    }
}
