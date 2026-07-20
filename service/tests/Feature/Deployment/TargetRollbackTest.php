<?php

namespace Tests\Feature\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 4: per-target rollback for the new domain, and confirmation that
 * legacy rollback (RollbackService::rollbackTo/rollbackToPrevious) is
 * completely unaffected.
 */
class TargetRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function rollbackService(): RollbackService
    {
        return app(RollbackService::class);
    }

    public function test_rolling_back_one_target_does_not_touch_a_sibling_target(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $targetA = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-01']);
        $targetB = DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'name' => 'web-02']);

        $oldDeployment = Deployment::factory()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('a', 40)]);
        $oldTaskA = DeploymentTask::create(['deployment_id' => $oldDeployment->id, 'deployment_target_id' => $targetA->id, 'status' => DeploymentTask::STATUS_SUCCEEDED]);

        // Sibling target B is currently on a newer, unrelated successful deployment.
        $currentDeploymentB = Deployment::factory()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('c', 40)]);
        $taskB = DeploymentTask::create(['deployment_id' => $currentDeploymentB->id, 'deployment_target_id' => $targetB->id, 'status' => DeploymentTask::STATUS_SUCCEEDED]);

        $rollback = $this->rollbackService()->rollbackTargetTo($oldTaskA, 'admin@example.test');

        $this->assertSame(str_repeat('a', 40), $rollback->commit_hash);
        $this->assertSame(Deployment::ACTION_ROLLBACK, $rollback->action);
        $this->assertCount(1, $rollback->tasks);
        $this->assertSame($targetA->id, $rollback->tasks->first()->deployment_target_id);

        // Target B's task/status must be completely untouched.
        $this->assertSame(DeploymentTask::STATUS_SUCCEEDED, $taskB->fresh()->status);
        $this->assertSame($currentDeploymentB->id, $taskB->fresh()->deployment_id);
    }

    public function test_cannot_roll_back_a_target_that_never_succeeded(): void
    {
        $target = DeploymentTarget::factory()->create();
        $deployment = Deployment::factory()->create();
        $task = DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id, 'status' => DeploymentTask::STATUS_FAILED]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->rollbackService()->rollbackTargetTo($task, 'admin@example.test');
    }

    public function test_rollback_is_audit_logged(): void
    {
        $target = DeploymentTarget::factory()->create();
        $deployment = Deployment::factory()->create();
        $task = DeploymentTask::create(['deployment_id' => $deployment->id, 'deployment_target_id' => $target->id, 'status' => DeploymentTask::STATUS_SUCCEEDED]);

        $rollback = $this->rollbackService()->rollbackTargetTo($task, 'admin@example.test');

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment_task.rollback_triggered',
            'subject_id' => $rollback->id,
        ]);
    }

    public function test_legacy_rollback_is_completely_unaffected(): void
    {
        // Same assertions the pre-existing RollbackService tests already
        // make — repeated here as an explicit Task 10 backward-compatibility
        // check for this specific phase.
        $project = Project::factory()->create();
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('d', 40),
            'finished_at' => now()->subHour(),
        ]);

        $rollback = $this->rollbackService()->rollbackTo($successful, 'admin@example.test');

        $this->assertSame(str_repeat('d', 40), $rollback->commit_hash);
        $this->assertCount(0, $rollback->tasks);
        $this->assertSame($project->server_id, $rollback->server_id);
    }
}
