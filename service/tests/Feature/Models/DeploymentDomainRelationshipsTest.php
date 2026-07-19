<?php

namespace Tests\Feature\Models;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New Deployment Domain models (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md
 * §3) — purely additive, so these tests only ever create new-domain rows;
 * none of them touch legacy Project/Server/Deployment behavior.
 */
class DeploymentDomainRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_has_many_environments(): void
    {
        $project = Project::factory()->create();
        $env = Environment::factory()->create(['project_id' => $project->id, 'key' => 'production']);

        $this->assertTrue($project->environments->contains($env));
        $this->assertSame($project->id, $env->project->id);
    }

    public function test_environment_has_many_deployment_targets_referencing_a_server(): void
    {
        $environment = Environment::factory()->create();
        $server = \App\Models\Server::factory()->create();
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'name' => 'web-01',
        ]);

        $this->assertTrue($environment->deploymentTargets->contains($target));
        $this->assertSame($server->id, $target->server->id);
        $this->assertTrue($server->deploymentTargets->contains($target));
    }

    public function test_deployment_task_belongs_to_the_existing_deployment_model_not_a_new_one(): void
    {
        $deployment = Deployment::factory()->create();
        $target = DeploymentTarget::factory()->create();

        $task = DeploymentTask::factory()->create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
        ]);

        $this->assertInstanceOf(Deployment::class, $task->deployment);
        $this->assertSame($deployment->id, $task->deployment->id);
        $this->assertTrue($deployment->tasks->contains($task));
    }

    public function test_agent_job_belongs_to_task_and_agent_and_every_retry_is_a_new_row(): void
    {
        $task = DeploymentTask::factory()->create();
        $agent = Agent::factory()->approved()->create();

        $firstAttempt = AgentJob::factory()->create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 1,
            'status' => AgentJob::STATUS_FAILED,
            'finished_at' => now(),
        ]);

        $secondAttempt = AgentJob::factory()->create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 2,
            'status' => AgentJob::STATUS_SUCCEEDED,
        ]);

        $this->assertNotSame($firstAttempt->id, $secondAttempt->id);
        $this->assertCount(2, $task->fresh()->agentJobs);
        $this->assertSame($agent->id, $secondAttempt->agent->id);
    }

    public function test_environment_key_is_unique_per_project(): void
    {
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id, 'key' => 'production']);

        $this->expectException(QueryException::class);
        Environment::factory()->create(['project_id' => $project->id, 'key' => 'production']);
    }

    public function test_deployment_target_name_is_unique_per_environment(): void
    {
        $environment = Environment::factory()->create();
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);

        $this->expectException(QueryException::class);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);
    }

    public function test_agent_job_attempt_is_unique_per_deployment_task(): void
    {
        $task = DeploymentTask::factory()->create();
        AgentJob::factory()->create(['deployment_task_id' => $task->id, 'attempt' => 1]);

        $this->expectException(QueryException::class);
        AgentJob::factory()->create(['deployment_task_id' => $task->id, 'attempt' => 1]);
    }

    public function test_deployment_target_agent_is_nullable(): void
    {
        $target = DeploymentTarget::factory()->create(['agent_id' => null]);

        $this->assertNull($target->fresh()->agent_id);
        $this->assertNull($target->agent);
    }
}
