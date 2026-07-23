<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4-C: Dashboard operations-center redesign. Covers the
 * DashboardService projections directly (precise, fast) plus a handful of
 * page-level checks for empty states, secret-leak prevention, quick-action
 * links, and query-count boundedness (the N+1 guard Task 9 asked for).
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DashboardService
    {
        return app(DashboardService::class);
    }

    /**
     * Deployment::factory() unconditionally creates its own throwaway
     * Project (which cascades into its own Server) inside definition()
     * before any override is applied — harmless for tests that don't
     * count rows, but it would silently inflate exact-count assertions
     * here. Deployment::create() (same pattern already used by
     * DeploymentApprovalTest) avoids that entirely.
     */
    private function createDeployment(int $projectId, int $serverId, string $status): Deployment
    {
        return Deployment::create([
            'project_id' => $projectId,
            'server_id' => $serverId,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => $status,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
        ]);
    }

    public function test_summary_cards_report_correct_counts_across_all_domains(): void
    {
        $onlineServer = Server::factory()->create(['last_seen_at' => now()]);
        $offlineServer = Server::factory()->create(['last_seen_at' => now()->subDays(2)]);

        Agent::factory()->approved()->create(['server_id' => $onlineServer->id]);
        Agent::factory()->create(['server_id' => $onlineServer->id, 'status' => Agent::STATUS_PENDING_APPROVAL]);
        Agent::factory()->create(['server_id' => $offlineServer->id, 'status' => Agent::STATUS_DISABLED]);

        $activeProject = Project::factory()->create(['server_id' => $onlineServer->id, 'is_active' => true]);
        $inactiveProject = Project::factory()->create(['server_id' => $offlineServer->id, 'is_active' => false]);

        $this->createDeployment($activeProject->id, $onlineServer->id, Deployment::STATUS_SUCCEEDED);
        $this->createDeployment($inactiveProject->id, $offlineServer->id, Deployment::STATUS_FAILED);
        $this->createDeployment($activeProject->id, $onlineServer->id, Deployment::STATUS_RUNNING);

        $summary = $this->service()->summaryCards();

        $this->assertSame(['total' => 2, 'online' => 1, 'offline' => 1], $summary['servers']);
        $this->assertSame(['total' => 3, 'approved' => 1, 'pending_approval' => 1, 'disabled' => 1], $summary['agents']);
        $this->assertSame(['total' => 2, 'active' => 1], $summary['projects']);
        $this->assertSame(['total' => 3, 'succeeded' => 1, 'failed' => 1, 'running' => 1], $summary['deployments']);
    }

    public function test_health_overview_flags_pending_and_offline_approved_agents(): void
    {
        Agent::factory()->create(['status' => Agent::STATUS_PENDING_APPROVAL]);
        Agent::factory()->approved()->create(['last_seen_at' => now()->subDays(1)]);
        Agent::factory()->approved()->create(['last_seen_at' => now()]);

        $problemAgents = $this->service()->healthOverview()['problemAgents'];

        $this->assertCount(2, $problemAgents);
        $this->assertEqualsCanonicalizing(
            ['pending_approval', 'offline'],
            $problemAgents->pluck('reason')->all()
        );
    }

    public function test_health_overview_flags_stalled_legacy_deployment(): void
    {
        Deployment::factory()->create([
            'status' => Deployment::STATUS_RUNNING,
            'last_activity_at' => now()->subMinutes(60),
        ]);

        $problemDeployments = $this->service()->healthOverview()['problemDeployments'];

        $this->assertTrue($problemDeployments->contains(fn ($row) => $row['reason'] === 'stalled'));
    }

    public function test_health_overview_flags_stalled_new_domain_task(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'status' => Deployment::STATUS_RUNNING]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_RUNNING,
        ]);
        AgentJob::factory()->create([
            'deployment_task_id' => $task->id,
            'status' => AgentJob::STATUS_RUNNING,
            'last_activity_at' => now()->subMinutes(60),
        ]);

        $problemDeployments = $this->service()->healthOverview()['problemDeployments'];

        $this->assertTrue($problemDeployments->contains(
            fn ($row) => $row['reason'] === 'stalled' && $row['deployment']->id === $deployment->id
        ));
    }

    public function test_server_overview_widget_distinguishes_legacy_and_agent_based_servers(): void
    {
        $legacyServer = Server::factory()->create();
        $agentServer = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $agentServer->id]);

        $overview = $this->service()->serverOverviewWidget()->keyBy(fn ($row) => $row['server']->id);

        $this->assertSame('legacy', $overview[$legacyServer->id]['type']);
        $this->assertSame('agent_based', $overview[$agentServer->id]['type']);
    }

    public function test_dashboard_shows_empty_states_with_no_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee(__('ui.dashboard.no_servers'));
        $response->assertSee(__('ui.dashboard.no_agents'));
        $response->assertSee(__('ui.dashboard.no_deployments'));
        $response->assertSee(__('ui.dashboard.health_all_clear'));
    }

    public function test_dashboard_renders_legacy_and_new_domain_deployments_side_by_side(): void
    {
        $user = User::factory()->create();

        $legacyProject = Project::factory()->create();
        Deployment::factory()->create(['project_id' => $legacyProject->id, 'status' => Deployment::STATUS_SUCCEEDED]);

        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'staging']);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'status' => Deployment::STATUS_SUCCEEDED]);
        DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_SUCCEEDED,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee($legacyProject->name);
        $response->assertSee($project->name);
        $response->assertSee('web-01');
    }

    public function test_dashboard_never_exposes_secrets_or_credentials(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['description' => 'has-a-secret-somewhere']);
        Agent::factory()->approved()->create(['server_id' => $server->id]);
        Project::factory()->create(['server_id' => $server->id, 'github_webhook_secret' => 'super-secret-webhook-value']);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertDontSee('super-secret-webhook-value');
        $response->assertDontSee($server->signing_secret ?? '__none__');
    }

    public function test_quick_actions_link_to_existing_routes_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee(route('projects.create'), false);
        $response->assertSee(route('servers.create'), false);
        $response->assertSee(route('agents.pending'), false);
        $response->assertSee('#recent-deployments', false);
    }

    public function test_dashboard_query_count_stays_bounded_regardless_of_row_volume(): void
    {
        $user = User::factory()->create();

        // 15 of everything — if any widget query were per-row (N+1), the
        // query count would scale with this instead of staying flat.
        Server::factory()->count(15)->create();
        Agent::factory()->approved()->count(15)->create();
        Project::factory()->count(15)->create()->each(function (Project $project) {
            Deployment::factory()->create(['project_id' => $project->id, 'status' => Deployment::STATUS_SUCCEEDED]);
        });

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('dashboard.index'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $queryCount, "Dashboard issued {$queryCount} queries for 15 rows per widget — looks like an N+1.");
    }
}
