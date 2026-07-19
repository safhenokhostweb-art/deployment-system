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
 * Task 7: Environment-scoped locking. Exercised directly against
 * AgentTaskService::claimNextTask (the actual gate), which is more
 * precise than going through HTTP for concurrency-shaped assertions.
 */
class EnvironmentLockingTest extends TestCase
{
    use RefreshDatabase;

    private function taskService(): AgentTaskService
    {
        return app(AgentTaskService::class);
    }

    private function makeEnvironmentWithTwoDeployments(): array
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);

        $agentA = Agent::factory()->approved()->create(['server_id' => $target->server_id]);
        $agentB = Agent::factory()->approved()->create(['server_id' => $target->server_id]);

        $deploymentA = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $target->server_id]);
        $taskA = DeploymentTask::create(['deployment_id' => $deploymentA->id, 'deployment_target_id' => $target->id]);

        // A second, independent Deployment competing for the same target —
        // two different DeploymentTask rows (different deployment_id) are
        // allowed to reference the same DeploymentTarget; the unique
        // constraint is per (deployment_id, deployment_target_id), not global.
        $deploymentB = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $target->server_id]);
        $taskB = DeploymentTask::create(['deployment_id' => $deploymentB->id, 'deployment_target_id' => $target->id]);

        return compact('project', 'environment', 'target', 'agentA', 'agentB', 'deploymentA', 'taskA', 'deploymentB', 'taskB');
    }

    public function test_claiming_the_first_task_locks_the_environment(): void
    {
        $ctx = $this->makeEnvironmentWithTwoDeployments();

        $this->taskService()->claimNextTask($ctx['agentA']);

        $environment = $ctx['environment']->fresh();
        $this->assertSame($ctx['deploymentA']->id, $environment->locked_by_deployment_id);
        $this->assertNotNull($environment->locked_at);
    }

    public function test_a_second_deployments_task_is_not_claimable_while_the_environment_is_locked(): void
    {
        $ctx = $this->makeEnvironmentWithTwoDeployments();

        $this->taskService()->claimNextTask($ctx['agentA']); // locks the environment for deploymentA

        // agentB polls the SAME server; taskB (deploymentB, a different
        // target within the same locked environment) must not be handed out.
        $claimed = $this->taskService()->claimNextTask($ctx['agentB']);

        $this->assertNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $ctx['taskB']->fresh()->status);
    }

    public function test_a_second_target_of_the_same_deployment_is_still_claimable(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target1 = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);
        $target2 = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-02']);

        $agent1 = Agent::factory()->approved()->create(['server_id' => $target1->server_id]);
        $agent2 = Agent::factory()->approved()->create(['server_id' => $target2->server_id]);

        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $target1->server_id]);
        DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target1->id]);
        DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target2->id]);

        $this->taskService()->claimNextTask($agent1);
        // Claiming target 2's task belongs to the SAME deployment that
        // already holds the lock — must not be blocked by its own lock.
        $claimed = $this->taskService()->claimNextTask($agent2);

        $this->assertNotNull($claimed);
    }

    public function test_lock_is_released_once_the_deployment_reaches_a_terminal_state(): void
    {
        $ctx = $this->makeEnvironmentWithTwoDeployments();

        $claimed = $this->taskService()->claimNextTask($ctx['agentA']);
        $agentJobId = $claimed['job']['id'];
        $agentJob = \App\Models\AgentJob::where('public_id', $agentJobId)->firstOrFail();

        $this->taskService()->updateStatus($agentJob, 'succeeded');

        $this->assertNull($ctx['environment']->fresh()->locked_by_deployment_id);

        // Now deploymentB's task becomes claimable.
        $claimedB = $this->taskService()->claimNextTask($ctx['agentB']);
        $this->assertNotNull($claimedB);
    }
}
