<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\DeploymentTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-C.5: Environment & DeploymentTarget management UI.
 */
class EnvironmentTargetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_an_environment_for_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->post(route('environments.store', $project), [
            'name' => 'Production',
            'key' => 'production',
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('environments', [
            'project_id' => $project->id,
            'name' => 'Production',
            'key' => 'production',
            'status' => Environment::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'environment.created']);
    }

    public function test_environment_key_must_be_unique_within_the_same_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id, 'key' => 'production']);

        $response = $this->actingAs($user)->post(route('environments.store', $project), [
            'name' => 'Production Again',
            'key' => 'production',
        ]);

        $response->assertSessionHasErrors('key');
        $this->assertSame(1, Environment::where('project_id', $project->id)->count());
    }

    public function test_the_same_key_is_allowed_across_different_projects(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        Environment::factory()->create(['project_id' => $projectA->id, 'key' => 'production']);

        $response = $this->actingAs($user)->post(route('environments.store', $projectB), [
            'name' => 'Production',
            'key' => 'production',
        ]);

        $response->assertRedirect(route('projects.show', $projectB));
        $this->assertDatabaseHas('environments', ['project_id' => $projectB->id, 'key' => 'production']);
    }

    public function test_user_can_toggle_an_environment_disabled_and_back_to_active(): void
    {
        $user = User::factory()->create();
        $environment = Environment::factory()->create(['status' => Environment::STATUS_ACTIVE]);

        $this->actingAs($user)->post(route('environments.toggle-status', $environment))
            ->assertRedirect(route('projects.show', $environment->project));
        $this->assertSame(Environment::STATUS_DISABLED, $environment->fresh()->status);

        $this->actingAs($user)->post(route('environments.toggle-status', $environment));
        $this->assertSame(Environment::STATUS_ACTIVE, $environment->fresh()->status);
    }

    public function test_user_can_attach_servers_as_deployment_targets_in_one_request(): void
    {
        $user = User::factory()->create();
        $environment = Environment::factory()->create();
        $serverA = Server::factory()->create(['name' => 'web-a']);
        $serverB = Server::factory()->create(['name' => 'web-b']);

        $response = $this->actingAs($user)->post(route('deployment-targets.store', $environment), [
            'server_ids' => [$serverA->id, $serverB->id],
        ]);

        $response->assertRedirect(route('projects.show', $environment->project));
        $this->assertDatabaseHas('deployment_targets', ['environment_id' => $environment->id, 'server_id' => $serverA->id, 'name' => 'web-a']);
        $this->assertDatabaseHas('deployment_targets', ['environment_id' => $environment->id, 'server_id' => $serverB->id, 'name' => 'web-b']);
    }

    public function test_duplicate_target_names_within_an_environment_are_disambiguated(): void
    {
        $user = User::factory()->create();
        $environment = Environment::factory()->create();
        $server = Server::factory()->create(['name' => 'shared-name']);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'shared-name']);

        $anotherServer = Server::factory()->create(['name' => 'shared-name']);
        $this->actingAs($user)->post(route('deployment-targets.store', $environment), [
            'server_ids' => [$anotherServer->id],
        ]);

        $this->assertDatabaseHas('deployment_targets', ['environment_id' => $environment->id, 'name' => 'shared-name (2)']);
    }

    public function test_user_can_disable_a_deployment_target(): void
    {
        $user = User::factory()->create();
        $target = DeploymentTarget::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post(route('deployment-targets.toggle-status', $target))
            ->assertRedirect(route('projects.show', $target->environment->project));

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_a_disabled_target_is_excluded_from_deployment_resolution(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'active-one', 'is_active' => true]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'disabled-one', 'is_active' => false]);

        $targets = app(DeploymentTargetResolver::class)->resolveTargetsFor($project);

        $this->assertCount(1, $targets);
        $this->assertSame('active-one', $targets[0]->targetKey);
    }

    public function test_deleting_a_target_with_no_deployment_history_succeeds(): void
    {
        $user = User::factory()->create();
        $target = DeploymentTarget::factory()->create();

        $this->actingAs($user)->delete(route('deployment-targets.destroy', $target))
            ->assertRedirect(route('projects.show', $target->environment->project));

        $this->assertDatabaseMissing('deployment_targets', ['id' => $target->id]);
    }

    public function test_deleting_a_target_with_deployment_history_is_blocked_and_preserves_history(): void
    {
        $user = User::factory()->create();
        $target = DeploymentTarget::factory()->create();
        $deployment = Deployment::factory()->create();
        DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_SUCCEEDED,
        ]);

        $response = $this->actingAs($user)->delete(route('deployment-targets.destroy', $target));

        $response->assertStatus(409);
        $this->assertDatabaseHas('deployment_targets', ['id' => $target->id]);
    }

    public function test_only_the_active_environment_is_used_when_a_project_has_more_than_one(): void
    {
        $project = Project::factory()->create();
        $disabled = Environment::factory()->create(['project_id' => $project->id, 'status' => Environment::STATUS_DISABLED]);
        DeploymentTarget::factory()->create(['environment_id' => $disabled->id, 'name' => 'from-disabled-env']);

        $active = Environment::factory()->create(['project_id' => $project->id, 'status' => Environment::STATUS_ACTIVE]);
        DeploymentTarget::factory()->create(['environment_id' => $active->id, 'name' => 'from-active-env']);

        $targets = app(DeploymentTargetResolver::class)->resolveTargetsFor($project);

        $this->assertCount(1, $targets);
        $this->assertSame('from-active-env', $targets[0]->targetKey);
    }

    public function test_project_with_only_a_disabled_environment_resolves_to_no_targets_not_a_legacy_fallback(): void
    {
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id, 'status' => Environment::STATUS_DISABLED]);

        $targets = app(DeploymentTargetResolver::class)->resolveTargetsFor($project);

        $this->assertSame([], $targets);
    }

    public function test_legacy_project_without_any_environment_still_resolves_and_renders(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $targets = app(DeploymentTargetResolver::class)->resolveTargetsFor($project);
        $this->assertCount(1, $targets);
        $this->assertTrue($targets[0]->isSynthesized);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $response->assertOk();
        $response->assertSee(__('ui.environments.empty_state'));
    }

    public function test_project_detail_page_never_exposes_agent_or_server_credentials(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $server = Server::factory()->create();
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id, 'agent_id' => $agent->id]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee($server->signing_secret);
        $response->assertDontSee($agent->signing_secret);
    }

    public function test_environment_and_target_mutations_require_authentication(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);

        $this->post(route('environments.store', $project))->assertRedirect(route('login'));
        $this->post(route('deployment-targets.store', $environment))->assertRedirect(route('login'));
        $this->post(route('deployment-targets.toggle-status', $target))->assertRedirect(route('login'));
        $this->delete(route('deployment-targets.destroy', $target))->assertRedirect(route('login'));
    }
}
