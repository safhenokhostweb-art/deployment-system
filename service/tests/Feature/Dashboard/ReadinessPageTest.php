<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-L Task 4. The Readiness page is purely read-only and derives
 * everything from existing models — these tests exercise the five status
 * computations in ReadinessController, not any new architecture.
 */
class ReadinessPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_see_the_readiness_page(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->get(route('readiness.index'))->assertOk();
    }

    public function test_github_section_reflects_connected_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('readiness.index'))
            ->assertOk()
            ->assertSee(__('status.not_connected'));

        GitHubAccount::factory()->create(['status' => GitHubAccount::STATUS_CONNECTED]);

        $this->actingAs($admin)->get(route('readiness.index'))
            ->assertOk()
            ->assertSee(__('status.connected'));
    }

    public function test_github_section_ignores_a_disconnected_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        GitHubAccount::factory()->create(['status' => GitHubAccount::STATUS_DISCONNECTED]);

        $this->actingAs($admin)->get(route('readiness.index'))
            ->assertOk()
            ->assertSee(__('status.not_connected'));
    }

    public function test_server_row_shows_online_status_and_agent_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $onlineServer = Server::factory()->create(['name' => 'online-server', 'last_seen_at' => now()]);
        Agent::factory()->approved()->create(['server_id' => $onlineServer->id]);

        $offlineServer = Server::factory()->create(['name' => 'offline-server']);
        Agent::factory()->create(['server_id' => $offlineServer->id]);

        $noAgentServer = Server::factory()->create(['name' => 'no-agent-server']);

        $response = $this->actingAs($admin)->get(route('readiness.index'))->assertOk();

        $response->assertSeeText('online-server');
        $response->assertSeeText('offline-server');
        $response->assertSeeText('no-agent-server');
        $response->assertSee(__('status.approved'));
        $response->assertSee(__('status.pending_approval'));
        $response->assertSee(__('status.no_agent'));
    }

    public function test_project_row_shows_configured_and_deployment_ready_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $readyServer = Server::factory()->create(['last_seen_at' => now()]);
        $readyProject = Project::factory()->create(['name' => 'ready-project', 'server_id' => $readyServer->id]);

        $offlineServer = Server::factory()->create();
        $notDeployableProject = Project::factory()->create(['name' => 'not-deployable-project', 'server_id' => $offlineServer->id]);

        $unconfiguredProject = Project::factory()->create([
            'name' => 'unconfigured-project',
            'server_id' => $offlineServer->id,
            'pipeline' => [],
        ]);

        $response = $this->actingAs($admin)->get(route('readiness.index'))->assertOk();

        $response->assertSeeText('ready-project');
        $response->assertSeeText('not-deployable-project');
        $response->assertSeeText('unconfigured-project');
        $response->assertSee(__('status.configured'));
        $response->assertSee(__('status.not_configured'));
        $response->assertSee(__('status.ready'));
        $response->assertSee(__('status.not_ready'));
    }

    public function test_empty_states_render_when_nothing_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('readiness.index'))
            ->assertOk()
            ->assertSee(__('ui.readiness.no_servers'))
            ->assertSee(__('ui.readiness.no_projects'));
    }
}
