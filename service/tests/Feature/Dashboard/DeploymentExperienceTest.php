<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Deployment\AgentJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-I Task 12: the Deployment Experience redesign — overview,
 * pipeline step visualization, timeline, live status, release labels,
 * rollback from/to, the new Recent Releases widget, and the Vazirmatn
 * font. Deliberately does not re-test what RolePermissionTest and
 * ProjectResourceAuthorizationTest already cover in depth (role/resource
 * authorization) — only enough of each to confirm this phase's new UI
 * respects the same, unmodified checks.
 */
class DeploymentExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_overview_shows_project_environment_target_commit_branch_trigger_and_status(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create(['environment' => 'production']);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'triggered_by' => 'admin@example.test',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertSee($project->name);
        $response->assertSee('production');
        $response->assertSee($project->server->name);
        $response->assertSee(substr($deployment->commit_hash, 0, 10));
        $response->assertSee('main');
        $response->assertSee('admin@example.test');
    }

    public function test_pipeline_steps_render_succeeded_running_and_pending_statuses(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create(['pipeline' => [
            ['name' => 'git pull', 'command' => 'echo pull'],
            ['name' => 'composer install', 'command' => 'echo install'],
            ['name' => 'optimize', 'command' => 'echo optimize'],
        ]]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_RUNNING,
            'started_at' => now()->subMinute(),
        ]);

        DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
            'sequence' => 1,
            'step_name' => 'git pull',
            'command' => 'echo pull',
            'output' => 'ok',
            'exit_code' => 0,
            'duration_ms' => 500,
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $deployment));

        $response->assertOk();
        // Step 1 succeeded, step 2 is the running one (next after the last
        // logged sequence), step 3 hasn't started.
        $response->assertSeeInOrder(['git pull', 'composer install', 'optimize']);
        $response->assertSee(__('ui.deployments.current_step'));
        $response->assertSee('composer install');
    }

    public function test_release_label_displayed_and_falls_back_to_commit_hash_when_absent(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();

        $labeled = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'release_label' => 'v1.2.0',
        ]);
        $unlabeled = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'release_label' => null,
        ]);

        $this->assertSame('v1.2.0', $labeled->displayLabel());
        $this->assertSame(substr($unlabeled->commit_hash, 0, 10), $unlabeled->displayLabel());

        $response = $this->actingAs($admin)->get(route('deployments.show', $labeled));
        $response->assertOk()->assertSee('v1.2.0');
    }

    public function test_manual_deploy_persists_optional_release_label_and_notes(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();

        $this->actingAs($admin)->post(route('projects.deploy', $project), [
            'commit_hash' => str_repeat('a', 40),
            'release_label' => 'v2.0.0',
            'release_notes' => 'Big refactor.',
        ])->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'release_label' => 'v2.0.0',
            'release_notes' => 'Big refactor.',
        ]);
    }

    public function test_manual_deploy_without_release_fields_still_works_exactly_as_before(): void
    {
        // Compatibility: a plain commit-only POST (every pre-4-I caller)
        // must still create a deployment with no release info at all.
        $admin = $this->admin();
        $project = Project::factory()->create();

        $this->actingAs($admin)->post(route('projects.deploy', $project), [
            'commit_hash' => str_repeat('b', 40),
        ])->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
            'release_label' => null,
            'release_notes' => null,
        ]);
    }

    public function test_rollback_dialog_shows_from_and_to_release_labels(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();

        $old = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'release_label' => 'v1.0.0',
            'finished_at' => now()->subDay(),
        ]);
        $current = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'release_label' => 'v1.1.0',
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $old));

        $response->assertOk();
        $response->assertSee('v1.0.0');
        $response->assertSee('v1.1.0');
        $response->assertSee(__('ui.deployments.rollback_from'));
        $response->assertSee(__('ui.deployments.rollback_to'));
    }

    public function test_dashboard_shows_only_succeeded_deployments_as_recent_releases(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();

        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'status' => Deployment::STATUS_SUCCEEDED, 'release_label' => 'v3.0.0']);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'status' => Deployment::STATUS_FAILED, 'release_label' => 'v-failed']);

        $response = $this->actingAs($admin)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee(__('ui.dashboard.recent_releases_title'));
        $response->assertSee('v3.0.0');
        $response->assertDontSee('v-failed');
    }

    public function test_redacted_secret_never_appears_in_the_live_status_last_log_line(): void
    {
        $admin = $this->admin();
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        // Goes through the real AgentJobService::recordLog() — the same
        // write-time SecretRedactor path a real agent report takes —
        // rather than inserting an already-clean row directly, so this
        // proves the redaction this phase's new UI relies on still runs.
        app(AgentJobService::class)->recordLog($deployment, [
            'sequence' => 1,
            'step_name' => 'deploy',
            'command' => 'export API_KEY=sk_live_should_never_render',
            'output' => 'token=sk_live_should_never_render done',
            'exit_code' => 1,
        ]);

        $response = $this->actingAs($admin)->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertDontSee('sk_live_should_never_render');
    }

    public function test_font_face_and_asset_are_referenced_on_an_authenticated_page(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('Vazirmatn', false);
        $response->assertSee('/fonts/vazirmatn/Vazirmatn-Regular.woff2', false);
    }

    public function test_non_member_cannot_view_a_deployment_by_direct_url(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);
        // Deliberately no ProjectMember row for $developer.

        $this->actingAs($developer)->get(route('deployments.show', $deployment))->assertForbidden();
    }

    public function test_project_member_can_view_the_deployment(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = Project::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $developer->id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);

        $this->actingAs($developer)->get(route('deployments.show', $deployment))->assertOk();
    }

    public function test_deployment_task_page_renders_pipeline_steps_for_the_latest_attempt(): void
    {
        // Compatibility + Task 4/5: the new-domain (multi-target) path's own
        // detail page must render the same step visualization without
        // touching anything about how AgentJob/DeploymentTask work.
        $admin = $this->admin();
        $project = Project::factory()->create(['pipeline' => [
            ['name' => 'git pull', 'command' => 'echo pull'],
            ['name' => 'cache clear', 'command' => 'echo clear'],
        ]]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $project->server_id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_RUNNING,
        ]);
        $agent = Agent::factory()->approved()->create(['server_id' => $target->server_id]);
        $agentJob = AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 1,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);
        $agentJob->logEntries()->create([
            'sequence' => 1,
            'step_name' => 'git pull',
            'command' => 'echo pull',
            'output' => 'ok',
            'exit_code' => 0,
            'duration_ms' => 300,
        ]);

        $response = $this->actingAs($admin)->get(route('deployment-tasks.show', $task));

        $response->assertOk();
        $response->assertSeeInOrder(['git pull', 'cache clear']);
        $response->assertSee(__('ui.deployments.current_step'));
    }
}
