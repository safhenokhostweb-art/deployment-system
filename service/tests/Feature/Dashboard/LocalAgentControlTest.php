<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.3: the dashboard side of remote control (restart/fetch logs)
 * and credential rotation — same 'local_agents.manage' gate as
 * approve/disable (Phase 5), mirrors LocalAgentApprovalTest's style.
 */
class LocalAgentControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_a_restart(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.restart', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_RESTART,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
        ]);
    }

    public function test_admin_can_queue_a_fetch_logs_request(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.fetch-logs', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_FETCH_LOGS,
        ]);
    }

    public function test_admin_can_queue_an_exact_commit_linux_agent_upgrade(): void
    {
        $commit = $this->createUpgradeAuthority();
        config(['deployment.latest_local_agent_version' => '1.5.3']);
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.2',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);

        $this->actingAs($admin)->post(route('local-agents.upgrade', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_UPGRADE,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
            'expected_version' => '1.5.4',
            'expected_commit_hash' => $commit,
            'repository_mapping' => 'deployment-system',
        ]);
    }

    public function test_dashboard_refuses_remote_upgrade_before_linux_bootstrap(): void
    {
        config(['deployment.latest_local_agent_version' => '1.5.2']);
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.1',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);

        $this->actingAs($admin)->post(route('local-agents.upgrade', $agent))->assertStatus(409);
    }

    public function test_admin_can_force_a_config_reload(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($admin)->post(route('local-agents.reload-config', $agent))
            ->assertRedirect(route('local-agents.show', $agent));

        $this->assertDatabaseHas('local_agent_control_jobs', [
            'local_agent_id' => $agent->id,
            'action' => LocalAgentControlJob::ACTION_RELOAD_CONFIG,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
        ]);
    }

    public function test_cannot_force_a_config_reload_on_a_non_approved_agent(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $this->actingAs($admin)->post(route('local-agents.reload-config', $agent))->assertStatus(409);
    }

    public function test_developer_cannot_force_a_config_reload(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($developer)->post(route('local-agents.reload-config', $agent))->assertStatus(403);
    }

    public function test_cannot_restart_a_non_approved_agent(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->pendingRegistration()->create();

        $this->actingAs($admin)->post(route('local-agents.restart', $agent))->assertStatus(409);
    }

    public function test_developer_cannot_restart_or_fetch_logs(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $agent = LocalAgent::factory()->approved()->create();

        $this->actingAs($developer)->post(route('local-agents.restart', $agent))->assertStatus(403);
        $this->actingAs($developer)->post(route('local-agents.fetch-logs', $agent))->assertStatus(403);
    }

    public function test_admin_can_rotate_credentials(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $oldHash = $agent->agent_token_hash;

        $response = $this->actingAs($admin)->post(route('local-agents.rotate-token', $agent));

        $response->assertRedirect(route('local-agents.show', $agent));
        $response->assertSessionHas('generated_token');
        $response->assertSessionHas('generated_secret');

        $this->assertNotSame($oldHash, $agent->fresh()->agent_token_hash);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'local_agent.token_rotated',
            'subject_type' => LocalAgent::class,
            'subject_id' => $agent->id,
        ]);
    }

    public function test_show_page_renders_version_and_control_buttons(): void
    {
        $admin = User::factory()->create();
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.0.0']);

        $this->actingAs($admin)->get(route('local-agents.show', $agent))
            ->assertOk()
            ->assertSee('1.0.0');
    }

    public function test_show_page_renders_update_available_badge(): void
    {
        $admin = User::factory()->create();
        config(['deployment.latest_local_agent_version' => '9.9.9']);
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.0.0']);

        $this->actingAs($admin)->get(route('local-agents.show', $agent))
            ->assertOk()
            ->assertSee('9.9.9');
    }

    /**
     * Unlike the test above (which overrides config to prove the badge
     * itself renders), this uses the REAL, un-overridden
     * config('deployment.latest_local_agent_version') default — the exact
     * mechanism that silently stopped working across two prior release
     * phases because LOCAL_AGENT_VERSION was bumped without also bumping
     * this default (see LocalAgentVersionSyncTest for the regression
     * guard on that specific drift). A real agent still reporting the
     * previous release's version must be flagged as outdated against
     * today's actual default, with no config() override in this test.
     */
    public function test_an_agent_reporting_the_previous_release_version_is_flagged_outdated_against_the_real_default(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.2.0']);

        $this->assertTrue($agent->isUpdateAvailable());
    }

    public function test_an_agent_reporting_the_current_release_version_is_not_flagged_outdated(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => config('deployment.latest_local_agent_version')]);

        $this->assertFalse($agent->isUpdateAvailable());
    }

    /**
     * The exact Deployment #177 scenario: an installed Agent still on the
     * previous official release (1.5.2) must be flagged outdated against
     * the real, un-overridden canonical default (1.5.4) — proving the
     * fix (LocalAgentVersionResolver, see LocalAgentVersionSyncTest) makes
     * the "Upgrade to 1.5.4" action actually surface, with no env override
     * and no manual config edit in this test.
     */
    public function test_installed_previous_official_release_agent_is_outdated_against_the_real_canonical_default(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.5.2']);

        $this->assertSame('1.5.4', config('deployment.latest_local_agent_version'));
        $this->assertTrue($agent->isUpdateAvailable());
    }

    private function createUpgradeAuthority(): string
    {
        $commit = str_repeat('a', 40);
        $project = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'master']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'master',
            'finished_at' => now(),
        ]);

        return $commit;
    }
}
