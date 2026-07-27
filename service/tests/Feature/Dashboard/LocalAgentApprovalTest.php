<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAgentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_registration_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('local-agents.create-registration'));

        $agent = LocalAgent::first();
        $response->assertRedirect(route('local-agents.show', $agent));
        $this->assertSame(LocalAgent::STATUS_PENDING_APPROVAL, $agent->status);
        $this->assertNotNull($agent->registration_token);
        $this->assertNull($agent->name); // not filled in until the agent's own /register call
    }

    public function test_admin_can_approve_a_registered_pending_local_agent(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create(['name' => 'dev-laptop-09', 'operating_system' => 'windows']);

        $response = $this->actingAs($user)->post(route('local-agents.approve', $agent));

        $response->assertRedirect(route('local-agents.show', $agent));
        $fresh = $agent->fresh();
        $this->assertSame(LocalAgent::STATUS_APPROVED, $fresh->status);
        $this->assertSame($user->email, $fresh->approved_by);
        $this->assertNotNull($fresh->agent_token_hash);
        $this->assertNotNull($fresh->signing_secret);
    }

    public function test_cannot_approve_a_local_agent_that_has_not_completed_registration(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $this->actingAs($user)->post(route('local-agents.approve', $agent))->assertStatus(409);

        $this->assertSame(LocalAgent::STATUS_PENDING_APPROVAL, $agent->fresh()->status);
    }

    public function test_admin_can_reject_a_pending_local_agent(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create(['name' => 'dev-laptop-10']);

        $this->actingAs($user)->post(route('local-agents.reject', $agent))->assertRedirect(route('local-agents.pending'));

        $this->assertDatabaseMissing('local_agents', ['id' => $agent->id]);
    }

    public function test_disabling_an_approved_local_agent_changes_its_status(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($user)->post(route('local-agents.disable', $agent))->assertRedirect(route('local-agents.show', $agent));

        $this->assertSame(LocalAgent::STATUS_DISABLED, $agent->fresh()->status);
    }

    public function test_guest_cannot_approve_local_agents(): void
    {
        $agent = LocalAgent::factory()->pendingRegistration()->create(['name' => 'dev-laptop-11']);

        $this->post(route('local-agents.approve', $agent))->assertRedirect(route('login'));

        $this->assertSame(LocalAgent::STATUS_PENDING_APPROVAL, $agent->fresh()->status);
    }

    public function test_developer_can_view_but_not_manage_local_agents(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->pendingRegistration()->create(['name' => 'dev-laptop-12']);

        $this->actingAs($developer)->get(route('local-agents.pending'))->assertOk();
        $this->actingAs($developer)->post(route('local-agents.approve', $agent))->assertStatus(403);
    }

    public function test_index_and_show_pages_render(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($user)->get(route('local-agents.index'))->assertOk()->assertSee($agent->name);
        $this->actingAs($user)->get(route('local-agents.show', $agent))->assertOk()->assertSee($agent->public_id);
    }
}
