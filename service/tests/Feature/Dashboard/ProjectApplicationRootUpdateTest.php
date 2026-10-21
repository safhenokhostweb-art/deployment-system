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

    public function test_legacy_pipeline_preset_identifier_is_normalized_when_updating_application_root(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'pipeline' => 'laravel',
            'application_root' => '.',
            'auto_deploy' => false,
            'is_active' => true,
        ]);
        $original = $project->refresh()->getRawOriginal();
        $presetResponse = $this->actingAs($admin)->get(route('projects.create'));
        $presetResponse->assertOk();
        $expectedPipeline = json_decode($presetResponse->viewData('pipelinePresets')['laravel'], true, 512, JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), [
                'name' => $project->name,
                'repository' => $project->repository,
                'branch' => $project->branch,
                'application_root' => 'service',
                'environment' => $project->environment,
                'server_id' => $project->server_id,
                'pipeline' => json_encode('laravel', JSON_THROW_ON_ERROR),
                'auto_deploy' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();

        $this->assertSame('service', $project->application_root);
        $this->assertSame($expectedPipeline, $project->pipeline);
        foreach ($original as $attribute => $value) {
            if (! in_array($attribute, ['pipeline', 'application_root', 'updated_at'], true)) {
                $this->assertSame($value, $project->getRawOriginal($attribute), $attribute);
            }
        }
    }

    public function test_unknown_legacy_pipeline_cannot_be_normalized(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['pipeline' => 'unknown', 'application_root' => '.']);
        $original = $project->refresh()->getRawOriginal();

        $this->actingAs($admin)->putJson(route('projects.update', $project), [
            'name' => $project->name,
            'repository' => $project->repository,
            'branch' => $project->branch,
            'application_root' => 'service',
            'environment' => $project->environment,
            'server_id' => $project->server_id,
            'pipeline' => json_encode('unknown', JSON_THROW_ON_ERROR),
        ])->assertStatus(422);

        $this->assertSame($original, $project->fresh()->getRawOriginal());
    }
}
