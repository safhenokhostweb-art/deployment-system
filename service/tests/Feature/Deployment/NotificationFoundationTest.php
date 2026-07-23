<?php

namespace Tests\Feature\Deployment;

use App\Events\DeploymentFailed;
use App\Events\DeploymentStarted;
use App\Events\DeploymentSucceeded;
use App\Events\RollbackCompleted;
use App\Events\RollbackStarted;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Task 7: notification foundation. No listener/provider exists yet — these
 * tests only confirm the events fire at the right moments, per Event::fake().
 */
class NotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_started_fires_when_a_legacy_manual_deployment_is_queued(): void
    {
        Event::fake([DeploymentStarted::class]);

        $project = Project::factory()->create();
        app(DeploymentDispatcher::class)->createManual($project, str_repeat('a', 40), 'admin@example.test');

        Event::assertDispatched(DeploymentStarted::class);
    }

    public function test_deployment_succeeded_fires_on_legacy_success(): void
    {
        Event::fake([DeploymentSucceeded::class]);

        $deployment = Deployment::factory()->create(['status' => Deployment::STATUS_RUNNING, 'action' => Deployment::ACTION_DEPLOY]);
        app(AgentJobService::class)->updateStatus($deployment, 'succeeded');

        Event::assertDispatched(DeploymentSucceeded::class, fn ($event) => $event->deployment->id === $deployment->id);
    }

    public function test_deployment_failed_fires_on_legacy_failure(): void
    {
        Event::fake([DeploymentFailed::class]);

        $deployment = Deployment::factory()->create(['status' => Deployment::STATUS_RUNNING]);
        app(AgentJobService::class)->updateStatus($deployment, 'failed', 'boom');

        Event::assertDispatched(DeploymentFailed::class);
    }

    public function test_rollback_completed_fires_instead_of_deployment_succeeded_for_a_rollback_action(): void
    {
        Event::fake([DeploymentSucceeded::class, RollbackCompleted::class]);

        $project = Project::factory()->create();
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'finished_at' => now()->subHour(),
        ]);
        $rollback = app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');
        $rollback->update(['status' => Deployment::STATUS_RUNNING]);

        app(AgentJobService::class)->updateStatus($rollback, 'succeeded');

        Event::assertDispatched(RollbackCompleted::class);
        Event::assertNotDispatched(DeploymentSucceeded::class);
    }

    /**
     * Phase 4-J Task 2 (completion): before this hardening,
     * failStalledLegacyDeployments() updated the row's status with no event
     * at all — a legacy deployment whose agent crashed mid-run produced no
     * notification, unlike an agent-reported failure. This is purely
     * additive instrumentation; the stall-detection logic itself (which
     * deployments qualify, what failure_message they get) is unchanged.
     */
    public function test_legacy_stalled_deployment_now_fires_deployment_failed(): void
    {
        Event::fake([DeploymentFailed::class]);

        $deployment = Deployment::factory()->create([
            'status' => Deployment::STATUS_RUNNING,
            'last_activity_at' => now()->subMinutes(60),
        ]);

        // Command::handle() calls $this->info(...), which needs the I/O
        // Artisan wires up when a command is actually run — resolving the
        // class and calling ->handle() directly leaves $this->output null.
        $this->artisan('deployments:detect-stalled')->assertExitCode(0);

        Event::assertDispatched(DeploymentFailed::class, fn ($event) => $event->deployment->id === $deployment->id);
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
    }

    /**
     * Task 2 (completion): RollbackService previously dispatched no event
     * whatsoever — a rollback being queued was invisible to the Notification
     * Center until it later succeeded or failed. Purely additive.
     */
    public function test_rollback_started_fires_when_a_rollback_is_queued(): void
    {
        Event::fake([RollbackStarted::class]);

        $project = Project::factory()->create();
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'finished_at' => now()->subHour(),
        ]);

        $rollback = app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');

        Event::assertDispatched(RollbackStarted::class, fn ($event) => $event->deployment->id === $rollback->id);
    }
}
