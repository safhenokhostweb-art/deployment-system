<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Delete a project" — Project had no destroy() action at all, which was
 * the actual blocker reported for deleting a Server (servers.projects()
 * ->restrictOnDelete()'s at the schema level). deployments.project_id and
 * environments.project_id both restrictOnDelete a Project the same way.
 */
class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_project_with_no_dependents(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create(['name' => 'Deletable Project']);

        $response = $this->actingAs($admin)->delete(route('projects.destroy', $project));

        $response->assertRedirect(route('projects.index'));
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'project.deleted']);
    }

    public function test_non_admin_cannot_delete_a_project(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $project = Project::factory()->create();

        $this->actingAs($operator)->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_project_with_a_deployment_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();
        Deployment::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin)->delete(route('projects.destroy', $project))
            ->assertStatus(409);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_project_with_an_environment_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin)->delete(route('projects.destroy', $project))
            ->assertStatus(409);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_delete_button_is_visible_to_admin_on_the_project_detail_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee(__('ui.projects.delete_button'));
    }

    public function test_delete_button_is_hidden_from_a_viewer_on_the_project_detail_page(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $viewer->id]);

        $response = $this->actingAs($viewer)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee(__('ui.projects.delete_button'));
    }
}
