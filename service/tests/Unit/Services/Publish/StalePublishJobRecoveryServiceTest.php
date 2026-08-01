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

    public function test_records_an_audit_log_entry_for_each_recovered_job(): void
    {
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
        ]);

        app(StalePublishJobRecoveryService::class)->recover();

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.abandoned',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
    }
}
