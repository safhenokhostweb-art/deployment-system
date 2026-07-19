<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_registration_token_for_a_server(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->post(route('agents.create-registration', $server));

        $agent = Agent::first();
        $response->assertRedirect(route('agents.show', $agent));
        $this->assertSame(Agent::STATUS_PENDING_APPROVAL, $agent->status);
        $this->assertSame($server->id, $agent->server_id);
        $this->assertNotNull($agent->registration_token);
        $this->assertNull($agent->name); // not filled in until the agent's own /register call
    }

    public function test_admin_can_approve_a_registered_pending_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->pendingRegistration()->create(['name' => 'web-09-agent', 'os' => 'linux', 'architecture' => 'x86_64']);

        $response = $this->actingAs($user)->post(route('agents.approve', $agent));

        $response->assertRedirect(route('agents.show', $agent));
        $fresh = $agent->fresh();
        $this->assertSame(Agent::STATUS_APPROVED, $fresh->status);
        $this->assertSame($user->email, $fresh->approved_by);
        $this->assertNotNull($fresh->agent_token_hash);
        $this->assertNotNull($fresh->signing_secret);
    }

    public function test_cannot_approve_an_agent_that_has_not_completed_registration(): void
    {
        $user = User::factory()->create();
        // pendingRegistration() leaves name/os/architecture null, exactly
        // like an admin-issued token nobody has used yet.
        $agent = Agent::factory()->pendingRegistration()->create();

        $this->actingAs($user)->post(route('agents.approve', $agent))->assertStatus(409);

        $this->assertSame(Agent::STATUS_PENDING_APPROVAL, $agent->fresh()->status);
    }

    public function test_admin_can_reject_a_pending_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->pendingRegistration()->create(['name' => 'web-10-agent']);

        $this->actingAs($user)->post(route('agents.reject', $agent))->assertRedirect(route('agents.pending'));

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }

    public function test_disabling_an_approved_agent_changes_its_status(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->approved()->create();

        $this->actingAs($user)->post(route('agents.disable', $agent))->assertRedirect(route('agents.show', $agent));

        $this->assertSame(Agent::STATUS_DISABLED, $agent->fresh()->status);
    }

    public function test_guest_cannot_approve_agents(): void
    {
        $agent = Agent::factory()->pendingRegistration()->create(['name' => 'web-11-agent']);

        $this->post(route('agents.approve', $agent))->assertRedirect(route('login'));

        $this->assertSame(Agent::STATUS_PENDING_APPROVAL, $agent->fresh()->status);
    }
}
