<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\PublishJob;
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

    public function test_admin_can_persist_artifact_persistent_configuration(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_persistent_paths' => ['database'],
            'artifact_persistent_root' => '/var/www/deployment-system/persistent',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['database'], $project->artifact_persistent_paths);
        $this->assertSame('/var/www/deployment-system/persistent', $project->artifact_persistent_root);
    }

    public function test_customer_upload_release_path_can_map_to_flattened_shared_target(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_persistent_paths' => ['public/uploads' => 'uploads'],
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['public/uploads' => 'uploads'], $project->artifact_persistent_paths);
        $this->assertSame('/var/www/customer-site-shared', $project->artifact_persistent_root);
    }

    public function test_saving_customer_persistence_contract_has_no_operational_side_effects(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_persistent_paths_text' => 'public/uploads => uploads',
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['public/uploads' => 'uploads'], $project->artifact_persistent_paths);
        $this->assertSame('/var/www/customer-site-shared', $project->artifact_persistent_root);
        $this->assertDatabaseCount((new Deployment())->getTable(), 0);
        $this->assertDatabaseCount((new PublishJob())->getTable(), 0);
        $this->assertDatabaseCount((new Artifact())->getTable(), 0);
    }

    public function test_customer_site_rejects_current_release_layout_assumptions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        foreach (['current/public/uploads', '/var/www/customer-site/current/public/uploads'] as $releasePath) {
            $this->actingAs($admin)->from(route('projects.edit', $project))
                ->put(route('projects.update', $project), $this->payload($project, [
                    'artifact_persistent_paths' => [$releasePath => 'uploads'],
                    'artifact_persistent_root' => '/var/www/customer-site-shared',
                ]))
                ->assertRedirect(route('projects.edit', $project))
                ->assertSessionHasErrors('artifact_persistent_paths');
        }
    }

    public function test_mapping_textarea_round_trips_release_and_shared_paths(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_persistent_paths_text' => "public/uploads => uploads",
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertSame(['public/uploads' => 'uploads'], $project->fresh()->artifact_persistent_paths);
        $this->actingAs($admin)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('public/uploads =&gt; uploads', false);
    }

    public function test_persistent_root_must_be_an_absolute_unix_path(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_persistent_paths' => ['public/uploads' => 'uploads'],
                'artifact_persistent_root' => 'relative/shared',
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_persistent_root');
    }

    public function test_customer_site_rejects_any_contract_not_supported_by_the_fixed_helper(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_persistent_paths' => ['public/uploads'],
                'artifact_persistent_root' => '/var/www/customer-site-shared',
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_persistent_paths');
    }

    public function test_persistent_paths_normalize_backslashes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'artifact_persistent_paths' => ['database' . chr(92) . 'main'],
            'artifact_persistent_root' => '/var/www/deployment-system/persistent',
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertSame(['database/main'], $project->fresh()->artifact_persistent_paths);
    }

    public function test_invalid_artifact_persistent_paths_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['/absolute/path', '../secret', 'storage/../secret', 'C:\\\\temp'] as $path) {
            $project = Project::factory()->create();
            $this->actingAs($admin)->from(route('projects.edit', $project))
                ->put(route('projects.update', $project), $this->payload($project, [
                    'artifact_persistent_paths' => [$path],
                    'artifact_persistent_root' => '/var/www/deployment-system/persistent',
                ]))
                ->assertRedirect(route('projects.edit', $project))
                ->assertSessionHasErrors('artifact_persistent_paths');
        }
    }

    public function test_persistent_paths_require_a_persistent_root(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_persistent_paths' => ['database'],
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_persistent_root');
    }

    public function test_persistent_path_overlapping_default_preserve_paths_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        // No explicit preserve list means the built-in defaults (.env,
        // storage, public/storage) — 'storage' as persistent is an
        // ambiguous contract and must be rejected exactly like the agent's
        // fail-closed prepare() would.
        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_persistent_paths' => ['storage'],
                'artifact_persistent_root' => '/var/www/deployment-system/persistent',
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_persistent_paths');
    }

    public function test_persistent_path_overlapping_explicit_preserve_paths_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'artifact_preserve_paths' => ['storage'],
                'artifact_persistent_paths' => ['storage/app'],
                'artifact_persistent_root' => '/var/www/deployment-system/persistent',
            ]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('artifact_persistent_paths');
    }

    public function test_persistent_configuration_is_preserved_by_unrelated_project_updates(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database'],
            'artifact_persistent_root' => '/var/www/deployment-system/persistent',
        ]);

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'application_root' => '.',
        ]))->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['database'], $project->artifact_persistent_paths);
        $this->assertSame('/var/www/deployment-system/persistent', $project->artifact_persistent_root);
    }

    public function test_edit_form_exposes_persistent_settings_controls(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database'],
            'artifact_persistent_root' => '/var/www/deployment-system/persistent',
        ]);

        $this->actingAs($admin)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('artifact_persistent_paths_text', false)
            ->assertSee('artifact_persistent_root', false)
            ->assertSee('database', false)
            ->assertSee('/var/www/deployment-system/persistent', false);
    }

    public function test_customer_edit_form_documents_the_fixed_active_layout(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($admin)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('/var/www/customer-site/public/uploads', false)
            ->assertSee('A <code>/current</code> release layout is not supported.', false);
    }
}
