<?php

namespace Tests\Unit\Services\Releases;

use App\Models\Project;
use App\Models\Release;
use App\Services\Releases\ReleaseRecoveryEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseRecoveryEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_newest_release_for_a_project_and_branch_is_current(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'source_received_at' => now()->subDay()]);
        $newest = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main']);

        $this->assertTrue(app(ReleaseRecoveryEligibility::class)->isCurrent($newest->fresh()));
    }

    public function test_an_older_release_for_the_same_project_and_branch_is_not_current(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $older = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main']);
        Release::factory()->create(['project_id' => $project->id, 'branch' => 'main']);

        $this->assertFalse(app(ReleaseRecoveryEligibility::class)->isCurrent($older->fresh()));
    }

    /**
     * The domain contract is deliberately "the newest row for this
     * project+branch is the tip, full stop" -- a newer push's own Release
     * being invalid, incomplete, or itself failed does not resurrect an
     * older awaiting_artifact Release as a fallback build target. Locks in
     * the answer to "should ANY newer Release supersede, or only one that
     * satisfies its own evidence contract": ANY newer row does, exactly
     * like a git branch ref only ever pointing at one commit regardless of
     * whether that commit's own CI passed.
     */
    public function test_a_newer_release_supersedes_an_older_one_regardless_of_the_newer_ones_own_status(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $older = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'status' => Release::STATUS_AWAITING_ARTIFACT]);

        foreach ([Release::STATUS_FAILED, Release::STATUS_SOURCE_RECEIVED, Release::STATUS_READY] as $newerStatus) {
            $newer = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'status' => $newerStatus]);

            $this->assertFalse(
                app(ReleaseRecoveryEligibility::class)->isCurrent($older->fresh()),
                "A newer Release left in status '{$newerStatus}' must still supersede the older awaiting_artifact Release.",
            );
            $this->assertTrue(app(ReleaseRecoveryEligibility::class)->isCurrent($newer->fresh()));

            $newer->delete();
        }
    }

    public function test_a_release_on_a_branch_the_project_no_longer_tracks_is_never_current(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $onOldBranch = Release::factory()->create(['project_id' => $project->id, 'branch' => 'develop']);

        $this->assertFalse(app(ReleaseRecoveryEligibility::class)->isCurrent($onOldBranch->fresh()));
    }

    public function test_releases_for_different_projects_never_supersede_each_other(): void
    {
        $projectA = Project::factory()->create(['branch' => 'main']);
        $projectB = Project::factory()->create(['branch' => 'main']);
        $releaseA = Release::factory()->create(['project_id' => $projectA->id, 'branch' => 'main']);
        Release::factory()->create(['project_id' => $projectB->id, 'branch' => 'main']);

        $this->assertTrue(app(ReleaseRecoveryEligibility::class)->isCurrent($releaseA->fresh()));
    }

    public function test_automatic_recovery_eligibility_also_requires_the_project_to_want_a_complete_artifact_and_be_active(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => false, 'is_active' => true]);
        $release = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main']);

        $eligibility = app(ReleaseRecoveryEligibility::class);
        $this->assertTrue($eligibility->isCurrent($release->fresh()));
        $this->assertFalse($eligibility->isEligibleForAutomaticRecovery($release->fresh()));

        $project->update(['requires_complete_artifact' => true, 'is_active' => false]);
        $this->assertFalse($eligibility->isEligibleForAutomaticRecovery($release->fresh()));

        $project->update(['is_active' => true]);
        $this->assertTrue($eligibility->isEligibleForAutomaticRecovery($release->fresh()));
    }
}
