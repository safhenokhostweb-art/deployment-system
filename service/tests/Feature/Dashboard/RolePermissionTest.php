<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-F: Role & Permission Foundation. Covers the hybrid design —
 * role column + PHP permission matrix + Gate::before — end to end: allow
 * for the role that should have it, deny (403, not a silent redirect)
 * for the role that shouldn't, UI hiding matches backend enforcement, and
 * every pre-existing test's User::factory()->create() (role defaults to
 * 'admin') keeps working exactly as before this phase.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_default_factory_user_is_admin_and_keeps_full_access(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue($user->hasPermission('servers.create'));
        $this->assertTrue($user->hasPermission('github.disconnect'));
        $this->assertTrue($user->hasPermission('users.manage'));
    }

    public function test_admin_can_create_a_server(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Admin Server'])
            ->assertRedirect();

        $this->assertDatabaseHas('servers', ['name' => 'Admin Server']);
    }

    public function test_viewer_can_view_the_dashboard_and_lists(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);

        $this->actingAs($viewer)->get(route('dashboard.index'))->assertOk();
        $this->actingAs($viewer)->get(route('servers.index'))->assertOk();
        $this->actingAs($viewer)->get(route('projects.index'))->assertOk();
        $this->actingAs($viewer)->get(route('agents.index'))->assertOk();
        $this->actingAs($viewer)->get(route('github.accounts.index'))->assertOk();
    }

    public function test_viewer_cannot_create_a_server(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);

        $this->actingAs($viewer)->get(route('servers.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('servers.store'), ['name' => 'Should Fail'])->assertForbidden();

        $this->assertDatabaseMissing('servers', ['name' => 'Should Fail']);
    }

    public function test_viewer_cannot_rotate_server_credentials(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $server = Server::factory()->create();

        $this->actingAs($viewer)->post(route('servers.rotate-token', $server))->assertForbidden();
    }

    public function test_viewer_cannot_connect_or_disconnect_github(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $account = GitHubAccount::factory()->create();

        $this->actingAs($viewer)->get(route('github.connect'))->assertForbidden();
        $this->actingAs($viewer)->post(route('github.accounts.disconnect', $account))->assertForbidden();
    }

    public function test_viewer_cannot_approve_or_disable_agents(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $server = Server::factory()->create();
        $agent = Agent::factory()->create(['server_id' => $server->id, 'name' => 'pending-agent']);
        $approvedAgent = Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($viewer)->post(route('agents.approve', $agent))->assertForbidden();
        $this->actingAs($viewer)->post(route('agents.disable', $approvedAgent))->assertForbidden();
    }

    public function test_viewer_cannot_deploy_or_rollback(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $project = Project::factory()->create();

        $this->actingAs($viewer)->post(route('projects.deploy', $project), ['commit_hash' => str_repeat('a', 40)])
            ->assertForbidden();

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);
        $this->actingAs($viewer)->post(route('deployments.rollback', $deployment))->assertForbidden();
    }

    public function test_operator_can_deploy_and_rollback(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $project = Project::factory()->create();
        // Phase 4-G: deploy/rollback became project-scoped for non-admin
        // roles — this membership row is the realistic prerequisite that
        // makes "operator can deploy" still true, exactly as it was here
        // before that phase, now on a project they're actually part of.
        \App\Models\ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $operator->id]);

        $this->actingAs($operator)->post(route('projects.deploy', $project), ['commit_hash' => str_repeat('a', 40)])
            ->assertRedirect();
    }

    public function test_operator_cannot_manage_servers_or_github(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);

        $this->actingAs($operator)->post(route('servers.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($operator)->get(route('github.connect'))->assertForbidden();
    }

    public function test_developer_can_trigger_deploy_but_not_rollback(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $project = Project::factory()->create();
        // Phase 4-G: see the identical note in test_operator_can_deploy_and_rollback.
        \App\Models\ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $developer->id]);

        $this->actingAs($developer)->post(route('projects.deploy', $project), ['commit_hash' => str_repeat('a', 40)])
            ->assertRedirect();

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);
        $this->actingAs($developer)->post(route('deployments.rollback', $deployment))->assertForbidden();
    }

    public function test_developer_cannot_manage_servers(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);

        $this->actingAs($developer)->post(route('servers.store'), ['name' => 'Nope'])->assertForbidden();
    }

    public function test_environment_and_target_management_requires_admin(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $project = Project::factory()->create();

        $this->actingAs($operator)->post(route('environments.store', $project), ['name' => 'Production', 'key' => 'production'])
            ->assertForbidden();
    }

    public function test_direct_url_access_to_a_protected_route_is_blocked_regardless_of_ui(): void
    {
        // Task 9: "Direct URL access blocked" — a Viewer hitting the raw
        // route directly (no link ever shown to them) must still be denied.
        $viewer = $this->userWithRole(User::ROLE_VIEWER);

        $this->actingAs($viewer)->get(route('servers.create'))->assertForbidden();
    }

    public function test_ui_hides_the_register_server_button_for_a_viewer_and_shows_it_for_an_admin(): void
    {
        $viewer = $this->userWithRole(User::ROLE_VIEWER);
        $admin = $this->userWithRole(User::ROLE_ADMIN);

        $viewerResponse = $this->actingAs($viewer)->get(route('servers.index'));
        $viewerResponse->assertOk();
        $viewerResponse->assertDontSee(__('ui.servers.register_button'));

        $adminResponse = $this->actingAs($admin)->get(route('servers.index'));
        $adminResponse->assertOk();
        $adminResponse->assertSee(__('ui.servers.register_button'));
    }

    public function test_ui_hides_deploy_button_for_developer_but_shows_rollback_for_operator(): void
    {
        $developer = $this->userWithRole(User::ROLE_DEVELOPER);
        $operator = $this->userWithRole(User::ROLE_OPERATOR);

        $project = Project::factory()->create();
        // Phase 4-G: viewing the page at all now requires membership —
        // both need it here so the test can actually reach the button
        // visibility question this test is about (role permission, not
        // project access).
        \App\Models\ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $developer->id]);
        \App\Models\ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $operator->id]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);

        $developerResponse = $this->actingAs($developer)->get(route('deployments.show', $deployment));
        $developerResponse->assertDontSee(__('ui.deployments.rollback_button'));

        $operatorResponse = $this->actingAs($operator)->get(route('deployments.show', $deployment));
        $operatorResponse->assertSee(__('ui.deployments.rollback_button'));
    }

    public function test_only_admin_can_manage_users(): void
    {
        $operator = $this->userWithRole(User::ROLE_OPERATOR);
        $target = $this->userWithRole(User::ROLE_VIEWER);

        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
        $this->actingAs($operator)->post(route('users.update-role', $target), ['role' => 'admin'])->assertForbidden();
        $this->assertSame(User::ROLE_VIEWER, $target->fresh()->role);
    }

    public function test_admin_can_change_a_users_role_and_it_is_audit_logged(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $target = $this->userWithRole(User::ROLE_VIEWER);

        $this->actingAs($admin)->post(route('users.update-role', $target), ['role' => 'operator'])
            ->assertRedirect(route('users.index'));

        $this->assertSame(User::ROLE_OPERATOR, $target->fresh()->role);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'user.role_changed',
            'actor_identifier' => $admin->email,
        ]);
    }

    public function test_unauthenticated_requests_are_still_redirected_to_login_not_403(): void
    {
        // Authorization (403) is a different failure mode from
        // authentication (redirect to login) — make sure this phase didn't
        // blur that distinction for a guest hitting a protected route.
        $this->get(route('servers.create'))->assertRedirect(route('login'));
    }
}
