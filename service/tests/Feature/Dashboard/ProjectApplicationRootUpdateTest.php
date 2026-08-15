<?php

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectApplicationRootUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_application_root_without_changing_existing_pipeline_or_recipe(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pipeline = [
            ['name' => 'activate', 'command' => 'php artisan up'],
        ];
        $recipe = [
            'steps' => [
                ['type' => 'activate_release'],
            ],
        ];
        $project = Project::factory()->create([
            'pipeline' => $pipeline,
            'deployment_recipe' => $recipe,
            'application_root' => '.',
        ]);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), [
                'name' => $project->name,
                'repository' => $project->repository,
                'branch' => $project->branch,
                'application_root' => 'service',
                'environment' => $project->environment,
                'server_id' => $project->server_id,
                'pipeline' => json_encode($pipeline, JSON_THROW_ON_ERROR),
                'auto_deploy' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();

        $this->assertSame('service', $project->application_root);
        $this->assertSame($pipeline, $project->pipeline);
        $this->assertSame($recipe, $project->deployment_recipe);
    }

    public function test_existing_projects_default_application_root_to_dot_when_the_form_omits_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['application_root' => '.']);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), [
                'name' => $project->name,
                'repository' => $project->repository,
                'branch' => $project->branch,
                'environment' => $project->environment,
                'server_id' => $project->server_id,
                'pipeline' => json_encode($project->pipeline, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame('.', $project->fresh()->application_root);
    }
}
