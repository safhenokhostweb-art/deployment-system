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
}
