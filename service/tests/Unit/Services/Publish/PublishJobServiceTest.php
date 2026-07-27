<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5: PublishJob's state-machine guard rails — the rules that
 * protect it from ever committing/pushing something the operator never
 * actually approved. The HTTP-facing behavior these guards produce is
 * covered by PublishJobFlowTest/PublishWorkflowTest; this file targets
 * the service methods directly.
 */
class PublishJobServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_rejects_the_publish_action_directly(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_PUBLISH, 'dev@example.test');
    }

    public function test_approve_rejects_a_job_that_is_not_awaiting_approval(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve($job, 'message', 'dev@example.test'); // still queued, not awaiting_approval
    }

    public function test_approve_rejects_a_rollback_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve($job, 'message', 'dev@example.test');
    }

    public function test_approve_rollback_rejects_a_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approveRollback($job, 'dev@example.test');
    }

    public function test_approve_rollback_rejects_a_rollback_job_that_is_already_approved(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');
        $service->approveRollback($job, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approveRollback($job, 'dev@example.test'); // already approved, not queued
    }

    public function test_report_status_check_rejects_a_publish_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->reportStatusCheck($job, ['file.php']);
    }

    /**
     * Phase 5.5: blocked_files is purely informational (spec §3's "Blocked
     * Files" list) — it must never affect files_changed_count or the
     * approval gate, only be stored for display.
     */
    public function test_report_status_check_stores_blocked_files_alongside_changed_files(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $updated = $service->reportStatusCheck($job, ['app/Foo.php'], ['.env', 'credentials.json']);

        $this->assertSame(['app/Foo.php'], $updated->changed_files);
        $this->assertSame(['.env', 'credentials.json'], $updated->blocked_files);
        $this->assertSame(1, $updated->files_changed_count);
    }

    public function test_report_status_check_defaults_blocked_files_to_an_empty_array(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $updated = $service->reportStatusCheck($job, ['app/Foo.php']);

        $this->assertSame([], $updated->blocked_files);
    }
}
