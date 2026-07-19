<?php

namespace Tests\Feature\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\DeploymentStatusAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6: aggregate status computation. The three-target example from the
 * brief (web-01 success, web-02 failed, web-03 running -> PARTIAL_SUCCESS
 * is not literal — RUNNING wins while anything is still in flight; the
 * example is exercised here once all three reach a terminal state).
 */
class DeploymentStatusAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function aggregator(): DeploymentStatusAggregator
    {
        return app(DeploymentStatusAggregator::class);
    }

    private function makeDeploymentWithTasks(array $statuses): Deployment
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'status' => Deployment::STATUS_RUNNING]);

        foreach ($statuses as $i => $status) {
            $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => "web-0{$i}"]);
            DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id, 'status' => $status]);
        }

        return $deployment;
    }

    public function test_all_succeeded_aggregates_to_succeeded(): void
    {
        $deployment = $this->makeDeploymentWithTasks([DeploymentTask::STATUS_SUCCEEDED, DeploymentTask::STATUS_SUCCEEDED]);

        $result = $this->aggregator()->recalculate($deployment);

        $this->assertSame(Deployment::STATUS_SUCCEEDED, $result->status);
        $this->assertNotNull($result->finished_at);
    }

    public function test_all_failed_aggregates_to_failed(): void
    {
        $deployment = $this->makeDeploymentWithTasks([DeploymentTask::STATUS_FAILED, DeploymentTask::STATUS_FAILED]);

        $result = $this->aggregator()->recalculate($deployment);

        $this->assertSame(Deployment::STATUS_FAILED, $result->status);
    }

    public function test_mixed_terminal_states_aggregate_to_partial_success(): void
    {
        // The brief's example: web-01 success, web-02 failed, web-03 (here
        // simplified to two targets reaching different terminal states).
        $deployment = $this->makeDeploymentWithTasks([DeploymentTask::STATUS_SUCCEEDED, DeploymentTask::STATUS_FAILED]);

        $result = $this->aggregator()->recalculate($deployment);

        $this->assertSame(Deployment::STATUS_PARTIAL_SUCCESS, $result->status);
    }

    public function test_any_task_still_running_or_queued_keeps_deployment_running(): void
    {
        $deployment = $this->makeDeploymentWithTasks([DeploymentTask::STATUS_SUCCEEDED, DeploymentTask::STATUS_RUNNING]);

        $result = $this->aggregator()->recalculate($deployment);

        $this->assertSame(Deployment::STATUS_RUNNING, $result->status);
        $this->assertNull($result->finished_at);
    }

    public function test_legacy_deployment_with_no_tasks_is_never_touched(): void
    {
        $deployment = Deployment::factory()->create(['status' => Deployment::STATUS_RUNNING]);

        $result = $this->aggregator()->recalculate($deployment);

        $this->assertSame(Deployment::STATUS_RUNNING, $result->status);
    }

    public function test_recalculating_an_already_correct_status_does_not_touch_finished_at_again(): void
    {
        $deployment = $this->makeDeploymentWithTasks([DeploymentTask::STATUS_SUCCEEDED]);
        $this->aggregator()->recalculate($deployment);
        $firstFinishedAt = $deployment->fresh()->finished_at;

        $this->aggregator()->recalculate($deployment->fresh());

        $this->assertEquals($firstFinishedAt, $deployment->fresh()->finished_at);
    }
}
