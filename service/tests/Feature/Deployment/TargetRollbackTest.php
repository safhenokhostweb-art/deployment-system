<?php

namespace Tests\Feature\Deployment;

use App\Events\RollbackStarted;
use App\Models\Agent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\DeploymentTaskCreator;
use App\Services\Deployment\RollbackService;
use App\Services\Security\JobPayloadSigner;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 4: per-target rollback for the new domain, and confirmation that
 * legacy rollback (RollbackService::rollbackTo/rollbackToPrevious) is
 * completely unaffected.
 */
class TargetRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

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

    public function test_rollback_to_creates_exactly_one_task_for_a_single_persisted_target(): void
    {
        [$project, $targets] = $this->projectWithTargets(1);
        $successful = $this->successfulDeployment($project);

        $rollback = $this->rollbackService()->rollbackTo($successful, 'admin@example.test');

        $this->assertCount(1, $rollback->tasks);
        $this->assertSame($targets[0]->id, $rollback->tasks->first()->deployment_target_id);
        $this->assertSame($successful->id, $rollback->rollback_of_deployment_id);
    }

    public function test_rollback_to_creates_exactly_one_task_for_every_active_target(): void
    {
        [$project, $targets] = $this->projectWithTargets(3);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
        ]);
        $successful = $this->successfulDeployment($project, $artifact);

        $rollback = $this->rollbackService()->rollbackTo($successful, 'admin@example.test');

        $this->assertCount(3, $rollback->tasks);
        $this->assertEqualsCanonicalizing(
            collect($targets)->pluck('id')->all(),
            $rollback->tasks->pluck('deployment_target_id')->all(),
        );
        $this->assertSame($successful->commit_hash, $rollback->commit_hash);
        $this->assertSame($successful->artifact_id, $rollback->artifact_id);
        $this->assertSame($successful->id, $rollback->rollback_of_deployment_id);
    }

    public function test_rollback_task_is_claimed_only_through_agent_task_service_and_signed_with_agent_secret(): void
    {
        $agent = Agent::factory()->approved()->create([
            'agent_version' => (string) config('deployment.min_server_agent_version_for_artifact'),
        ]);
        $agentSecret = AgentFactory::$lastPlainSecret;
        $serverSecret = $agent->server->signing_secret;
        $project = Project::factory()->create([
            'server_id' => $agent->server_id,
            'requires_complete_artifact' => true,
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'agent_id' => $agent->id,
        ]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
        ]);
        $successful = $this->successfulDeployment($project, $artifact);
        $rollback = $this->rollbackService()->rollbackTo($successful, 'admin@example.test');

        $this->assertNull(app(AgentJobService::class)->claimNextJob($agent->server));

        $claimed = app(AgentTaskService::class)->claimNextTask($agent);
        $this->assertNotNull($claimed);
        $this->assertSame(Deployment::ACTION_ROLLBACK, $claimed['job']['action']);
        $this->assertSame($artifact->public_id, $claimed['job']['artifact']['public_id']);

        $signer = app(JobPayloadSigner::class);
        $this->assertTrue(hash_equals($claimed['signature'], $signer->sign($claimed['job'], $agentSecret)));
        $this->assertFalse(hash_equals($claimed['signature'], $signer->sign($claimed['job'], $serverSecret)));

        foreach (['id', 'project_key', 'action', 'commit_hash', 'previous_commit_hash', 'branch', 'issued_at'] as $field) {
            $tampered = $claimed['job'];
            $tampered[$field] = (string) $tampered[$field] . '-tampered';
            $this->assertFalse(hash_equals($claimed['signature'], $signer->sign($tampered, $agentSecret)), "Tampering {$field} must invalidate the signature.");
        }

        $this->assertSame(DeploymentTask::STATUS_RUNNING, $rollback->tasks()->firstOrFail()->fresh()->status);
    }

    public function test_task_creation_is_idempotent_for_the_same_deployment_and_targets(): void
    {
        [$project] = $this->projectWithTargets(2);
        $deployment = Deployment::factory()->create(['project_id' => $project->id]);
        $targets = app(\App\Services\Deployment\DeploymentTargetResolver::class)->resolveTargetsFor($project);
        $creator = app(DeploymentTaskCreator::class);

        $creator->createForTargets($deployment, $targets);
        $creator->createForTargets($deployment, $targets);

        $this->assertSame(2, $deployment->tasks()->count());
    }

    public function test_task_creation_failure_rolls_back_the_deployment_and_defers_the_event(): void
    {
        Event::fake([RollbackStarted::class]);
        [$project] = $this->projectWithTargets(1);
        $successful = $this->successfulDeployment($project);
        $creator = \Mockery::mock(DeploymentTaskCreator::class);
        $creator->shouldReceive('createForTargets')->once()->andReturnUsing(function (Deployment $deployment, array $targets): void {
            DeploymentTask::create([
                'deployment_id' => $deployment->id,
                'deployment_target_id' => $targets[0]->deploymentTargetId,
                'status' => DeploymentTask::STATUS_QUEUED,
            ]);

            throw new RuntimeException('synthetic task failure');
        });
        $this->app->instance(DeploymentTaskCreator::class, $creator);

        try {
            app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');
            $this->fail('Rollback should fail when task creation fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic task failure', $exception->getMessage());
        }

        $this->assertSame(1, $project->deployments()->count());
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'deployment.rollback_triggered']);
        Event::assertNotDispatched(RollbackStarted::class);
    }

    public function test_no_active_target_does_not_leave_a_partial_rollback(): void
    {
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id, 'status' => Environment::STATUS_DISABLED]);
        $successful = $this->successfulDeployment($project);

        try {
            $this->rollbackService()->rollbackTo($successful, 'admin@example.test');
            $this->fail('Rollback should reject a project without active targets.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(1, $project->deployments()->count());
    }

    /** @return array{Project, array<int, DeploymentTarget>} */
    private function projectWithTargets(int $count): array
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $targets = [];

        for ($index = 0; $index < $count; $index++) {
            $targets[] = DeploymentTarget::factory()->create([
                'environment_id' => $environment->id,
                'name' => "web-{$index}",
            ]);
        }

        return [$project, $targets];
    }

    private function successfulDeployment(Project $project, ?Artifact $artifact = null): Deployment
    {
        return Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('b', 40),
            'artifact_id' => $artifact?->id,
            'finished_at' => now()->subHour(),
        ]);
    }
}
