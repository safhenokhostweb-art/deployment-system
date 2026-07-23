<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\DiscoveryJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-H Task 11: Dashboard-side wizard integration — triggering a
 * scan (gated the same as project creation) and the Project Wizard's
 * optional pre-fill from a Discovery result, without changing anything
 * about the legacy fully-manual creation path.
 */
class LocalDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_can_queue_a_discovery_scan_on_a_server_with_an_approved_agent(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($admin)
            ->post(route('discovery.scans.store', $server), ['workspace_path' => '/home/example/www'])
            ->assertRedirect(route('servers.show', $server));

        $this->assertDatabaseHas('discovery_jobs', [
            'workspace_path' => '/home/example/www',
            'status' => DiscoveryJob::STATUS_QUEUED,
            'requested_by' => $admin->email,
        ]);
    }

    public function test_scan_is_rejected_when_the_server_has_no_approved_agent(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)
            ->post(route('discovery.scans.store', $server), ['workspace_path' => '/home/example/www'])
            ->assertStatus(422);

        $this->assertDatabaseCount('discovery_jobs', 0);
    }

    public function test_non_admin_cannot_queue_a_discovery_scan(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($operator)
            ->post(route('discovery.scans.store', $server), ['workspace_path' => '/home/example/www'])
            ->assertForbidden();

        $this->assertDatabaseCount('discovery_jobs', 0);
    }

    public function test_server_show_page_lists_a_succeeded_scans_detected_projects_with_a_use_this_project_link(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id]);

        DiscoveryJob::create([
            'agent_id' => $agent->id,
            'workspace_path' => '/home/example/www',
            'status' => DiscoveryJob::STATUS_SUCCEEDED,
            'requested_by' => $admin->email,
            'result' => [[
                'name' => 'saas-platform',
                'path' => '/home/example/www/saas-platform',
                'type' => 'laravel',
                'framework_version' => '^11.0',
                'git' => ['repository' => 'acme/saas-platform', 'branch' => 'main'],
            ]],
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee('saas-platform');
        $response->assertSee('acme/saas-platform');
        // Final Phase: the link also carries discovered_path and
        // discovered_framework_version now (discovered_commit is absent
        // here since this fixture's git array has no commit_hash — route()
        // omits a null query parameter entirely, same as before).
        $response->assertSee(route('projects.create', [
            'discovered_name' => 'saas-platform',
            'discovered_path' => '/home/example/www/saas-platform',
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'main',
            'discovered_framework_version' => '^11.0',
            'discovered_type' => 'laravel',
        ]));
    }

    public function test_project_wizard_prefills_name_repository_and_branch_from_query_parameters(): void
    {
        $admin = $this->admin();
        Server::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.create', [
            'discovered_name' => 'saas-platform',
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'develop',
            'discovered_type' => 'laravel',
        ]));

        $response->assertOk();
        $response->assertSee('value="saas-platform"', false);
        $response->assertSee('value="acme/saas-platform"', false);
        $response->assertSee('value="develop"', false);
    }

    public function test_project_wizard_with_no_discovery_query_parameters_behaves_exactly_as_before(): void
    {
        // Compatibility: a plain GET (every pre-4-H caller, including every
        // existing test) must render the same empty form it always has.
        $admin = $this->admin();
        Server::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.create'));

        $response->assertOk();
        $response->assertSee('name="name"', false);
        $response->assertDontSee('value="saas-platform"', false);
    }
}
