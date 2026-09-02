<?php

namespace Tests\Feature\Console;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK C — CLI wiring for the explicit, operator-invoked obsolete-build
 * cleanup: dry-run by default, --apply to actually invalidate.
 */
class ReconcileObsoleteArtifactBuildsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_a_dry_run_that_changes_nothing(): void
    {
        [, $job] = $this->obsoleteQueuedBuild();

        $this->artisan('releases:reconcile-obsolete-artifact-builds')
            ->assertSuccessful()
            ->expectsOutputToContain('1 obsolete')
            ->expectsOutputToContain('Re-run with --apply');

        $this->assertSame(PublishJob::STATUS_APPROVED, $job->fresh()->status);
    }

    public function test_apply_flag_actually_invalidates(): void
    {
        [, $job] = $this->obsoleteQueuedBuild();

        $this->artisan('releases:reconcile-obsolete-artifact-builds', ['--apply' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('1 invalidated');

        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->fresh()->status);
    }

    /** @return array{Release, PublishJob} */
    private function obsoleteQueuedBuild(): array
    {
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        $oldRelease = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'status' => Release::STATUS_ARTIFACT_BUILDING,
            'source_received_at' => now()->subDays(10),
        ]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'release_id' => $oldRelease->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_APPROVED,
            'reviewed_head_sha' => $oldRelease->commit_hash,
            'reviewed_branch' => 'main',
        ]);
        Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'status' => Release::STATUS_AWAITING_ARTIFACT]);

        return [$oldRelease, $job];
    }
}
