<?php

namespace Tests\Feature\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Task 2/3/4: routing, resolver integration, and task creation, exercised
 * directly against DeploymentDispatcher (the webhook/manual controllers
 * are exercised separately in AgentTaskClaimTest and the existing
 * GitHubWebhookTest, which is untouched by this phase).
 */
class MultiTargetDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): DeploymentDispatcher
    {
        return app(DeploymentDispatcher::class);
    }

    public function test_legacy_project_creates_a_flat_deployment_with_zero_tasks(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('a', 40), $project->branch, 'octocat');

        $this->assertSame($project->server_id, $deployment->server_id);
        $this->assertCount(0, $deployment->tasks);
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
    }

    public function test_new_domain_project_with_auto_deploy_creates_tasks_immediately(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target1 = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);
        $target2 = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-02']);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('b', 40), $project->branch, 'octocat');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertCount(2, $deployment->tasks);
        $targetIds = $deployment->tasks->pluck('deployment_target_id')->sort()->values();
        $this->assertSame([$target1->id, $target2->id], $targetIds->sort()->values()->all());
        $this->assertTrue($deployment->tasks->every(fn ($t) => $t->status === DeploymentTask::STATUS_QUEUED));
    }

    public function test_new_domain_project_without_auto_deploy_defers_task_creation_until_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('c', 40), $project->branch, 'octocat');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertCount(0, $deployment->tasks);

        $this->dispatcher()->approve($deployment, 'admin@example.test');

        $this->assertCount(1, $deployment->fresh()->tasks);
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
    }

    public function test_new_domain_project_with_zero_active_targets_fails_closed_not_legacy_fallback(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        Environment::factory()->create(['project_id' => $project->id]); // no targets at all

        $this->expectException(HttpException::class);

        $this->dispatcher()->createFromWebhook($project, str_repeat('d', 40), $project->branch, 'octocat');
    }

    public function test_inactive_targets_are_never_included(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'active-one', 'is_active' => true]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'disabled-one', 'is_active' => false]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('e', 40), $project->branch, 'octocat');

        $this->assertCount(1, $deployment->tasks);
        $this->assertSame('active-one', $deployment->tasks->first()->deploymentTarget->name);
    }

    public function test_duplicate_task_per_target_is_prevented_at_the_database_level(): void
    {
        $deployment = Deployment::factory()->create();
        $target = DeploymentTarget::factory()->create();

        DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id]);
    }

    public function test_cancelling_a_multi_target_deployment_cancels_its_queued_tasks(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->count(2)->create(['environment_id' => $environment->id]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('f', 40), $project->branch, 'octocat');
        $this->dispatcher()->cancel($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_CANCELLED, $deployment->fresh()->status);
        $this->assertTrue($deployment->fresh()->tasks->every(fn ($t) => $t->status === DeploymentTask::STATUS_CANCELLED));
    }
}
