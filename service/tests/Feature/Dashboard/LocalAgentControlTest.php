<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.3: the dashboard side of remote control (restart/fetch logs)
 * and credential rotation — same 'local_agents.manage' gate as
 * approve/disable (Phase 5), mirrors LocalAgentApprovalTest's style.
 */
class LocalAgentControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_a_restart(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.restart', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_RESTART,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
        ]);
    }

    public function test_admin_can_queue_a_fetch_logs_request(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.fetch-logs', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_FETCH_LOGS,
        ]);
    }

    public function test_admin_can_force_a_config_reload(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.reload-config', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_RELOAD_CONFIG,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
        ]);
    }

    public function test_cannot_force_a_config_reload_on_a_non_approved_agent(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $this->actingAs($admin)->post(route('local-agents.reload-config', $agent))->assertStatus(409);
    }

    public function test_developer_cannot_force_a_config_reload(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($developer)->post(route('local-agents.reload-config', $agent))->assertStatus(403);
    }

    public function test_cannot_restart_a_non_approved_agent(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $this->actingAs($admin)->post(route('local-agents.restart', $agent))->assertStatus(409);
    }

    public function test_developer_cannot_restart_or_fetch_logs(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($developer)->post(route('local-agents.restart', $agent))->assertStatus(403);
        $this->actingAs($developer)->post(route('local-agents.fetch-logs', $agent))->assertStatus(403);
    }

    public function test_admin_can_rotate_credentials(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $oldHash = $agent->agent_token_hash;

        $response = $this->actingAs($admin)->post(route('local-agents.rotate-token', $agent));

        $response->assertRedirect(route('local-agents.show', $agent));
        $response->assertSessionHas('generated_token');
        $response->assertSessionHas('generated_secret');

        $this->assertNotSame($oldHash, $agent->fresh()->agent_token_hash);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'local_agent.token_rotated',
            'subject_type' => LocalAgent::class,
            'subject_id' => $agent->id,
        ]);
    }

    public function test_show_page_renders_version_and_control_buttons(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.0.0']);

        $this->actingAs($admin)->get(route('local-agents.show', $agent))
            ->assertOk()
            ->assertSee('1.0.0');
    }

    public function test_show_page_renders_update_available_badge(): void
    {
        $admin = User::factory()->create();
        config(['deployment.latest_local_agent_version' => '9.9.9']);
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.0.0']);

        $this->actingAs($admin)->get(route('local-agents.show', $agent))
            ->assertOk()
            ->assertSee('9.9.9');
    }
}
