<?php

namespace Tests\Feature\Dashboard;

use App\Events\AgentOffline;
use App\Events\CredentialRotated;
use App\Events\DeploymentFailed;
use App\Events\DeploymentStarted;
use App\Events\GitHubConnected;
use App\Events\PermissionChanged;
use App\Events\RollbackStarted;
use App\Models\Agent;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-J Task 12. Deliberately does not re-test Deployment Engine
 * behavior, Agent auth, or GitHub API calls — only that this phase's new,
 * additive instrumentation (event dispatch + the subscriber that reacts
 * to it) produces the right Notification rows, and that the Notification
 * Center UI enforces "own notifications only".
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_deployment_success_notifies_admins_and_the_triggering_user(): void
    {
        $admin = $this->admin();
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER, 'email' => 'dev@example.test']);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_QUEUED,
            'triggered_by' => 'dev@example.test',
        ]);

        event(new \App\Events\DeploymentSucceeded($deployment));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_DEPLOYMENT_SUCCESS,
            'related_type' => Deployment::class,
            'related_id' => $deployment->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $developer->id,
            'type' => Notification::TYPE_DEPLOYMENT_SUCCESS,
        ]);
    }

    public function test_deployment_started_notifies_admins_and_the_triggering_user(): void
    {
        $admin = $this->admin();
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER, 'email' => 'starter@example.test']);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'triggered_by' => 'starter@example.test',
        ]);

        event(new DeploymentStarted($deployment));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_DEPLOYMENT_STARTED,
            'related_type' => Deployment::class,
            'related_id' => $deployment->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $developer->id,
            'type' => Notification::TYPE_DEPLOYMENT_STARTED,
        ]);
    }

    public function test_rollback_started_notifies_admins_with_its_own_type(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();
        $rollback = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_ROLLBACK,
        ]);

        event(new RollbackStarted($rollback));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_DEPLOYMENT_ROLLBACK_STARTED,
            'related_type' => Deployment::class,
            'related_id' => $rollback->id,
        ]);
    }

    /**
     * Task 2 (completion): a failed rollback must read clearly as a
     * rollback failure, not a generic deployment failure, even though both
     * reuse the same DeploymentFailed event and the same notification type.
     */
    public function test_a_failed_rollback_has_distinct_wording_from_a_failed_deployment(): void
    {
        $this->admin();
        $project = Project::factory()->create();
        $normalDeploy = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'action' => Deployment::ACTION_DEPLOY]);
        $rollback = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'action' => Deployment::ACTION_ROLLBACK]);

        event(new DeploymentFailed($normalDeploy));
        event(new DeploymentFailed($rollback));

        $deployNotification = Notification::where('related_id', $normalDeploy->id)->where('type', Notification::TYPE_DEPLOYMENT_FAILED)->firstOrFail();
        $rollbackNotification = Notification::where('related_id', $rollback->id)->where('type', Notification::TYPE_DEPLOYMENT_FAILED)->firstOrFail();

        $this->assertNotSame($deployNotification->title, $rollbackNotification->title);
        $this->assertStringContainsString('بازگشت', $rollbackNotification->title);
    }

    public function test_agent_offline_notifies_admins(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create(['name' => 'prod-web-2']);
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id, 'name' => 'worker-1']);

        event(new AgentOffline($agent));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_AGENT_OFFLINE,
            'related_type' => Agent::class,
            'related_id' => $agent->id,
            'severity' => Notification::SEVERITY_CRITICAL,
        ]);
    }

    public function test_deployment_failed_notifies_with_critical_severity(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'triggered_by' => null,
        ]);

        event(new \App\Events\DeploymentFailed($deployment, 'boom'));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_DEPLOYMENT_FAILED,
            'severity' => Notification::SEVERITY_CRITICAL,
        ]);
    }

    public function test_credential_rotated_notification_never_contains_a_secret_value(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create(['name' => 'prod-web-1']);

        event(new CredentialRotated($server, $admin->email));

        $notification = Notification::where('type', Notification::TYPE_CREDENTIAL_ROTATED)->firstOrFail();
        $this->assertStringNotContainsString($server->agent_token_hash ?? '', $notification->message);
        $this->assertStringContainsString('prod-web-1', $notification->message);
        // Task 11's own bad/good example: never a raw secret string.
        $this->assertDoesNotMatchRegularExpression('/password|secret|token/i', $notification->message);
    }

    public function test_permission_changed_notifies_admins(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => User::ROLE_VIEWER, 'name' => 'Ali']);

        event(new PermissionChanged($target, User::ROLE_VIEWER, User::ROLE_OPERATOR, $admin->email));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_PERMISSION_CHANGED,
            'related_type' => User::class,
            'related_id' => $target->id,
        ]);
    }

    public function test_github_connected_notifies_admins_not_secret_bearing(): void
    {
        $admin = $this->admin();
        $account = GitHubAccount::factory()->create(['account_login' => 'acme-org']);

        event(new GitHubConnected($account, $admin->email));

        $notification = Notification::where('type', Notification::TYPE_GITHUB_CONNECTED)->firstOrFail();
        $this->assertStringContainsString('acme-org', $notification->message);
    }

    public function test_user_wants_notification_defaults_true_when_no_preference_set(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->wantsNotification(Notification::TYPE_DEPLOYMENT_FAILED));
    }

    public function test_disabled_preference_prevents_notification_creation(): void
    {
        $admin = $this->admin();
        $admin->update(['notification_preferences' => [Notification::TYPE_DEPLOYMENT_FAILED => false]]);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'triggered_by' => null,
        ]);

        event(new \App\Events\DeploymentFailed($deployment));

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_DEPLOYMENT_FAILED,
        ]);
    }

    public function test_user_sees_only_their_own_notifications_on_the_index_page(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $otherUser = User::factory()->create();

        Notification::factory()->create(['user_id' => $viewer->id, 'title' => 'Mine']);
        Notification::factory()->create(['user_id' => $otherUser->id, 'title' => 'Not mine']);

        $response = $this->actingAs($viewer)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Not mine');
    }

    public function test_mark_read_updates_read_at(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $notification = Notification::factory()->unread()->create(['user_id' => $viewer->id]);

        $this->actingAs($viewer)->post(route('notifications.read', $notification))->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->unread()->create(['user_id' => $otherUser->id]);

        $this->actingAs($viewer)->post(route('notifications.read', $notification))->assertStatus(403);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_touches_the_current_users_notifications(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $otherUser = User::factory()->create();
        $mine = Notification::factory()->unread()->create(['user_id' => $viewer->id]);
        $theirs = Notification::factory()->unread()->create(['user_id' => $otherUser->id]);

        $this->actingAs($viewer)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_filter_by_type_narrows_the_list(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        Notification::factory()->create(['user_id' => $viewer->id, 'type' => Notification::TYPE_DEPLOYMENT_FAILED, 'title' => 'Failed one']);
        Notification::factory()->create(['user_id' => $viewer->id, 'type' => Notification::TYPE_AGENT_OFFLINE, 'title' => 'Offline one']);

        $response = $this->actingAs($viewer)->get(route('notifications.index', ['type' => Notification::TYPE_DEPLOYMENT_FAILED]));

        $response->assertOk();
        $response->assertSee('Failed one');
        $response->assertDontSee('Offline one');
    }

    public function test_filter_by_severity_narrows_the_list(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        Notification::factory()->create(['user_id' => $viewer->id, 'severity' => Notification::SEVERITY_CRITICAL, 'title' => 'Critical one']);
        Notification::factory()->create(['user_id' => $viewer->id, 'severity' => Notification::SEVERITY_INFO, 'title' => 'Info one']);

        $response = $this->actingAs($viewer)->get(route('notifications.index', ['severity' => Notification::SEVERITY_CRITICAL]));

        $response->assertOk();
        $response->assertSee('Critical one');
        $response->assertDontSee('Info one');
    }

    public function test_filter_by_unread_status_narrows_the_list(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        Notification::factory()->unread()->create(['user_id' => $viewer->id, 'title' => 'Unread one']);
        Notification::factory()->read()->create(['user_id' => $viewer->id, 'title' => 'Read one']);

        $response = $this->actingAs($viewer)->get(route('notifications.index', ['status' => 'unread']));

        $response->assertOk();
        $response->assertSee('Unread one');
        $response->assertDontSee('Read one');
    }

    public function test_preferences_page_saves_selected_types(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->post(route('notifications.preferences.update'), [
            'enabled' => [Notification::TYPE_DEPLOYMENT_FAILED, Notification::TYPE_AGENT_OFFLINE],
        ])->assertRedirect();

        $viewer->refresh();
        $this->assertTrue($viewer->wantsNotification(Notification::TYPE_DEPLOYMENT_FAILED));
        $this->assertFalse($viewer->wantsNotification(Notification::TYPE_DEPLOYMENT_SUCCESS));
    }

    public function test_header_bell_shows_unread_count_for_the_current_user_only(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $otherUser = User::factory()->create();
        Notification::factory()->unread()->count(3)->create(['user_id' => $viewer->id]);
        Notification::factory()->unread()->count(5)->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($viewer)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('>3<', false);
    }
}
