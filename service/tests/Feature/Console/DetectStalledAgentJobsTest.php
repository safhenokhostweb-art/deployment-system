<?php

namespace Tests\Feature\Console;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7's "recovery for stuck deployments" / "compatibility with the
 * existing deployments:detect-stalled command", specifically for the new
 * domain. The pre-existing DetectStalledDeploymentsTest (legacy-only) is
 * untouched and still covers the original behavior.
 */
class DetectStalledAgentJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_a_stalled_agent_job_and_task_as_failed_and_releases_the_environment_lock(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30]);

        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);
        $agent = Agent::factory()->approved()->create(['server_id' => $target->server_id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $target->server_id]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_RUNNING,
        ]);
        $environment->update(['locked_by_deployment_id' => $deployment->id, 'locked_at' => now()->subHour()]);

        $stalledJob = AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 1,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(45),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(AgentJob::STATUS_FAILED, $stalledJob->fresh()->status);
        $this->assertSame(DeploymentTask::STATUS_FAILED, $task->fresh()->status);
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertNull($environment->fresh()->locked_by_deployment_id);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'agent_job.marked_stalled',
            'subject_id' => $stalledJob->id,
        ]);
    }

    public function test_a_healthy_agent_job_is_left_untouched(): void
    {
        config(['deployment.stalled_deployment_timeout_minutes' => 30]);

        $target = DeploymentTarget::factory()->create();
        $agent = Agent::factory()->approved()->create(['server_id' => $target->server_id]);
        $deployment = Deployment::factory()->create(['server_id' => $target->server_id]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_RUNNING,
        ]);
        $healthyJob = AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 1,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
        ]);

        $this->artisan('deployments:detect-stalled')->assertSuccessful();

        $this->assertSame(AgentJob::STATUS_RUNNING, $healthyJob->fresh()->status);
    }
}
