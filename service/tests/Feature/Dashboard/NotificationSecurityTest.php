<?php

namespace Tests\Feature\Dashboard;

use App\Events\AgentApprovalRequired;
use App\Events\AgentConnected;
use App\Events\CredentialRotated;
use App\Events\DeploymentFailed;
use App\Events\DeploymentStarted;
use App\Events\DeploymentSucceeded;
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
 * Phase 4-J Task 8: Notification content must never contain a
 * password/SSH key/token/secret/environment variable, for any event type —
 * not just CredentialRotated (already covered individually in
 * NotificationCenterTest). This sweeps every notification-producing event
 * in one pass and asserts none of the resulting rows match any forbidden
 * pattern, in either title or message.
 */
class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_PATTERN = '/password|secret|ssh[-_ ]?key|token|env(?:ironment)?[-_ ]?variable/i';

    public function test_no_notification_produced_by_any_event_ever_contains_a_forbidden_secret_pattern(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create(['name' => 'prod-db-1']);
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id, 'name' => 'db-agent']);
        $account = GitHubAccount::factory()->create(['account_login' => 'acme-org']);
        $target = User::factory()->create(['role' => User::ROLE_VIEWER, 'name' => 'Target User']);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);
        $rollback = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_ROLLBACK,
        ]);

        event(new CredentialRotated($server, $admin->email));
        event(new AgentApprovalRequired($agent));
        event(new AgentConnected($agent));
        event(new GitHubConnected($account, $admin->email));
        event(new PermissionChanged($target, User::ROLE_VIEWER, User::ROLE_OPERATOR, $admin->email));
        event(new DeploymentStarted($deployment));
        event(new DeploymentSucceeded($deployment));
        event(new DeploymentFailed($deployment, 'agent reported a non-zero exit code'));
        event(new RollbackStarted($rollback));
        event(new DeploymentFailed($rollback, 'agent reported a non-zero exit code'));

        $notifications = Notification::all();
        $this->assertGreaterThan(0, $notifications->count());

        foreach ($notifications as $notification) {
            $this->assertDoesNotMatchRegularExpression(
                self::FORBIDDEN_PATTERN,
                $notification->title,
                "Notification #{$notification->id} ({$notification->type}) title leaked a forbidden pattern: {$notification->title}"
            );
            $this->assertDoesNotMatchRegularExpression(
                self::FORBIDDEN_PATTERN,
                $notification->message,
                "Notification #{$notification->id} ({$notification->type}) message leaked a forbidden pattern: {$notification->message}"
            );
        }
    }

    /**
     * The literal bad/good example from the brief: a rotated credential's
     * actual secret value must never appear, only a human-readable
     * confirmation that a rotation happened.
     */
    public function test_credential_rotated_event_payload_itself_carries_no_secret_value(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create(['name' => 'prod-web-9', 'signing_secret' => 'super-secret-value-xyz']);

        event(new CredentialRotated($server, $admin->email));

        $notification = Notification::where('type', Notification::TYPE_CREDENTIAL_ROTATED)->firstOrFail();

        $this->assertStringNotContainsString('super-secret-value-xyz', $notification->message);
        $this->assertStringNotContainsString('super-secret-value-xyz', $notification->title);
        $this->assertStringContainsString('prod-web-9', $notification->message);
    }
}
