<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Services\Publish\LocalAgentControlJobService;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 5.3: the agent-facing side of remote control — same signed
 * channel as every other Local Agent endpoint, mirrors
 * PublishJobFlowTest/OperationJobFlowTest's structure.
 */
class LocalAgentControlJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    public function test_agent_claims_a_queued_restart_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next');
        $response = $this->get('/api/local-agent/control-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $response->assertJsonPath('job.action', LocalAgentControlJob::ACTION_RESTART);
        $this->assertSame(LocalAgentControlJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_agent_reports_a_successful_fetch_logs_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');

        $body = ['status' => 'succeeded', 'output' => "[INFO] agent started\n[INFO] heartbeat ok"];
        $path = "/api/local-agent/control-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => LocalAgentControlJob::STATUS_SUCCEEDED]);

        $job->refresh();
        $this->assertSame(LocalAgentControlJob::STATUS_SUCCEEDED, $job->status);
        $this->assertStringContainsString('agent started', $job->output);
    }

    public function test_agent_reports_a_failed_restart_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $body = ['status' => 'failed', 'error_message' => 'could not exit cleanly'];
        $path = "/api/local-agent/control-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => LocalAgentControlJob::STATUS_FAILED]);

        $this->assertSame(LocalAgentControlJob::STATUS_FAILED, $job->fresh()->status);
    }

    public function test_agent_cannot_report_on_another_agents_control_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $otherAgent = LocalAgent::factory()->approved()->create();

        $body = ['status' => 'succeeded'];
        $path = "/api/local-agent/control-jobs/{$job->public_id}/report";
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $otherAgent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(403);
    }

    public function test_agent_claims_and_reports_a_reload_config_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RELOAD_CONFIG, 'admin@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next');
        $response = $this->get('/api/local-agent/control-jobs/next', $headers)->assertOk();
        $response->assertJsonPath('job.action', LocalAgentControlJob::ACTION_RELOAD_CONFIG);

        $body = ['status' => 'succeeded', 'output' => 'Project configuration will be resynchronized now.'];
        $path = "/api/local-agent/control-jobs/{$job->public_id}/report";
        $reportHeaders = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $reportHeaders)->assertOk()->assertJson(['status' => LocalAgentControlJob::STATUS_SUCCEEDED]);
    }

    /**
     * Root-cause fix (Watchdog, section 9): the watchdog polls this
     * restart-scoped endpoint instead of next() so it can never claim a
     * fetch_logs/reload_config job it has no idea how to execute — which
     * would otherwise leave that job claimed-but-never-reported, the
     * exact "stuck running forever" failure mode this effort exists to
     * eliminate.
     */
    public function test_next_restart_only_claims_a_queued_restart_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next-restart');
        $response = $this->get('/api/local-agent/control-jobs/next-restart', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $this->assertSame(LocalAgentControlJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_next_restart_only_ignores_a_queued_fetch_logs_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $fetchLogsJob = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next-restart');
        $response = $this->get('/api/local-agent/control-jobs/next-restart', $headers)->assertOk();

        $response->assertJson(['job' => null]);
        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $fetchLogsJob->fresh()->status);
    }

    public function test_next_restart_only_ignores_a_queued_reload_config_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $reloadJob = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RELOAD_CONFIG, 'admin@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next-restart');
        $response = $this->get('/api/local-agent/control-jobs/next-restart', $headers)->assertOk();

        $response->assertJson(['job' => null]);
        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $reloadJob->fresh()->status);
    }

    /**
     * claimNext()'s query is scoped by local_agent_id, so this isn't a
     * 403/permission rejection — a job belonging to another agent simply
     * never appears in the candidate set at all. Confirms that isolation
     * holds specifically for the watchdog's restart-scoped endpoint, not
     * just the general next() one.
     */
    public function test_next_restart_only_never_claims_another_agents_restart_job(): void
    {
        $otherAgent = LocalAgent::factory()->approved()->create();
        app(LocalAgentControlJobService::class)->queue($otherAgent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $agent = LocalAgent::factory()->approved()->create();

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next-restart');
        $response = $this->get('/api/local-agent/control-jobs/next-restart', $headers)->assertOk();

        $response->assertJson(['job' => null]);
    }

    public function test_next_returns_null_job_when_nothing_queued(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/control-jobs/next');

        $this->get('/api/local-agent/control-jobs/next', $headers)->assertOk()->assertJson(['job' => null]);
    }

    public function test_heartbeat_stores_agent_version_and_returns_latest_version(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['agent_version' => '1.0.0', 'hostname' => 'DEV-01', 'operating_system' => 'windows'];
        $headers = $this->headersFor($agent, 'POST', '/api/local-agent/heartbeat', json_encode($body));

        $response = $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk();
        $response->assertJsonStructure(['status', 'latest_version', 'project_config_version']);

        $this->assertSame('1.0.0', $agent->fresh()->agent_version);
    }

    public function test_heartbeat_reports_a_project_config_version_agents_use_to_detect_changed_mappings(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['agent_version' => '1.0.0'];
        $headers = $this->headersFor($agent, 'POST', '/api/local-agent/heartbeat', json_encode($body));

        $response = $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk();

        $this->assertSame($agent->projectConfigVersion(), $response->json('project_config_version'));
    }
}
