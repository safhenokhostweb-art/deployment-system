<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\LocalAgentControlJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 (project-config sync) Phase 8: editing local_path/remote_name/
 * branch on an already-created mapping without deleting and re-adding it
 * — project_key/project_id is never re-asked for here.
 */
class LocalProjectMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_local_path_branch_and_remote_on_an_existing_mapping(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Windows']);
        $project = Project::factory()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_path' => 'C:\\laragon\\www\\old-path',
            'remote_name' => 'origin',
            'git_branch' => null,
        ]);

        $response = $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'local_path' => 'C:\\laragon\\www\\new-path',
            'git_branch' => 'develop',
            'remote_name' => 'upstream',
        ]);

        $response->assertRedirect(route('local-agents.show', $agent));
        $fresh = $mapping->fresh();
        $this->assertSame('C:\\laragon\\www\\new-path', $fresh->local_path);
        $this->assertSame('C:\\laragon\\www\\new-path', $fresh->source_workspace_path);
        $this->assertSame('develop', $fresh->git_branch);
        $this->assertSame('upstream', $fresh->remote_name);
        // project_id is never touched by this endpoint — no re-selecting
        // the project itself, only editing the machine-specific fields.
        $this->assertSame($project->id, $fresh->project_id);
    }

    public function test_production_directory_cannot_be_saved_as_source_workspace(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Linux']);
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'source_workspace_path' => '/var/www/customer-site',
            'production_path' => '/var/www/customer-site',
        ])->assertStatus(422);

        $this->assertNotSame('/var/www/customer-site', $mapping->fresh()->source_workspace_path);
    }

    public function test_htdocs_directory_cannot_be_saved_as_source_workspace(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'source_workspace_path' => 'C:\\xampp\\htdocs\\customer-site',
        ])->assertStatus(422);

        $this->assertNotSame('C:\\xampp\\htdocs\\customer-site', $mapping->fresh()->source_workspace_path);
    }

    public function test_source_workspace_and_production_target_are_stored_separately(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Linux']);
        $project = Project::factory()->create(['project_key' => 'customer-site']);

        $this->actingAs($user)->post(route('local-agents.mappings.store', $agent), [
            'project_id' => $project->id,
            'source_workspace_path' => '/opt/deployment-agent/workspaces/customer-site',
            'production_path' => '/var/www/customer-site',
        ])->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_project_mappings', [
            'project_id' => $project->id,
            'source_workspace_path' => '/opt/deployment-agent/workspaces/customer-site',
            'production_path' => '/var/www/customer-site',
        ]);
    }

    public function test_windows_agent_rejects_a_linux_or_relative_source_workspace(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Windows']);
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->actingAs($user)->from(route('local-agents.show', $agent))
            ->put(route('local-agents.mappings.update', [$agent, $mapping]), [
                'source_workspace_path' => '/opt/deployment-agent/workspaces/saas-platform',
            ])->assertSessionHasErrors('source_workspace_path');

        $this->actingAs($user)->from(route('local-agents.show', $agent))
            ->put(route('local-agents.mappings.update', [$agent, $mapping]), [
                'source_workspace_path' => 'deployment-workspaces\\saas-platform',
            ])->assertSessionHasErrors('source_workspace_path');
    }

    public function test_linux_agent_accepts_a_non_production_absolute_workspace(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Linux']);
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'source_workspace_path' => '/opt/deployment-agent/workspaces/saas-platform',
            'git_branch' => 'main',
            'remote_name' => 'origin',
        ])->assertRedirect(route('local-agents.show', $agent));

        $this->assertSame('/opt/deployment-agent/workspaces/saas-platform', $mapping->fresh()->source_workspace_path);
    }

    public function test_mapping_rejects_branch_names_the_agent_cannot_check_out(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Linux']);
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        foreach (['feature..broken', 'feature@{broken', 'feature/', 'feature.'] as $branch) {
            $this->actingAs($user)->from(route('local-agents.show', $agent))
                ->put(route('local-agents.mappings.update', [$agent, $mapping]), [
                    'source_workspace_path' => '/opt/deployment-agent/workspaces/saas-platform',
                    'git_branch' => $branch,
                    'remote_name' => 'origin',
                ])->assertSessionHasErrors('git_branch');
        }
    }

    public function test_mapping_update_preserves_production_target_and_has_no_operational_side_effects(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Windows']);
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'production_path' => '/var/www/saas-platform',
        ]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'source_workspace_path' => 'C:\\deployment-workspaces\\saas-platform',
            'production_path' => '/var/www/tampered',
            'git_branch' => 'main',
            'remote_name' => 'origin',
        ])->assertRedirect(route('local-agents.show', $agent));

        $fresh = $mapping->fresh();
        $this->assertSame('/var/www/saas-platform', $fresh->production_path);
        $this->assertSame('C:\\deployment-workspaces\\saas-platform', $fresh->source_workspace_path);
        $this->assertSame(0, Artifact::count());
        $this->assertSame(0, Deployment::count());
        $this->assertSame(0, PublishJob::count());
        $this->assertSame(0, LocalAgentControlJob::count());
    }

    public function test_mapping_ui_shows_agent_specific_path_guidance_and_reported_state(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['operating_system' => 'Windows']);
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'local_head_sha' => str_repeat('d', 40),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);

        $this->actingAs($user)->get(route('local-agents.show', $agent))
            ->assertOk()
            ->assertSee('C:\\deployment-workspaces\\project')
            ->assertSee(str_repeat('d', 40))
            ->assertSee('Clean')
            ->assertSee('protected reference only');
    }

    public function test_cannot_update_a_mapping_belonging_to_a_different_agent(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $otherAgent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $otherAgent->id]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'local_path' => 'C:\\laragon\\www\\hijacked',
        ])->assertStatus(404);
    }

    public function test_local_path_is_required(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->actingAs($user)->put(route('local-agents.mappings.update', [$agent, $mapping]), [
            'local_path' => '',
        ])->assertSessionHasErrors('local_path');
    }

    public function test_admin_can_bulk_create_mappings_for_several_projects_at_once(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $projectC = Project::factory()->create(); // left unchecked — must not be mapped

        $response = $this->actingAs($user)->post(route('local-agents.mappings.bulk-store', $agent), [
            'enabled' => [$projectA->id => '1', $projectB->id => '1'],
            'local_path' => [
                $projectA->id => 'C:\\laragon\\www\\' . $projectA->project_key,
                $projectB->id => 'C:\\laragon\\www\\' . $projectB->project_key,
                $projectC->id => 'C:\\laragon\\www\\' . $projectC->project_key,
            ],
            'git_branch' => [$projectA->id => 'develop'],
            'remote_name' => [],
        ]);

        $response->assertRedirect(route('local-agents.show', $agent));
        $this->assertDatabaseHas('local_project_mappings', [
            'local_agent_id' => $agent->id,
            'project_id' => $projectA->id,
            'local_path' => 'C:\\laragon\\www\\' . $projectA->project_key,
            'git_branch' => 'develop',
            'remote_name' => 'origin',
        ]);
        $this->assertDatabaseHas('local_project_mappings', [
            'local_agent_id' => $agent->id,
            'project_id' => $projectB->id,
        ]);
        $this->assertDatabaseMissing('local_project_mappings', [
            'local_agent_id' => $agent->id,
            'project_id' => $projectC->id,
        ]);
    }

    public function test_bulk_create_skips_a_checked_row_with_a_blank_local_path(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        $this->actingAs($user)->post(route('local-agents.mappings.bulk-store', $agent), [
            'enabled' => [$project->id => '1'],
            'local_path' => [$project->id => '   '],
        ])->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseMissing('local_project_mappings', [
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_bulk_create_skips_a_project_already_mapped_to_this_agent(): void
    {
        $user = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_path' => 'C:\\original',
        ]);

        $this->actingAs($user)->post(route('local-agents.mappings.bulk-store', $agent), [
            'enabled' => [$project->id => '1'],
            'local_path' => [$project->id => 'C:\\overwritten'],
        ])->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseCount('local_project_mappings', 1);
        $this->assertDatabaseHas('local_project_mappings', [
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_path' => 'C:\\original',
        ]);
    }

    public function test_developer_cannot_bulk_create_mappings(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        $this->actingAs($developer)->post(route('local-agents.mappings.bulk-store', $agent), [
            'enabled' => [$project->id => '1'],
            'local_path' => [$project->id => 'C:\\x'],
        ])->assertStatus(403);
    }
}
