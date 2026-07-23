<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-G: Resource-level authorization. Composes the Phase 4-F
 * role/permission matrix (unchanged) with per-project membership via a
 * real ProjectPolicy — Admin bypasses membership entirely (so every
 * pre-4-G Project, with zero membership rows, stays fully manageable by
 * the one role every existing test actually uses); every other role
 * needs both the global permission verb AND an explicit membership row.
 */
class ProjectResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function deploymentFor(Project $project): Deployment
    {
        return Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);
    }

    public function test_admin_sees_and_manages_a_legacy_project_with_zero_members(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $project = Project::factory()->create();

        $this->assertSame(0, $project->members()->count());

        $this->actingAs($admin)->get(route('projects.index'))->assertOk()->assertSee($project->name);
        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($admin)->post(route('projects.deploy', $project), ['commit_hash' => str_repeat('b', 40)])
            ->assertRedirect();
    }

    public function test_developer_with_membership_can_view_and_deploy_their_project(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $developer->id]);

        $this->actingAs($developer)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($developer)->post(route('projects.deploy', $project), ['commit_hash' => str_repeat('c', 40)])
            ->assertRedirect();
    }

    public function test_developer_without_membership_cannot_view_or_deploy_a_different_project(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $memberProject = Project::factory()->create();
        $otherProject = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $memberProject->id, 'user_id' => $developer->id]);

        $this->actingAs($developer)->get(route('projects.show', $otherProject))->assertForbidden();
        $this->actingAs($developer)->post(route('projects.deploy', $otherProject), ['commit_hash' => str_repeat('d', 40)])
            ->assertForbidden();
    }

    public function test_project_list_only_shows_projects_the_user_is_a_member_of(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $allowedProject = Project::factory()->create(['name' => 'Allowed Project']);
        $deniedProject = Project::factory()->create(['name' => 'Denied Project']);
        ProjectMember::factory()->create(['project_id' => $allowedProject->id, 'user_id' => $developer->id]);

        $response = $this->actingAs($developer)->get(route('projects.index'));

        $response->assertOk();
        $response->assertSee('Allowed Project');
        $response->assertDontSee('Denied Project');
    }

    public function test_operator_with_membership_can_roll_back_but_without_membership_cannot(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $memberProject = Project::factory()->create();
        $otherProject = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $memberProject->id, 'user_id' => $operator->id]);

        $allowedDeployment = $this->deploymentFor($memberProject);
        $deniedDeployment = $this->deploymentFor($otherProject);

        $this->actingAs($operator)->post(route('deployments.rollback', $allowedDeployment))->assertRedirect();
        $this->actingAs($operator)->post(route('deployments.rollback', $deniedDeployment))->assertForbidden();
    }

    public function test_deployment_task_rollback_also_respects_project_membership(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $otherProject = Project::factory()->create();
        $deployment = $this->deploymentFor($otherProject);

        $task = \App\Models\DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => \App\Models\DeploymentTarget::factory()->create()->id,
            'status' => \App\Models\DeploymentTask::STATUS_SUCCEEDED,
        ]);

        $this->actingAs($operator)->get(route('deployment-tasks.show', $task))->assertForbidden();
        $this->actingAs($operator)->post(route('deployment-tasks.rollback', $task))->assertForbidden();
    }

    public function test_direct_url_access_to_an_unauthorized_projects_deployment_is_blocked(): void
    {
        // Task 10: "Direct URL blocked" — no link is ever shown, but
        // hitting the raw route must still 403, not silently succeed.
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $project = Project::factory()->create();
        $deployment = $this->deploymentFor($project);

        $this->actingAs($viewer)->get(route('deployments.show', $deployment))->assertForbidden();
    }

    public function test_admin_can_add_and_remove_a_project_member_and_it_is_audit_logged(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $project = Project::factory()->create();

        $this->actingAs($admin)->post(route('projects.members.store', $project), ['user_id' => $developer->id])
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $developer->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'project_member.added']);

        $member = ProjectMember::where('project_id', $project->id)->where('user_id', $developer->id)->firstOrFail();
        $this->actingAs($admin)->delete(route('projects.members.destroy', [$project, $member]))
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id, 'user_id' => $developer->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'project_member.removed']);
    }

    public function test_non_admin_cannot_manage_project_members(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $target = $this->userWithRole(User::ROLE_VIEWER);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $operator->id]);

        $this->actingAs($operator)->post(route('projects.members.store', $project), ['user_id' => $target->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id, 'user_id' => $target->id]);
    }

    public function test_ui_hides_members_section_from_non_admin_but_shows_it_to_admin(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $operator->id]);

        $adminResponse = $this->actingAs($admin)->get(route('projects.show', $project));
        $adminResponse->assertSee(__('ui.projects.members_title'));

        $operatorResponse = $this->actingAs($operator)->get(route('projects.show', $project));
        $operatorResponse->assertDontSee(__('ui.projects.members_title'));
    }

    public function test_existing_admin_workflow_is_completely_unaffected_by_membership(): void
    {
        // Compatibility: the exact pattern every pre-4-G test already uses.
        $admin = User::factory()->create();
        $project = Project::factory()->create();

        $this->actingAs($admin)->get(route('projects.index'))->assertOk();
        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk();
    }
}
