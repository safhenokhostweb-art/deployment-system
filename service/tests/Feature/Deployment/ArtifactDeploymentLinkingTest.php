<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Agent;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): a Deployment
 * whose project/commit has a matching Artifact gets it attached at
 * creation time (see DeploymentDispatcher::attachArtifactIfAvailable()),
 * so AgentJobService::claimNextJob() can tell the agent to download it
 * instead of git-fetching from GitHub. A Deployment with no matching
 * artifact is completely unaffected — the exact legacy behavior.
 */
class ArtifactDeploymentLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_created_deployment_attaches_a_matching_artifact(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('a', 40);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame($artifact->id, $deployment->artifact_id);
    }

    public function test_webhook_created_deployment_has_no_artifact_when_none_matches(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('b', 40), 'main', 'dev@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_pending_deployment_attaches_an_artifact_that_becomes_available_before_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('b', 40);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertNull($deployment->artifact_id);

        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertSame($artifact->id, $deployment->fresh()->artifact_id);
    }

    public function test_pending_deployment_without_an_artifact_keeps_the_legacy_fallback_when_approved(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('f', 40), 'main', 'dev@example.test');

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_manually_triggered_deployment_attaches_a_matching_artifact(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('c', 40);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertSame($artifact->id, $deployment->artifact_id);
    }

    public function test_an_artifact_belonging_to_a_different_project_is_never_attached(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $commitHash = str_repeat('d', 40);
        Artifact::factory()->create([
            'project_id' => $otherProject->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_an_artifact_for_a_different_commit_is_never_attached_at_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('1', 40), 'main', 'dev@example.test');
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('2', 40),
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_an_artifact_still_uploading_is_never_attached(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('e', 40);
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_UPLOADING,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_a_failed_artifact_is_never_attached_at_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('9', 40);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_FAILED,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_multi_target_approval_attaches_the_artifact_before_queued_tasks_are_created(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->count(2)->create(['environment_id' => $environment->id]);
        $commitHash = str_repeat('7', 40);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $fresh = $deployment->fresh();
        $this->assertSame($artifact->id, $fresh->artifact_id);
        $this->assertCount(2, $fresh->tasks);
        $this->assertTrue($fresh->tasks->every(fn (DeploymentTask $task) => $task->status === DeploymentTask::STATUS_QUEUED));
    }

    public function test_auto_deploy_does_not_late_attach_an_artifact_uploaded_after_it_was_queued(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('8', 40);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNull($deployment->artifact_id);

        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_approval_does_not_replace_an_existing_artifact_link(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('3', 40);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $existing = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('4', 40),
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $matching = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $deployment->update(['artifact_id' => $existing->id]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame($existing->id, $deployment->fresh()->artifact_id);
        $this->assertNotSame($matching->id, $deployment->fresh()->artifact_id);
    }

    public function test_rollback_never_attaches_a_matching_deploy_artifact(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('5', 40);
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commitHash,
            'finished_at' => now(),
        ]);
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $rollback = app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');

        $this->assertSame(Deployment::ACTION_ROLLBACK, $rollback->action);
        $this->assertNull($rollback->artifact_id);
    }

    /**
     * Regression test (artifact race condition): a project with
     * requires_complete_artifact=true that has no matching artifact at
     * CREATION time must NOT be failed at creation — the Local Agent may
     * still be uploading the exact-commit artifact. The deployment stays
     * queued so AgentJobService::claimNextJob() can re-attach it at claim
     * time, and only then fails closed if it is still missing.
     */
    public function test_requires_complete_artifact_without_artifact_stays_queued_at_creation(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $commitHash = str_repeat('c0', 20);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $fresh = $deployment->fresh();
        // Race-condition fix: no fail-at-creation. The gate moved to claim time.
        $this->assertSame(Deployment::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->artifact_id);
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->finished_at);
    }

    /**
     * Regression test (artifact race condition): the artifact lands a moment
     * AFTER the webhook created the queued deployment — claimNextJob()
     * re-attaches the exact-commit artifact and dispatches the job instead of
     * failing it. This is the exact production race that used to fail
     * deployments before the upload finished.
     */
    public function test_requires_complete_artifact_lands_after_creation_is_reattached_at_claim(): void
    {
        $server = Server::factory()->create();
        $project = Project::factory()->create([
            'server_id' => $server->id,
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $commitHash = str_repeat('f0', 20);
        // The agent that will claim the job must belong to the same server.
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id, 'agent_version' => '1.2.0']);

        // Webhook fires while the Local Agent is still uploading the artifact.
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertNull($deployment->fresh()->artifact_id);

        // Upload completes a moment later.
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        // Server Agent claims the job — claim-time re-attach picks the
        // artifact up and the job is dispatched (not failed).
        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNotNull($claimed);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
        $this->assertSame($artifact->id, $deployment->fresh()->artifact_id);
    }

    public function test_requires_complete_artifact_with_matching_artifact_is_queued_normally(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $commitHash = str_repeat('d0', 20);
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertNotNull($deployment->fresh()->artifact_id);
    }

    public function test_multi_target_requires_complete_artifact_without_artifact_stays_queued_at_creation(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $server = Server::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $targets = DeploymentTarget::factory()->count(2)->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
        ]);
        $commitHash = str_repeat('e0', 20);
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id, 'agent_version' => '1.2.0']);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $fresh = $deployment->fresh();
        // Race-condition fix: no fail-at-creation, tasks are created so the
        // claim-time re-attach (AgentTaskService) can pick the artifact up.
        $this->assertSame(Deployment::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->artifact_id);
        $this->assertNull($fresh->failure_code);
        $this->assertCount(2, $fresh->tasks);
        $this->assertNull($fresh->finished_at);

        // The artifact lands after creation; the first claiming agent
        // re-attaches it and dispatches instead of failing closed.
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $claimed = app(AgentTaskService::class)->claimNextTask($agent);

        $this->assertNotNull($claimed);
        // Multi-target aggregate stays queued until a task reaches a terminal
        // state (existing aggregator behavior); the task itself is running and
        // the exact-commit artifact is now attached — that is what proves
        // the race-condition re-attach worked instead of failing closed.
        $this->assertSame(Deployment::STATUS_QUEUED, $fresh->fresh()->status);
        // Exactly one of the two tasks was claimed (running) — the other
        // remains queued — and the exact-commit artifact is attached.
        $taskStatuses = $fresh->fresh()->tasks()->pluck('status')->all();
        $this->assertCount(1, array_keys($taskStatuses, DeploymentTask::STATUS_RUNNING, true));
        $this->assertCount(1, array_keys($taskStatuses, DeploymentTask::STATUS_QUEUED, true));
        $this->assertSame($artifact->id, $fresh->fresh()->artifact_id);
    }
}
