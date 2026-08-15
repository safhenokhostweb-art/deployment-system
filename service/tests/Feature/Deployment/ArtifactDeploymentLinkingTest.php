<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\DeploymentDispatcher;
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

    public function test_artifact_only_project_blocks_a_webhook_deployment_without_an_exact_artifact(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true, 'requires_complete_artifact' => true]);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('f', 40), 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertSame(Deployment::FAILURE_CODE_REQUIRED_ARTIFACT_MISSING, $deployment->failure_code);
        $this->assertNull($deployment->artifact_id);
    }

    public function test_artifact_only_project_never_attaches_an_artifact_from_another_project(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $otherProject = Project::factory()->create();
        $commitHash = str_repeat('1', 40);
        Artifact::factory()->create(['project_id' => $otherProject->id, 'commit_hash' => $commitHash, 'status' => Artifact::STATUS_AVAILABLE]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertSame(Deployment::FAILURE_CODE_REQUIRED_ARTIFACT_MISSING, $deployment->failure_code);
    }

    public function test_artifact_only_project_allows_an_available_exact_match(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $commitHash = str_repeat('2', 40);
        $artifact = Artifact::factory()->create(['project_id' => $project->id, 'commit_hash' => $commitHash, 'status' => Artifact::STATUS_AVAILABLE]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame($artifact->id, $deployment->artifact_id);
    }

    public function test_artifact_only_project_is_not_claimed_if_its_attached_artifact_is_gone(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_QUEUED,
            'artifact_id' => null,
        ]);

        $claimed = app(AgentJobService::class)->claimNextJob($project->server);

        $this->assertNull($claimed);
        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertSame(Deployment::FAILURE_CODE_REQUIRED_ARTIFACT_MISSING, $deployment->failure_code);
    }
}
