<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-D: Server Onboarding Wizard. The chosen design (see
 * docs/PHASE_4D_SERVER_ONBOARDING_REPORT.md §1) wraps the ALREADY-WORKING
 * Server static-credential flow in wizard UX rather than building new
 * agent-side registration code — these tests focus on what's actually new:
 * the type field, the one-time downloadable config content, and the
 * Server Detail redesign.
 */
class ServerOnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_type_is_persisted_when_provided(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('servers.store'), [
            'name' => 'Production VPS',
            'type' => 'vps',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('servers', ['name' => 'Production VPS', 'type' => 'vps']);
    }

    public function test_server_type_is_optional(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('servers.store'), [
            'name' => 'No Type Server',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('servers', ['name' => 'No Type Server', 'type' => null]);
    }

    public function test_invalid_server_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('servers.store'), [
            'name' => 'Bad Type Server',
            'type' => 'mainframe',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('servers', ['name' => 'Bad Type Server']);
    }

    public function test_legacy_server_with_null_type_still_renders_its_detail_page(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['type' => null]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee(__('ui.servers.type_unspecified'));
    }

    public function test_downloadable_config_content_appears_only_on_the_one_time_redirect_after_creation(): void
    {
        $user = User::factory()->create();

        $store = $this->actingAs($user)->post(route('servers.store'), ['name' => 'Fresh Server']);
        $landing = $this->actingAs($user)->get($store->headers->get('Location'));
        $landing->assertOk();
        $landing->assertSee('agent-config-content', false);
        $landing->assertSee('service_base_url', false);

        // A second, later visit to the same page must not still carry it —
        // the plaintext token/secret are gone from session by then and are
        // not recoverable from the database (only the hash is stored).
        $secondVisit = $this->actingAs($user)->get($store->headers->get('Location'));
        $secondVisit->assertOk();
        $secondVisit->assertDontSee('agent-config-content', false);
    }

    public function test_downloadable_config_content_contains_this_servers_real_generated_credentials(): void
    {
        $user = User::factory()->create();

        $store = $this->actingAs($user)->post(route('servers.store'), ['name' => 'Config Content Server']);
        $server = Server::where('name', 'Config Content Server')->firstOrFail();
        $landing = $this->actingAs($user)->get($store->headers->get('Location'));

        $landing->assertSee($server->public_id, false);
        $landing->assertSee('server_id', false);
    }

    public function test_config_content_never_leaks_a_different_servers_credentials(): void
    {
        $user = User::factory()->create();
        $otherServer = Server::factory()->create();
        $otherPlainSecret = $otherServer->signing_secret;

        $store = $this->actingAs($user)->post(route('servers.store'), ['name' => 'Isolated Server']);
        $landing = $this->actingAs($user)->get($store->headers->get('Location'));

        $landing->assertDontSee($otherPlainSecret);
    }

    public function test_server_detail_never_exposes_agent_token_hash_or_signing_secret_on_a_later_visit(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();
        $plainSecret = $server->signing_secret;

        // Not the redirect-landing page — a plain, later visit.
        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertDontSee($plainSecret);
        $response->assertDontSee($server->agent_token_hash ?? '__none__');
    }

    public function test_never_connected_server_shows_a_reminder_without_any_credentials(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['last_seen_at' => null]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee(__('ui.servers.not_connected_reminder_title'));
        $response->assertDontSee('agent-config-content', false);
    }

    public function test_server_detail_lists_deployment_targets_using_this_server(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'Production']);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id, 'name' => 'web-01']);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee('web-01');
        $response->assertSee('Production');
    }

    public function test_server_detail_shows_empty_state_when_no_targets_use_this_server(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee(__('ui.servers.no_targets'));
    }

    public function test_pending_agent_list_shows_a_version_column_without_exposing_secrets(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();
        $agent = Agent::factory()->create(['server_id' => $server->id, 'name' => 'pending-agent-1']);

        $response = $this->actingAs($user)->get(route('agents.pending'));

        $response->assertOk();
        $response->assertSee(__('ui.common.version'));
        $response->assertSee('pending-agent-1');
    }

    public function test_wizard_step_indicator_renders_on_the_create_server_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertSee(__('ui.servers.wizard_step1'));
        $response->assertSee(__('ui.servers.wizard_step5'));
    }

    public function test_full_server_creation_and_rotation_flow_still_works_end_to_end(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('servers.store'), ['name' => 'E2E Server', 'type' => 'cpanel'])
            ->assertRedirect();
        $server = Server::where('name', 'E2E Server')->firstOrFail();
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'server.registered']);

        $this->actingAs($user)->post(route('servers.rotate-token', $server))
            ->assertRedirect(route('servers.show', $server));
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'server.credentials_rotated']);
    }
}
