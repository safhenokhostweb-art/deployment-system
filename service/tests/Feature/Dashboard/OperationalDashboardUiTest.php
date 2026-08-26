<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDashboardUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_presents_operational_sections_from_existing_controller_data(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        Agent::factory()->approved()->create([
            'server_id' => $project->server_id,
            'name' => 'prod-runtime-01',
            'agent_version' => config('deployment.min_server_agent_version_for_artifact'),
            'last_seen_at' => now(),
        ]);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'started_at' => now()->subSeconds(75),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee(__('dashboard_ui.system_health_title'));
        $response->assertSee(__('dashboard_ui.deployment_overview_title'));
        $response->assertSee(__('dashboard_ui.agent_fleet_title'));
        $response->assertSee(__('dashboard_ui.artifact_overview_title'));
        $response->assertSee(__('dashboard_ui.attention_title'));
        $response->assertSee(__('dashboard_ui.recent_timeline_title'));
        $response->assertSee('prod-runtime-01');
        $response->assertSee('data-domain="deployment"', false);
        $response->assertSee('data-health-status="not_configured"', false);
        $response->assertSee('ui-dashboard-stat-grid', false);
    }

    public function test_dashboard_marks_telemetry_gaps_instead_of_claiming_unknown_health(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee(__('dashboard_ui.hub_status_note'));
        $response->assertSee(__('dashboard_ui.queue_status_note'));
        $response->assertSee(__('dashboard_ui.failed_jobs_note'));
        $response->assertSee(__('dashboard_ui.artifact_inventory_unavailable_title'));
        $response->assertSee(__('dashboard_ui.production_build_agents_unavailable'));
        $response->assertSee(__('dashboard_ui.attention_clear_scope'));
    }

    public function test_dashboard_attention_panel_contains_only_existing_actionable_signals(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['requires_complete_artifact' => false]);
        Agent::factory()->approved()->create([
            'server_id' => $project->server_id,
            'name' => 'offline-prod-agent',
            'last_seen_at' => now()->subDay(),
        ]);
        $failed = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_FAILED,
            'failure_code' => Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED,
            'failure_message' => 'HTTP verification returned 500.',
            'finished_at' => now(),
            'artifact_id' => null,
        ]);
        $project->update(['requires_complete_artifact' => true]);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('offline-prod-agent');
        $response->assertSee('HTTP verification returned 500.');
        $response->assertSee(__('dashboard_ui.required_artifact_not_linked'));
        $response->assertSee(route('deployments.show', $failed), false);
        $response->assertSee('ui-platform-verdict--danger', false);
        $response->assertSee('data-status="missing"', false);
        $response->assertDontSee(__('dashboard_ui.verdict_clear'));
    }
}
