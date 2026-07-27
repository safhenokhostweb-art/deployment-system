<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 5: the PublishJob lifecycle over the signed Local Agent channel —
 * mirrors OperationJobFlowTest's structure.
 */
class PublishJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    public function test_agent_claims_a_queued_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $response = $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $response->assertJsonPath('job.action', PublishJob::ACTION_STATUS_CHECK);
        $response->assertJsonPath('job.project_key', $project->project_key);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_agent_reports_changed_files_for_a_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['changed_files' => ['app/Foo.php', 'README.md']];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_AWAITING_APPROVAL]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->status);
        $this->assertSame(['app/Foo.php', 'README.md'], $job->changed_files);
        $this->assertSame(2, $job->files_changed_count);
    }

    /**
     * Phase 5.5: the agent reports blocked_files alongside changed_files
     * (agent.php's status_check branch now sends both) — this is the
     * over-the-wire contract for the Publish page's "Blocked Files" list.
     */
    public function test_agent_reports_blocked_files_alongside_changed_files(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['changed_files' => ['app/Foo.php'], 'blocked_files' => ['.env', 'credentials.json']];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk();

        $job->refresh();
        $this->assertSame(['app/Foo.php'], $job->changed_files);
        $this->assertSame(['.env', 'credentials.json'], $job->blocked_files);
        $this->assertSame(1, $job->files_changed_count);
    }

    public function test_agent_can_report_failure_for_a_status_check_job(): void
    {
        // Regression: a status_check job that fails on the agent side
        // (e.g. `git status` itself errors) must go through the same
        // failure path as any other job, not be forced through the
        // success-only reportStatusCheck() validation.
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['status' => 'failed', 'error_message' => 'not a git repository'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_FAILED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_FAILED, $job->status);
        $this->assertSame('not a git repository', $job->error_message);
    }

    public function test_agent_claims_an_approved_publish_job_and_reports_success(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php']);
        app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');

        $nextHeaders = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $next = $this->get('/api/local-agent/publish-jobs/next', $nextHeaders)->assertOk();
        $next->assertJsonPath('job.action', PublishJob::ACTION_PUBLISH);
        $next->assertJsonPath('job.commit_message', 'Fix the widget');

        $reportBody = ['status' => 'succeeded', 'commit_hash' => str_repeat('a', 40), 'output' => 'main -> main'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $reportHeaders = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $reportHeaders)->assertOk()->assertJson(['status' => PublishJob::STATUS_SUCCEEDED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(str_repeat('a', 40), $job->result_commit_hash);
    }

    public function test_agent_claims_an_approved_rollback_job_and_reports_success(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');
        app(PublishJobService::class)->approveRollback($job, 'dev@example.test');

        $nextHeaders = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $this->get('/api/local-agent/publish-jobs/next', $nextHeaders)
            ->assertOk()
            ->assertJsonPath('job.action', PublishJob::ACTION_ROLLBACK)
            ->assertJsonPath('job.commit_message', null);

        $reportBody = ['status' => 'succeeded', 'commit_hash' => str_repeat('b', 40)];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $reportHeaders = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $reportHeaders)->assertOk()->assertJson(['status' => PublishJob::STATUS_SUCCEEDED]);
    }

    public function test_agent_reports_a_failed_publish(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php']);
        app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $reportBody = ['status' => 'failed', 'error_message' => 'git push rejected (non-fast-forward)'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_FAILED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('non-fast-forward', $job->error_message);
    }

    public function test_agent_cannot_report_on_another_agents_publish_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $otherAgent = LocalAgent::factory()->approved()->create();

        $body = ['changed_files' => []];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $otherAgent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(403);
    }

    public function test_heartbeat_updates_hostname_and_os(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['hostname' => 'DEV-09', 'operating_system' => 'linux'];
        $headers = $this->headersFor($agent, 'POST', '/api/local-agent/heartbeat', json_encode($body));

        $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk()->assertJson(['status' => 'ok']);

        $agent->refresh();
        $this->assertSame('DEV-09', $agent->hostname);
        $this->assertSame('linux', $agent->operating_system);
        $this->assertNotNull($agent->last_seen_at);
    }
}
