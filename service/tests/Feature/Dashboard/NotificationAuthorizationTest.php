<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-J Task 3 (hardening): before this, NotificationController only
 * checked "is this row mine" — a Developer/Operator/Viewer whose
 * ProjectMember row was later removed could still read the title/message of
 * a Notification about a Deployment in that Project (only the click-through
 * link's own Gate check would fail). These tests exercise
 * NotificationVisibilityFilter through the real /notifications route,
 * reusing the same ProjectPolicy + PermissionMatrix machinery
 * ProjectResourceAuthorizationTest already relies on — no new Policy.
 */
class NotificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_list_hides_a_deployment_notification_after_project_membership_is_revoked(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = Project::factory()->create();
        $member = ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $developer->id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);

        $notification = Notification::factory()->create([
            'user_id' => $developer->id,
            'type' => Notification::TYPE_DEPLOYMENT_FAILED,
            'title' => 'Deployment Failed #125',
            'related_type' => Deployment::class,
            'related_id' => $deployment->id,
        ]);

        // Sanity check: still a member, the notification is visible.
        $this->actingAs($developer)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Deployment Failed #125');

        $member->delete();

        $this->actingAs($developer)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Deployment Failed #125');

        // The row itself is untouched — this is a display-time filter, not
        // a deletion.
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_admin_always_sees_deployment_notifications_regardless_of_project_membership(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);

        Notification::factory()->create([
            'user_id' => $admin->id,
            'title' => 'Admin visible notification',
            'related_type' => Deployment::class,
            'related_id' => $deployment->id,
        ]);

        $this->assertSame(0, $project->members()->count());

        $this->actingAs($admin)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Admin visible notification');
    }

    public function test_notification_list_shows_deployment_notification_while_project_membership_exists(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $operator->id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);

        Notification::factory()->create([
            'user_id' => $operator->id,
            'title' => 'Still a member',
            'related_type' => Deployment::class,
            'related_id' => $deployment->id,
        ]);

        $this->actingAs($operator)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Still a member');
    }

    /**
     * A notification with no related resource (related_type/related_id both
     * null) has nothing to authorize against — it must always display,
     * per Task 3's own explicit rule.
     */
    public function test_personal_notification_without_a_related_resource_is_always_shown(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        Notification::factory()->create([
            'user_id' => $viewer->id,
            'title' => 'Purely personal notice',
            'related_type' => null,
            'related_id' => null,
        ]);

        $this->actingAs($viewer)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Purely personal notice');
    }
}
