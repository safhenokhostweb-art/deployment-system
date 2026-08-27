<?php

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectActiveToggleTest extends TestCase
{
    use RefreshDatabase;

    private function baseUpdatePayload(Project $project): array
    {
        return [
            'name' => $project->name,
            'repository' => $project->repository,
            'branch' => $project->branch,
            'environment' => $project->environment,
            'server_id' => $project->server_id,
            'pipeline' => json_encode($project->pipeline, JSON_THROW_ON_ERROR),
        ];
    }

    public function test_omitting_is_active_from_the_form_deactivates_the_project(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), $this->baseUpdatePayload($project))
            ->assertRedirect(route('projects.show', $project));

        $this->assertFalse($project->fresh()->is_active);
    }

    public function test_submitting_is_active_1_keeps_the_project_active(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['is_active' => false]);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), $this->baseUpdatePayload($project) + [
                'is_active' => '1',
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertTrue($project->fresh()->is_active);
    }

    public function test_omitting_auto_deploy_from_the_form_disables_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['auto_deploy' => true]);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), $this->baseUpdatePayload($project))
            ->assertRedirect(route('projects.show', $project));

        $this->assertFalse($project->fresh()->auto_deploy);
    }

    public function test_submitting_auto_deploy_1_enables_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['auto_deploy' => false]);

        $this->actingAs($admin)
            ->put(route('projects.update', $project), $this->baseUpdatePayload($project) + [
                'auto_deploy' => '1',
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertTrue($project->fresh()->auto_deploy);
    }
}
