<?php

namespace Tests\Feature\Deployment;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\AgentTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 3: retry policy. Default config (max_attempts=1) preserves
 * today's straight-to-failed behavior; these tests explicitly opt in via
 * config() to exercise the retry path itself.
 */
class TaskRetryTest extends TestCase
{
    use RefreshDatabase;

    private function taskService(): AgentTaskService
    {
        return app(AgentTaskService::class);
    }

    private function makeClaimableTask(): array
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);
        $agent = Agent::factory()->approved()->create(['server_id' => $target->server_id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $target->server_id]);
        $task = DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id]);

        return compact('agent', 'deployment', 'task');
    }

    public function test_default_config_fails_immediately_with_no_retry(): void
    {
        // task_max_attempts defaults to 1 — must match pre-Phase-3-D behavior exactly.
        $ctx = $this->makeClaimableTask();
        $claimed = $this->taskService()->claimNextTask($ctx['agent']);
        $agentJob = \App\Models\AgentJob::where('public_id', $claimed['job']['id'])->firstOrFail();

        $this->taskService()->updateStatus($agentJob, 'failed', 'boom');

        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::STATUS_FAILED, $ctx['deployment']->fresh()->status);
        $this->assertCount(1, $ctx['task']->fresh()->agentJobs);
    }

    public function test_opted_in_retry_schedules_a_new_attempt_instead_of_failing(): void
    {
        config(['deployment.task_max_attempts' => 3, 'deployment.task_retry_backoff_seconds' => [30, 120]]);
        $ctx = $this->makeClaimableTask();

        $claimed = $this->taskService()->claimNextTask($ctx['agent']);
        $agentJob = \App\Models\AgentJob::where('public_id', $claimed['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($agentJob, 'failed', 'attempt 1 failed');

        $task = $ctx['task']->fresh();
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $task->status);
        $this->assertNotNull($task->next_retry_at);
        $this->assertSame(Deployment::STATUS_QUEUED, $ctx['deployment']->fresh()->status); // aggregate untouched — not yet terminal

        // Not claimable yet — within the backoff window.
        $this->assertNull($this->taskService()->claimNextTask($ctx['agent']));

        // Once the backoff window passes, it's claimable again as a new attempt.
        $task->update(['next_retry_at' => now()->subMinute()]);
        $secondClaim = $this->taskService()->claimNextTask($ctx['agent']);
        $this->assertNotNull($secondClaim);

        $this->assertCount(2, $task->fresh()->agentJobs);
        $this->assertSame(2, $task->fresh()->agentJobs->last()->attempt);
    }

    public function test_full_example_two_failures_then_success_preserves_independent_history(): void
    {
        config(['deployment.task_max_attempts' => 3, 'deployment.task_retry_backoff_seconds' => [0, 0]]);
        $ctx = $this->makeClaimableTask();

        // Attempt 1: FAILED
        $claim1 = $this->taskService()->claimNextTask($ctx['agent']);
        $job1 = \App\Models\AgentJob::where('public_id', $claim1['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($job1, 'failed');

        // Attempt 2: FAILED
        $ctx['task']->update(['next_retry_at' => now()->subSecond()]);
        $claim2 = $this->taskService()->claimNextTask($ctx['agent']);
        $job2 = \App\Models\AgentJob::where('public_id', $claim2['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($job2, 'failed');

        // Attempt 3: SUCCESS
        $ctx['task']->update(['next_retry_at' => now()->subSecond()]);
        $claim3 = $this->taskService()->claimNextTask($ctx['agent']);
        $job3 = \App\Models\AgentJob::where('public_id', $claim3['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($job3, 'succeeded');

        $task = $ctx['task']->fresh();
        $this->assertSame(DeploymentTask::STATUS_SUCCEEDED, $task->status);
        $this->assertSame(Deployment::STATUS_SUCCEEDED, $ctx['deployment']->fresh()->status);

        $attempts = $task->agentJobs()->orderBy('attempt')->get();
        $this->assertCount(3, $attempts);
        $this->assertSame(['failed', 'failed', 'succeeded'], $attempts->pluck('status')->all());
        $this->assertSame([1, 2, 3], $attempts->pluck('attempt')->all());
    }
}
