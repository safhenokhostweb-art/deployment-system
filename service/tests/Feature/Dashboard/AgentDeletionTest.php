<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Delete an agent" — agents.server_id restrictOnDelete()'s a Server at
 * the schema level, so a leftover disabled Agent row was another way a
 * Server could get stuck un-deletable. Only a disabled agent (already
 * stopped authenticating) can be hard-deleted, same "stop it, then
 * remove it" shape as disable()/reenable(); a pending agent is already
 * removable via reject().
 */
class AgentDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_disabled_agent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->disabled()->create();

        $response = $this->actingAs($admin)->delete(route('agents.destroy', $agent));

        $response->assertRedirect(route('servers.show', $agent->server_id));
        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'agent.deleted']);
    }

    public function test_an_approved_agent_cannot_be_deleted_directly(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();

        $this->actingAs($admin)->delete(route('agents.destroy', $agent))
            ->assertStatus(409);

        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
    }

    public function test_a_disabled_agent_with_job_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->disabled()->create();
        AgentJob::factory()->create(['agent_id' => $agent->id]);

        $this->actingAs($admin)->delete(route('agents.destroy', $agent))
            ->assertStatus(409);

        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
    }

    public function test_non_admin_cannot_delete_an_agent(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $agent = Agent::factory()->disabled()->create();

        $this->actingAs($operator)->delete(route('agents.destroy', $agent))
            ->assertForbidden();

        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
    }

    public function test_delete_button_is_visible_for_a_disabled_agent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->disabled()->create();

        $response = $this->actingAs($admin)->get(route('agents.show', $agent));

        $response->assertOk();
        $response->assertSee(__('ui.agents.delete_button'));
    }
}
