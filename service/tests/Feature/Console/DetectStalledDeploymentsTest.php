<?php

namespace Tests\Feature\Console;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectStalledDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_a_running_deployment_with_no_recent_activity_as_failed(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30]);
        $project = Project::factory()->create();

        $stalled = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_RUNNING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(45),
        ]);

        $healthy = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_RUNNING,
            'commit_hash' => str_repeat('b', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinutes(1),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_FAILED, $stalled->fresh()->status);
        $this->assertNotNull($stalled->fresh()->failure_message);
        $this->assertSame(Deployment::STATUS_RUNNING, $healthy->fresh()->status);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment.marked_stalled',
            'subject_id' => $stalled->id,
        ]);
    }

    /**
     * Root-cause fix (sandogh deployment overhaul, Phase 2): a
     * recipe-driven deployment can be mid `building`/`migrating`/
     * `activating`/`verifying` — none of these existed before this
     * phase, and a deployment stuck in one must be caught exactly like
     * one stuck in `running` always was.
     */
    public function test_marks_a_deployment_stalled_in_an_intermediate_recipe_phase(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30]);
        $project = Project::factory()->create();

        $stalled = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_MIGRATING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(45),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_FAILED, $stalled->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_HEARTBEAT_LOST, $stalled->fresh()->failure_code);
    }

    /**
     * A deployment claimed through the new lease-aware AgentJobService
     * has lease_expires_at set — that expiry (not last_activity_at) is
     * what decides staleness for it, even if last_activity_at happens to
     * look recent (e.g. a log line arrived just before the agent died).
     */
    public function test_marks_a_deployment_with_an_expired_lease_as_stalled_even_with_recent_last_activity(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30]);
        $project = Project::factory()->create();

        $stalled = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_BUILDING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subSeconds(30),
            'heartbeat_at' => now()->subMinutes(4),
            'lease_expires_at' => now()->subSeconds(10),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_FAILED, $stalled->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_LEASE_EXPIRED, $stalled->fresh()->failure_code);
    }

    /**
     * A leased deployment whose lease hasn't expired yet must survive
     * the sweep even if last_activity_at looks stale by the old
     * timeout-minutes rule — the lease is the authoritative signal once
     * one has been issued.
     */
    public function test_a_leased_deployment_with_an_unexpired_lease_is_not_marked_stalled(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 5]);
        $project = Project::factory()->create();

        $healthy = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_BUILDING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subMinutes(20),
            'last_activity_at' => now()->subMinutes(20),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes(3),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_BUILDING, $healthy->fresh()->status);
    }

    /**
     * Independent of heartbeat/lease health: a deployment that has
     * simply run far longer than any real deploy reasonably should is
     * force-ended — the same defense-in-depth
     * StalePublishJobRecoveryService applies for PublishJob.
     */
    public function test_marks_a_deployment_exceeding_the_max_runtime_ceiling_even_with_a_fresh_lease(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30, 'deployment.deployment_job_max_runtime_seconds' => 3600]);
        $project = Project::factory()->create();

        $runaway = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_VERIFYING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'started_at' => now()->subHours(2),
            'last_activity_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes(3),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_FAILED, $runaway->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_MAX_RUNTIME_EXCEEDED, $runaway->fresh()->failure_code);
    }
}
