<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Deployment\Recipe\RecipeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4/5 of the sandogh deployment overhaul: proves the deployment
 * show page actually renders a recipe-driven deployment correctly, not
 * just that RecipeStepPresenter's array shape looks right in isolation —
 * the recipe steps title, per-step status/type/failure_code, and the
 * deployed_at-vs-finished_at "verified deployed" distinction Phase 5
 * exists for.
 */
class RecipeDeploymentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function recipeProject(): Project
    {
        $recipe = app(RecipeValidator::class)->validate([
            ['type' => 'install_dependencies', 'working_directory' => '{release_path}'],
            ['type' => 'run_script', 'script' => 'build', 'working_directory' => '{release_path}'],
            ['type' => 'activate_release'],
            ['type' => 'http_health_check', 'url' => 'https://example.test/health'],
        ]);

        return Project::factory()->create(['deployment_recipe' => $recipe]);
    }

    public function test_recipe_driven_deployment_shows_the_recipe_steps_title_not_pipeline(): void
    {
        $admin = $this->admin();
        $project = $this->recipeProject();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_BUILDING,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $deployment));

        // Proves the recipe branch was actually taken, not the legacy
        // pipeline one — a substring assertDontSee for "pipeline" would
        // give a false positive here, since the unrelated "Pipeline log"
        // raw-output section (pipeline_log_title) legitimately appears on
        // every deployment page regardless of recipe vs pipeline.
        $response->assertOk();
        $response->assertSee(__('ui.deployments.recipe_steps_title'));
        $response->assertSee('install_dependencies');
        $response->assertSee('run_script');
    }

    public function test_a_failed_recipe_step_shows_its_failure_code_and_guidance(): void
    {
        $admin = $this->admin();
        $project = $this->recipeProject();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'failure_message' => 'npm ci exited with code 1.',
            'failure_code' => Deployment::FAILURE_CODE_BUILD_FAILED,
        ]);
        DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
            'sequence' => 1,
            'step_name' => 'install_dependencies',
            'command' => 'install_dependencies',
            'output' => 'npm error code 1',
            'exit_code' => 1,
            'duration_ms' => 1200,
            'status' => 'failed',
            'step_type' => 'install_dependencies',
            'failure_code' => Deployment::FAILURE_CODE_BUILD_FAILED,
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertSee(Deployment::FAILURE_CODE_BUILD_FAILED);
        $response->assertSee('preflight step failed', false);
    }

    public function test_deployed_at_shows_verified_deployed_while_finished_without_it_does_not(): void
    {
        $admin = $this->admin();
        $project = $this->recipeProject();

        $verified = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now(),
            'deployed_at' => now(),
            'http_health_config' => ['enabled' => true, 'url' => 'https://example.test/'],
            'http_health_status' => Deployment::HTTP_HEALTH_VERIFIED,
            'http_health_verified_at' => now(),
        ]);
        $unverified = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_FAILED,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now(),
            'deployed_at' => null,
        ]);

        $verifiedResponse = $this->actingAs($admin)->get(route('deployments.show', $verified));
        $verifiedResponse->assertOk();
        $verifiedResponse->assertSee(__('ui.deployments.verified_deployed_yes'));

        $unverifiedResponse = $this->actingAs($admin)->get(route('deployments.show', $unverified));
        $unverifiedResponse->assertOk();
        $unverifiedResponse->assertSee(__('ui.deployments.verified_deployed_no'));
    }

    /**
     * The exact scenario Phase 5 exists to prevent: a Deployment whose
     * agent-reported shell commands all exited 0 (finished_at set) is
     * NOT the same claim as "verified serving the new code" — this
     * would previously have been indistinguishable from a fully-verified
     * success in the UI.
     */
    public function test_finished_but_unverified_deployment_is_visibly_distinct_from_a_verified_one(): void
    {
        $admin = $this->admin();
        $project = $this->recipeProject();
        $rolledBack = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_ROLLED_BACK,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now(),
            'rolled_back_at' => now(),
            'deployed_at' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $rolledBack));

        $response->assertOk();
        $response->assertSee(__('ui.deployments.verified_deployed_no'));
        $response->assertSee(__('status.rolled_back'));
    }
}
