<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
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
     * Regression test (fail-closed artifact-required gate): a project with
     * requires_complete_artifact=true that has no matching artifact must have
     * its deployment failed at creation — never queued, never pending
     * approval, and with the agent-side failure code/message already set.
     */
    public function test_requires_complete_artifact_without_artifact_is_failed_and_never_queued(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $commitHash = str_repeat('c0', 20);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $fresh = $deployment->fresh();
        $this->assertSame(Deployment::STATUS_FAILED, $fresh->status);
        $this->assertNotSame(Deployment::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->artifact_id);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING, $fresh->failure_code);
        $this->assertStringContainsString($project->project_key, $fresh->failure_message);
        $this->assertStringContainsString($commitHash, $fresh->failure_message);
        $this->assertNotNull($fresh->finished_at);
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

    public function test_multi_target_requires_complete_artifact_without_artifact_is_failed_and_creates_no_tasks(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->count(2)->create(['environment_id' => $environment->id]);
        $commitHash = str_repeat('e0', 20);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $fresh = $deployment->fresh();
        $this->assertSame(Deployment::STATUS_FAILED, $fresh->status);
        $this->assertNotSame(Deployment::STATUS_QUEUED, $fresh->status);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING, $fresh->failure_code);
        $this->assertNull($fresh->artifact_id);
        $this->assertCount(0, $fresh->tasks);
        $this->assertNotNull($fresh->finished_at);
    }
}
