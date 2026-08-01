<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix: PublishJob's lease/heartbeat/publish-lock lifecycle —
 * the mechanism that lets deployments:recover-stale tell a slow-but-alive
 * publish apart from one whose claiming agent went silent. Covers
 * claimNext()'s atomicity/lock behavior and heartbeat()'s renewal/
 * late-callback rules; PublishJobServiceTest covers the pre-existing
 * approval state machine, StalePublishJobRecoveryServiceTest covers the
 * actual recovery sweep.
 */
class PublishJobLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_next_sets_a_lease_and_heartbeat(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $queued = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $claimed = $service->claimNext($agent);

        $this->assertNotNull($claimed);
        $this->assertSame($queued->id, $claimed->id);
        $this->assertSame(PublishJob::STATUS_RUNNING, $claimed->status);
        $this->assertNotNull($claimed->heartbeat_at);
        $this->assertNotNull($claimed->lease_expires_at);
        $this->assertTrue($claimed->lease_expires_at->isFuture());
    }

    public function test_claim_next_acquires_a_publish_lock_for_a_publish_action(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);

        app(PublishJobService::class)->claimNext($agent);

        $project->refresh();
        $this->assertSame($job->id, $project->locked_by_publish_job_id);
        $this->assertSame($agent->id, $project->locked_by_local_agent_id);
        $this->assertTrue($project->isPublishLocked());
    }

    public function test_claim_next_does_not_lock_the_project_for_a_status_check(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_QUEUED,
        ]);

        app(PublishJobService::class)->claimNext($agent);

        $this->assertFalse($project->fresh()->isPublishLocked());
    }

    /**
     * The actual concurrency scenario this exists for: a Project mapped
     * to two different developers' machines, both trying to publish at
     * once.
     */
    public function test_claim_next_skips_a_publish_job_whose_project_is_locked_by_another_agent(): void
    {
        $project = Project::factory()->create();
        $agentA = LocalAgent::factory()->approved()->create();
        $agentB = LocalAgent::factory()->approved()->create();

        $jobA = PublishJob::factory()->create([
            'local_agent_id' => $agentA->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);
        $jobB = PublishJob::factory()->create([
            'local_agent_id' => $agentB->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);

        $service = app(PublishJobService::class);
        $service->claimNext($agentA); // locks the project for jobA

        $claimedForB = $service->claimNext($agentB);

        $this->assertNull($claimedForB);
        $this->assertSame(PublishJob::STATUS_APPROVED, $jobB->fresh()->status);
    }

    public function test_claim_next_is_atomic_against_a_concurrent_double_claim(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'status' => PublishJob::STATUS_QUEUED,
        ]);

        $service = app(PublishJobService::class);
        $first = $service->claimNext($agent);
        $second = $service->claimNext($agent);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a job already running must never be claimed twice');
    }

    public function test_heartbeat_renews_the_lease_and_the_publish_lock(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);
        $service = app(PublishJobService::class);
        $claimed = $service->claimNext($agent);
        $originalLeaseExpiry = $claimed->lease_expires_at;
        $originalLockExpiry = $project->fresh()->lock_expires_at;

        sleep(1);
        $service->heartbeat($claimed, $agent);

        $this->assertTrue($claimed->fresh()->lease_expires_at->gt($originalLeaseExpiry));
        $this->assertTrue($project->fresh()->lock_expires_at->gt($originalLockExpiry));
    }

    public function test_heartbeat_from_a_different_agent_is_rejected(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $otherAgent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'status' => PublishJob::STATUS_RUNNING]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(PublishJobService::class)->heartbeat($job, $otherAgent);
    }

    public function test_heartbeat_on_a_terminal_job_is_a_no_op_and_recorded_as_a_late_callback(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'lease_expires_at' => null,
        ]);

        $result = app(PublishJobService::class)->heartbeat($job, $agent);

        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $result->status);
        $this->assertNull($result->lease_expires_at);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.late_callback_rejected',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_report_result_on_a_terminal_job_is_a_no_op(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_ABANDONED,
            'error_message' => 'original abandonment message',
        ]);

        $result = app(PublishJobService::class)->reportResult($job, 'abc123', 'late output');

        $this->assertSame(PublishJob::STATUS_ABANDONED, $result->status);
        $this->assertSame('original abandonment message', $result->fresh()->error_message);
    }

    public function test_report_failure_with_process_timeout_code_sets_timed_out_status(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'status' => PublishJob::STATUS_RUNNING]);

        $updated = app(PublishJobService::class)->reportFailure($job, 'exceeded timeout', null, PublishJob::FAILURE_CODE_PROCESS_TIMEOUT);

        $this->assertSame(PublishJob::STATUS_TIMED_OUT, $updated->status);
        $this->assertSame(PublishJob::FAILURE_CODE_PROCESS_TIMEOUT, $updated->failure_code);
    }

    public function test_report_failure_without_a_failure_code_sets_plain_failed_status(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'status' => PublishJob::STATUS_RUNNING]);

        $updated = app(PublishJobService::class)->reportFailure($job, 'merge conflict');

        $this->assertSame(PublishJob::STATUS_FAILED, $updated->status);
        $this->assertNull($updated->failure_code);
    }

    public function test_report_result_releases_the_publish_lock(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);
        $service = app(PublishJobService::class);
        $service->claimNext($agent);
        $this->assertTrue($project->fresh()->isPublishLocked());

        $service->reportResult($job->fresh(), 'abc123', 'ok');

        $this->assertFalse($project->fresh()->isPublishLocked());
    }

    public function test_mark_abandoned_releases_the_lock_and_is_rejected_for_a_terminal_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_APPROVED,
        ]);
        $service = app(PublishJobService::class);
        $service->claimNext($agent);

        $abandoned = $service->markAbandoned($job->fresh(), PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED, 'no heartbeat');

        $this->assertSame(PublishJob::STATUS_ABANDONED, $abandoned->status);
        $this->assertFalse($project->fresh()->isPublishLocked());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->markAbandoned($abandoned, PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED, 'again');
    }

    public function test_is_lease_expired_is_false_for_a_non_running_job(): void
    {
        $job = PublishJob::factory()->make(['status' => PublishJob::STATUS_QUEUED, 'lease_expires_at' => now()->subMinute()]);

        $this->assertFalse($job->isLeaseExpired());
    }

    public function test_is_lease_expired_is_true_for_a_running_job_with_no_lease_at_all(): void
    {
        $job = PublishJob::factory()->make(['status' => PublishJob::STATUS_RUNNING, 'lease_expires_at' => null]);

        $this->assertTrue($job->isLeaseExpired());
    }
}
