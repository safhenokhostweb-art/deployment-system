<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Project;
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
}
