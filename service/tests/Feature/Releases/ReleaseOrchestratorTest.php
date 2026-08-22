<?php

namespace Tests\Feature\Releases;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Releases\ReleaseOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_release_for_a_new_commit(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('a', 40);

        $release = app(ReleaseOrchestrator::class)->createForCommit(
            $project,
            $commitHash,
            'main',
            'webhook',
            ['delivery_id' => 'delivery-1']
        );

        $this->assertDatabaseHas('releases', [
            'id' => $release->id,
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'branch' => 'main',
            'source' => 'webhook',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);
        $this->assertSame(['delivery_id' => 'delivery-1'], $release->metadata);
    }

    public function test_it_does_not_create_duplicate_releases_for_the_same_project_and_commit(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('b', 40);

        $first = app(ReleaseOrchestrator::class)->createForCommit($project, $commitHash, 'main', 'webhook');
        $second = app(ReleaseOrchestrator::class)->createForCommit($project, $commitHash, 'main', 'publish');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame('webhook', $second->source);
    }

    public function test_it_attaches_an_existing_available_artifact_and_marks_release_ready(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('c', 40);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
            'release_id' => null,
        ]);

        $release = app(ReleaseOrchestrator::class)->createForCommit($project, $commitHash, 'main', 'publish');

        $this->assertSame(Release::STATUS_READY, $release->status);
        $this->assertTrue($release->isReady());
        $this->assertNotNull($release->ready_at);
        $this->assertSame($release->id, $artifact->fresh()->release_id);
        $this->assertTrue($release->artifacts()->whereKey($artifact->id)->exists());
    }

    public function test_it_waits_for_artifact_when_none_is_available(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('d', 40);

        $release = app(ReleaseOrchestrator::class)->createForCommit($project, $commitHash, 'main', 'webhook');

        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->status);
        $this->assertNotNull($release->artifact_checked_at);
        $this->assertNull($release->ready_at);
        $this->assertFalse($release->isReady());
    }

    public function test_it_ignores_unavailable_artifacts(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('e', 40);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_UPLOADING,
            'release_id' => null,
        ]);

        $release = app(ReleaseOrchestrator::class)->createForCommit($project, $commitHash, 'main', 'publish');

        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->status);
        $this->assertNull($artifact->fresh()->release_id);
    }

    public function test_current_deployment_creation_is_unchanged_and_does_not_require_a_release(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('f', 40);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook(
            $project,
            $commitHash,
            'main',
            'dev@example.test'
        );

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNull($deployment->release_id);
        $this->assertDatabaseMissing('releases', [
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
        ]);
    }
}
