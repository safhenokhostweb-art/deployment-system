<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\StalePublishJobRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix: the shared recovery sweep behind both
 * deployments:recover-stale and the Dashboard's "بازیابی انتشارهای
 * معلق" button — must finalize exactly the stale rows, never a healthy
 * one, and must be safe to run repeatedly.
 */
class StalePublishJobRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovers_a_running_job_with_an_expired_lease_as_abandoned(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['last_seen_at' => now()->subMinutes(10)]);
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'heartbeat_at' => now()->subMinutes(3),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['abandoned']);
        $this->assertSame(0, $result['timed_out']);
        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->fresh()->status);
        $this->assertSame(PublishJob::FAILURE_CODE_AGENT_HEARTBEAT_LOST, $job->fresh()->failure_code);
    }

    public function test_uses_lease_expired_failure_code_when_the_agent_is_still_reporting_online(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['last_seen_at' => now()]);
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED, $job->fresh()->failure_code);
    }

    public function test_classifies_as_timed_out_once_the_max_runtime_ceiling_is_exceeded(): void
    {
        config(['deployment.publish_job_max_runtime_seconds' => 60]);
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5), // 300s > 60s ceiling
            'lease_expires_at' => now()->subMinute(),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(1, $result['timed_out']);
        $this->assertSame(0, $result['abandoned']);
        $this->assertSame(PublishJob::STATUS_TIMED_OUT, $job->fresh()->status);
        $this->assertSame(PublishJob::FAILURE_CODE_PROCESS_TIMEOUT, $job->fresh()->failure_code);
    }

    public function test_never_touches_a_running_job_whose_lease_has_not_expired(): void
    {
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_never_touches_a_job_that_is_not_running(): void
    {
        $job = PublishJob::factory()->create(['status' => PublishJob::STATUS_QUEUED]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(PublishJob::STATUS_QUEUED, $job->fresh()->status);
    }

    public function test_releases_the_publish_lock_held_by_a_recovered_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $project->update([
            'locked_by_publish_job_id' => $job->id,
            'locked_by_local_agent_id' => $agent->id,
            'locked_at' => now()->subMinutes(2),
            'lock_expires_at' => now()->subMinute(),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(1, $result['locks_released']);
        $this->assertNull($project->fresh()->locked_by_publish_job_id);
    }

    public function test_is_idempotent_when_run_twice_in_a_row(): void
    {
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $service = app(StalePublishJobRecoveryService::class);

        $first = $service->recover();
        $second = $service->recover();

        $this->assertSame(1, $first['abandoned']);
        $this->assertSame(0, $second['checked'], 'a second run must find nothing left to recover');
        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->fresh()->status);
    }

    public function test_dry_run_reports_counts_without_changing_anything(): void
    {
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover(true);

        $this->assertSame(1, $result['abandoned']);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status, 'dry-run must never mutate the job');
    }

    // ------------------------------------------------------------------
    // CASE 7: an awaiting_approval review older than the review TTL is
    // finalized by recovery.
    // ------------------------------------------------------------------

    public function test_expires_an_awaiting_approval_review_older_than_the_review_ttl(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subHours(2), // 120m old > 60m TTL
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['reviews_expired']);
        $this->assertSame(0, $result['abandoned']);
        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.review_expired',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_does_not_expire_an_awaiting_approval_review_within_the_review_ttl(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $job = PublishJob::factory()->create([
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subMinutes(30),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['reviews_expired']);
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->fresh()->status);
    }

    // ------------------------------------------------------------------
    // CASE 8: review expiry is idempotent when run twice.
    // ------------------------------------------------------------------

    public function test_review_expiry_is_idempotent_when_run_twice_in_a_row(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $job = PublishJob::factory()->create([
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subHours(2),
        ]);
        $service = app(StalePublishJobRecoveryService::class);

        $first = $service->recover();
        $second = $service->recover();

        $this->assertSame(1, $first['reviews_expired']);
        $this->assertSame(0, $second['checked'], 'a second run must find nothing left to recover');
        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
    }

    public function test_review_expiry_dry_run_reports_without_changing_anything(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $job = PublishJob::factory()->create([
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subHours(2),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover(true);

        $this->assertSame(1, $result['reviews_expired']);
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->fresh()->status, 'dry-run must never mutate the review');
    }

    // ------------------------------------------------------------------
    // CASE 10: expiring a review never touches the project publish lock.
    // ------------------------------------------------------------------

    public function test_expiring_a_review_never_touches_the_project_lock(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subHours(2),
        ]);

        app(StalePublishJobRecoveryService::class)->recover();

        $fresh = $project->fresh();
        $this->assertNull($fresh->locked_by_publish_job_id);
        $this->assertNull($fresh->locked_by_local_agent_id);
        $this->assertFalse($fresh->isPublishLocked());
    }

    // ------------------------------------------------------------------
    // CASE 14: running-job stale recovery still works alongside the new
    // review-expiry sweep.
    // ------------------------------------------------------------------

    public function test_running_job_recovery_still_works_alongside_review_expiry(): void
    {
        config(['deployment.publish_review_ttl_minutes' => 60]);
        $agent = LocalAgent::factory()->approved()->create();
        $running = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $expiredReview = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
            'finished_at' => now()->subHours(2),
        ]);

        $result = app(StalePublishJobRecoveryService::class)->recover();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['abandoned']);
        $this->assertSame(1, $result['reviews_expired']);
        $this->assertSame(PublishJob::STATUS_ABANDONED, $running->fresh()->status);
        $this->assertSame(PublishJob::STATUS_REJECTED, $expiredReview->fresh()->status);
    }
}
