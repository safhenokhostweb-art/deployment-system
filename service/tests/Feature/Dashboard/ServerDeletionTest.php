<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Delete a server I registered" — Server had no destroy() action at all.
 * projects/deployments/agents/deployment_targets all ->restrictOnDelete()
 * a server at the schema level already; these tests confirm that's
 * surfaced as a friendly 409 rather than a raw QueryException, and that
 * a server with nothing attached can actually be removed.
 */
class ServerDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_server_with_no_dependents(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create(['name' => 'Deletable Server']);

        $response = $this->actingAs($admin)->delete(route('servers.destroy', $server));

        $response->assertRedirect(route('servers.index'));
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'server.deleted']);
    }

    public function test_non_admin_cannot_delete_a_server(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $server = Server::factory()->create();

        $this->actingAs($operator)->delete(route('servers.destroy', $server))
            ->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_server_with_a_project_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        Project::factory()->create(['server_id' => $server->id]);

        $this->actingAs($admin)->delete(route('servers.destroy', $server))
            ->assertStatus(409);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_server_with_an_agent_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        Agent::factory()->create(['server_id' => $server->id]);

        $this->actingAs($admin)->delete(route('servers.destroy', $server))
            ->assertStatus(409);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_server_with_a_deployment_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        Deployment::factory()->create(['server_id' => $server->id]);

        $this->actingAs($admin)->delete(route('servers.destroy', $server))
            ->assertStatus(409);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_server_with_a_deployment_target_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id]);

        $this->actingAs($admin)->delete(route('servers.destroy', $server))
            ->assertStatus(409);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_delete_button_is_visible_to_admin_on_the_server_detail_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee(__('ui.servers.delete_button'));
    }

    public function test_delete_button_is_hidden_from_a_viewer_on_the_server_detail_page(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $server = Server::factory()->create();

        $response = $this->actingAs($viewer)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertDontSee(__('ui.servers.delete_button'));
    }
}
