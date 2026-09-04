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

        $this->assertSame(['checked' => 1, 'superseded' => 0, 'queued' => 0], $result);
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
        $agent->update(['agent_version' => '1.5.4']);

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();

        $this->assertSame(['checked' => 1, 'superseded' => 0, 'queued' => 1], $result);
        $release = $release->fresh();
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $build = PublishJob::sole();
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $build->action);
        $this->assertSame($release->id, $build->release_id);
        $this->assertSame($agent->id, $build->local_agent_id);

        // A second sweep is a pure no-op: the build is already active.
        $again = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(['checked' => 0, 'superseded' => 0, 'queued' => 0], $again);
        $this->assertSame(1, PublishJob::count());
    }

    public function test_a_release_with_an_already_active_build_is_never_reconsidered(): void
    {
        [$release, $agent] = $this->strandedRelease('1.5.4');

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, PublishJob::count());

        $again = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(['checked' => 0, 'superseded' => 0, 'queued' => 0], $again);
        $this->assertSame(1, PublishJob::count());
    }

    /**
     * TASK D — the exact production incident shape: two independent
     * projects each carry a backlog of historical awaiting_artifact
     * Releases (superseded by later pushes to the same branch, long
     * before the Agent-version blocker) plus exactly one genuinely
     * current Release. A version-ineligible-then-upgraded authoritative
     * Agent must trigger a build for ONLY the current Release of each
     * project — never the historical backlog — and repeating the sweep
     * must not create duplicates or revive anything.
     */
    public function test_recovery_never_revives_a_historical_backlog_only_the_current_release_per_project(): void
    {
        $projectA = $this->projectWithHistory('project-a', historicalCount: 4);
        $projectB = $this->projectWithHistory('project-b', historicalCount: 3);

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();

        $this->assertSame(2, $result['checked'], 'Only the two current Releases (one per project) are genuine candidates.');
        $this->assertSame(7, $result['superseded'], '4 + 3 historical Releases across both projects must be recognized as superseded and skipped.');
        $this->assertSame(1, $result['queued'], 'Only project A -- whose Agent is now eligible -- gets a build; project B stays blocked.');

        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $projectA['current']->fresh()->status);
        foreach ($projectA['historical'] as $old) {
            $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $old->fresh()->status, 'A superseded historical Release must be left completely untouched.');
        }

        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $projectB['current']->fresh()->status, 'Project B\'s Agent is still below the assigned-artifact minimum -- its current Release stays awaiting_artifact.');
        foreach ($projectB['historical'] as $old) {
            $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $old->fresh()->status);
        }

        $build = PublishJob::sole();
        $this->assertSame($projectA['current']->id, $build->release_id);

        // Idempotency: a second sweep creates no duplicates and revives
        // nothing new. Project A's Release is now excluded (active build in
        // flight); project B's current Release is still legitimately
        // re-checked each sweep (its Agent remains ineligible) but stays
        // unqueued, and the historical backlog remains superseded.
        $again = app(ReleaseArtifactBuildRetryService::class)->retry();
        $this->assertSame(['checked' => 1, 'superseded' => 7, 'queued' => 0], $again);
        $this->assertSame(1, PublishJob::count());
        $this->assertSame($projectA['current']->id, PublishJob::sole()->release_id, 'The single build remains project A\'s -- no duplicate and no new one for project B.');
    }

    public function test_an_existing_available_artifact_prevents_an_unnecessary_rebuild(): void
    {
        [$release, ] = $this->strandedRelease('1.5.3');
        // Simplest way to reach ReleaseOrchestrator::syncArtifactState()'s
        // "already satisfied" branch without re-deriving the full
        // exact-commit evidence contract machinery (see
        // ExactCommitEvidenceClosureTest for that path): a legacy-fallback
        // Release with a matching available Artifact is ready by that same
        // established rule.
        $release->forceFill([
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'source' => 'legacy',
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'test fixture',
            'legacy_fallback_eligible_by' => 'tester@example.test',
            'metadata' => ['release_ready' => true],
        ])->save();
        \App\Models\Artifact::factory()->create([
            'project_id' => $release->project_id,
            'commit_hash' => $release->commit_hash,
            'branch' => $release->branch,
            'status' => \App\Models\Artifact::STATUS_AVAILABLE,
            'semantic_status' => 'legacy',
        ]);

        $result = app(ReleaseArtifactBuildRetryService::class)->retry();

        $this->assertSame(0, $result['queued'], 'A Release already satisfied by an existing Artifact must not get a redundant build.');
        $this->assertDatabaseCount('publish_jobs', 0);
        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
    }

    /**
     * @return array{current: Release, historical: array<int, Release>}
     */
    private function projectWithHistory(string $projectKey, int $historicalCount): array
    {
        $currentSha = hash('sha256', $projectKey . '-current');
        $currentSha = substr($currentSha, 0, 40);
        $project = Project::factory()->create(['project_key' => $projectKey, 'branch' => 'main', 'requires_complete_artifact' => true, 'is_active' => true]);
        $agentVersion = $projectKey === 'project-a' ? '1.5.4' : '1.5.2';
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
            'source_workspace_path' => 'C:\\workspaces\\' . $projectKey,
            'git_branch' => 'main',
            'local_head_sha' => $currentSha,
            'remote_tracking_sha' => $currentSha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        $historical = [];
        for ($i = 0; $i < $historicalCount; $i++) {
            $historical[] = Release::factory()->create([
                'project_id' => $project->id,
                'commit_hash' => substr(hash('sha256', $projectKey . '-old-' . $i), 0, 40),
                'branch' => 'main',
                'status' => Release::STATUS_AWAITING_ARTIFACT,
                'source_received_at' => now()->subDays(30 - $i),
            ]);
        }

        // Created last so it has the highest id -- the current branch tip.
        $current = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        return ['current' => $current, 'historical' => $historical];
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
