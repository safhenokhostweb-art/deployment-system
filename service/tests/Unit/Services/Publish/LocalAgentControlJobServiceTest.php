<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Services\Publish\LocalAgentControlJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.3: LocalAgentControlJobService's queue/claim/report lifecycle
 * — mirrors OperationJobServiceTest's style (there is none dedicated;
 * this follows PublishJobServiceTest's precedent instead).
 */
class LocalAgentControlJobServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_rejects_an_unknown_action(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(LocalAgentControlJobService::class)->queue($agent, 'not-a-real-action', 'admin@example.test');
    }

    public function test_queue_creates_a_queued_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $job->status);
        $this->assertSame($agent->id, $job->local_agent_id);
        $this->assertSame('admin@example.test', $job->requested_by);
    }

    public function test_claim_next_returns_the_oldest_queued_job_for_that_agent_only(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $otherAgent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);

        $service->queue($otherAgent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');
        $first = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');
        $service->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $claimed = $service->claimNext($agent);

        $this->assertSame($first->id, $claimed->id);
        $this->assertSame(LocalAgentControlJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_claim_next_returns_null_when_nothing_is_queued(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $this->assertNull(app(LocalAgentControlJobService::class)->claimNext($agent));
    }

    public function test_report_result_marks_the_job_succeeded(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');
        $service->claimNext($agent);

        $updated = $service->reportResult($job, 'log line 1\nlog line 2');

        $this->assertSame(LocalAgentControlJob::STATUS_SUCCEEDED, $updated->status);
        $this->assertStringContainsString('log line 1', $updated->output);
    }

    public function test_report_failure_marks_the_job_failed(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');
        $service->claimNext($agent);

        $updated = $service->reportFailure($job, 'agent could not restart itself');

        $this->assertSame(LocalAgentControlJob::STATUS_FAILED, $updated->status);
        $this->assertSame('agent could not restart itself', $updated->error_message);
    }

    public function test_report_result_redacts_secrets_in_output(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');

        $updated = $service->reportResult($job, 'token=ghp_1234567890abcdef1234567890abcdef1234');

        $this->assertStringNotContainsString('ghp_1234567890abcdef1234567890abcdef1234', $updated->output);
        $this->assertStringContainsString('[REDACTED]', $updated->output);
    }
}
