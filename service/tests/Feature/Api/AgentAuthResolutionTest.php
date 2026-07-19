<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Database\Factories\AgentFactory;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Covers docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md §3.2's dual-lookup rule.
 * Every pre-existing test in AgentJobFlowTest/AgentHeartbeatTest already
 * exercises a Server with no Agent row at all — i.e. every one of them is
 * already an implicit regression test proving the "Old Agent" fallback
 * path is untouched. This file adds the cases specific to the new
 * resolution order itself.
 */
class AgentAuthResolutionTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_approved_agent_authenticates_via_the_agent_path(): void
    {
        $agent = Agent::factory()->approved()->create();
        $token = AgentFactory::$lastPlainToken;
        $secret = AgentFactory::$lastPlainSecret;

        $project = Project::factory()->create(['server_id' => $agent->server_id]);
        Deployment::create([
            'project_id' => $project->id,
            'server_id' => $agent->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, $token, $secret);

        $this->get('/api/agent/jobs/next', $headers)
            ->assertOk()
            ->assertJsonPath('job.project_key', $project->project_key);

        $this->assertNotNull($agent->fresh()->last_seen_at);
    }

    public function test_pending_agent_is_rejected_outright_never_falls_back_to_server(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create();

        // A pending agent has no agent_token_hash/signing_secret yet, so
        // any bearer/signature it could present is necessarily fabricated —
        // the point being verified here is that the request is rejected at
        // the "found an Agent row but it's not approved" check, not that it
        // silently falls through to a Server lookup.
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, 'fabricated-token', 'fabricated-secret');

        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }

    public function test_disabled_agent_is_rejected_even_if_a_server_shares_its_public_id(): void
    {
        // Simulates a future "promoted" agent: the Agent row's public_id
        // intentionally equals an existing Server's public_id (Phase 3-A
        // §5B's reuse strategy). Disabling the Agent must not be
        // bypassable by falling through to that Server's own credentials.
        $server = Server::factory()->create();
        $serverToken = ServerFactory::$lastPlainToken;
        $serverSecret = ServerFactory::$lastPlainSecret;

        $agent = Agent::factory()->disabled()->create(['public_id' => $server->public_id]);

        // Attempt with the AGENT's own (disabled) credentials.
        $agentHeaders = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $this->get('/api/agent/jobs/next', $agentHeaders)->assertStatus(401);

        // Attempt with the SERVER's still-perfectly-valid legacy credentials,
        // same public_id — must also be rejected, not silently accepted via
        // a fallback to Server auth.
        $serverHeaders = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $serverToken, $serverSecret);
        $this->get('/api/agent/jobs/next', $serverHeaders)->assertStatus(401);
    }

    public function test_legacy_server_with_no_agent_row_still_authenticates_exactly_as_before(): void
    {
        $server = Server::factory()->create();
        $token = ServerFactory::$lastPlainToken;
        $secret = ServerFactory::$lastPlainSecret;

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);

        $this->get('/api/agent/jobs/next', $headers)->assertOk()->assertJson(['job' => null]);
    }

    public function test_replay_protection_applies_identically_on_the_agent_path(): void
    {
        $agent = Agent::factory()->approved()->create();

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret, $timestamp, $nonce);

        $this->get('/api/agent/jobs/next', $headers)->assertOk();
        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }
}
