<?php

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectArtifactSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'name' => $project->name,
            'repository' => $project->repository,
            'branch' => $project->branch,
            'environment' => $project->environment,
            'server_id' => $project->server_id,
            'pipeline' => json_encode($project->pipeline, JSON_THROW_ON_ERROR),
        ], $overrides);
    }

    public function test_admin_can_persist_artifact_paths_and_complete_artifact_requirement(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_preserve_paths' => ['.env', 'storage', 'public/storage'],
            'requires_complete_artifact' => '1',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['.env', 'storage', 'public/storage'], $project->artifact_preserve_paths);
        $this->assertTrue($project->requires_complete_artifact);
    }

    public function test_complete_artifact_requirement_can_be_explicitly_disabled(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['requires_complete_artifact' => true]);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'requires_complete_artifact' => '0',
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertFalse($project->fresh()->requires_complete_artifact);
    }

    public function test_invalid_artifact_preserve_paths_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['/absolute/path', '../secret', 'storage/../secret', 'C:\\temp', 'storage\\..\\secret'] as $path) {
            $project = Project::factory()->create();
            $this->actingAs($admin)->from(route('projects.edit', $project))
                ->put(route('projects.update', $project), $this->payload($project, [
                    'artifact_preserve_paths' => [$path],
                ]))
                ->assertRedirect(route('projects.edit', $project))
                ->assertSessionHasErrors('artifact_preserve_paths');
        }
    }

    public function test_valid_nested_relative_path_is_accepted_and_backslashes_normalize(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_preserve_paths' => ['storage/app/uploads', 'public\\storage'],
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertSame(['storage/app/uploads', 'public/storage'], $project->fresh()->artifact_preserve_paths);
    }

    public function test_duplicate_artifact_preserve_paths_are_rejected_after_normalization(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_preserve_paths' => ['public/storage', 'public\\storage'],
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_preserve_paths');
    }

    public function test_omitted_artifact_settings_are_preserved_by_unrelated_project_updates(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'artifact_preserve_paths' => ['.env', 'storage', 'public/storage'],
            'requires_complete_artifact' => true,
        ]);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'application_root' => '.',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['.env', 'storage', 'public/storage'], $project->artifact_preserve_paths);
        $this->assertTrue($project->requires_complete_artifact);
    }

    public function test_typed_pipeline_operations_remain_valid_with_artifact_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();
        $pipeline = [
            ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
            ['name' => 'restart services', 'type' => 'service_reload', 'command' => ''],
        ];

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'pipeline' => json_encode($pipeline, JSON_THROW_ON_ERROR),
            'artifact_preserve_paths' => ['.env'],
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertSame($pipeline, $project->fresh()->pipeline);
    }

    public function test_edit_form_exposes_artifact_settings_controls(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'artifact_preserve_paths' => ['.env', 'storage'],
            'requires_complete_artifact' => true,
        ]);

        $this->actingAs($admin)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('artifact_preserve_paths_text', false)
            ->assertSee('requires_complete_artifact', false)
            ->assertSee(".env\nstorage", false);
    }
}
