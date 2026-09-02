<?php

namespace Tests\Unit\Services\Releases;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Releases\ReleaseArtifactBuildRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix (release-orchestration bootstrap deadlock): closes the
 * loop for a Release ProcessGitHubWebhookPush left awaiting_artifact
 * because its authoritative build Agent was version-ineligible — once the
 * Agent's exact-commit self-upgrade completes, nothing else ever revisits
 * that Release (GitHub does not redeliver a push on its own). This is the
 * scheduled sweep that does.
 */
class ReleaseArtifactBuildRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stranded_release_stays_awaiting_artifact_while_the_agent_is_still_ineligible(): void
    {
        [$release, ] = $this->strandedRelease('1.5.2');

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();

        $this->assertSame(['checked' => 1, 'queued' => 0], $result);
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->fresh()->status);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    public function test_a_stranded_release_recovers_automatically_once_the_agent_upgrades(): void
    {
        [$release, $agent] = $this->strandedRelease('1.5.2');

        // The exact-commit self-upgrade completes and heartbeat verifies
        // it (LocalAgentControlJobService::verifyUpgradeHeartbeat) --
        // simulated here directly as the Agent's new reported version,
        // since that verification is this test's precondition, not its
        // subject.
        $agent->update(['agent_version' => '1.5.3']);

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();

        $this->assertSame(['checked' => 1, 'queued' => 1], $result);
        $release = $release->fresh();
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $build = PublishJob::sole();
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $build->action);
        $this->assertSame($release->id, $build->release_id);
        $this->assertSame($agent->id, $build->local_agent_id);

        // A second sweep is a pure no-op: the build is already active.
        $again = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(['checked' => 0, 'queued' => 0], $again);
        $this->assertSame(1, PublishJob::count());
    }

    public function test_a_release_with_an_already_active_build_is_never_reconsidered(): void
    {
        [$release, $agent] = $this->strandedRelease('1.5.3');

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, PublishJob::count());

        $again = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(['checked' => 0, 'queued' => 0], $again);
        $this->assertSame(1, PublishJob::count());
    }

    /** @return array{Release, LocalAgent} */
    private function strandedRelease(string $agentVersion): array
    {
        $sha = str_repeat('e', 40);
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true, 'is_active' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create([
            'agent_version' => $agentVersion,
            'capabilities' => [
                'git' => true,
                'laravel_release_manifest' => true,
                'assigned_artifact_publish_job_id' => true,
            ],
        ]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'source_workspace_path' => 'C:\\workspaces\\project',
            'git_branch' => 'main',
            'local_head_sha' => $sha,
            'remote_tracking_sha' => $sha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        return [$release, $agent];
    }
}
