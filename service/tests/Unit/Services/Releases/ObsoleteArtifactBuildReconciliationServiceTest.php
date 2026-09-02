<?php

namespace Tests\Unit\Services\Releases;

use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Releases\ObsoleteArtifactBuildReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK C — the explicit, operator-invoked cleanup for the exact backlog the
 * old, unbounded releases:retry-awaiting-artifact sweep left behind: a
 * not-yet-started build_artifact PublishJob queued for a Release since
 * superseded by a later push.
 */
class ObsoleteArtifactBuildReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_the_obsolete_job_without_changing_anything(): void
    {
        [$oldRelease, , $job] = $this->obsoleteQueuedBuild(PublishJob::STATUS_APPROVED);

        $result = app(ObsoleteArtifactBuildReconciliationService::class)->reconcile(apply: false);

        $this->assertSame(['examined' => 1, 'obsolete' => 1, 'invalidated' => 0], $result);
        $this->assertSame(PublishJob::STATUS_APPROVED, $job->fresh()->status);
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $oldRelease->fresh()->status);
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'publish_job.invalidated_obsolete_artifact_build']);
    }

    public function test_apply_invalidates_the_obsolete_job_and_reconciles_the_release(): void
    {
        [$oldRelease, , $job] = $this->obsoleteQueuedBuild(PublishJob::STATUS_APPROVED);

        $result = app(ObsoleteArtifactBuildReconciliationService::class)->reconcile(apply: true);

        $this->assertSame(['examined' => 1, 'obsolete' => 1, 'invalidated' => 1], $result);
        $job = $job->fresh();
        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->status);
        $this->assertSame('obsolete_release_superseded', $job->failure_code);
        $this->assertNotNull($job->finished_at);

        // Reconciled back to awaiting_artifact (no Artifact exists), not
        // left stuck at artifact_building, and never mislabeled failed.
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $oldRelease->fresh()->status);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.invalidated_obsolete_artifact_build',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'publish_job.abandoned']);
    }

    public function test_a_currently_eligible_releases_job_is_left_completely_alone(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        $current = Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'status' => Release::STATUS_ARTIFACT_BUILDING]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'release_id' => $current->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_APPROVED,
            'reviewed_head_sha' => $current->commit_hash,
            'reviewed_branch' => 'main',
        ]);

        $result = app(ObsoleteArtifactBuildReconciliationService::class)->reconcile(apply: true);

        $this->assertSame(['examined' => 1, 'obsolete' => 0, 'invalidated' => 0], $result);
        $this->assertSame(PublishJob::STATUS_APPROVED, $job->fresh()->status);
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $current->fresh()->status);
    }

    public function test_a_running_job_is_never_touched_even_if_its_release_is_obsolete(): void
    {
        [, , $job] = $this->obsoleteQueuedBuild(PublishJob::STATUS_RUNNING);

        $result = app(ObsoleteArtifactBuildReconciliationService::class)->reconcile(apply: true);

        $this->assertSame(['examined' => 0, 'obsolete' => 0, 'invalidated' => 0], $result, 'A running job must never even be considered, let alone invalidated.');
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_a_succeeded_job_is_never_touched_even_if_its_release_is_obsolete(): void
    {
        [, , $job] = $this->obsoleteQueuedBuild(PublishJob::STATUS_SUCCEEDED);

        $result = app(ObsoleteArtifactBuildReconciliationService::class)->reconcile(apply: true);

        $this->assertSame(['examined' => 0, 'obsolete' => 0, 'invalidated' => 0], $result);
        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $job->fresh()->status);
    }

    public function test_reconciliation_is_idempotent_when_run_twice(): void
    {
        [, , $job] = $this->obsoleteQueuedBuild(PublishJob::STATUS_QUEUED);
        $service = app(ObsoleteArtifactBuildReconciliationService::class);

        $first = $service->reconcile(apply: true);
        $this->assertSame(1, $first['invalidated']);

        $second = $service->reconcile(apply: true);
        $this->assertSame(['examined' => 0, 'obsolete' => 0, 'invalidated' => 0], $second, 'Already-invalidated jobs must not be re-examined or double-counted.');
        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->fresh()->status);
        $this->assertSame(1, AuditLogEntry::where('action', 'publish_job.invalidated_obsolete_artifact_build')->count());
    }

    /** @return array{Release, Release, PublishJob} old release, current release, job on the old one */
    private function obsoleteQueuedBuild(string $jobStatus): array
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
            'status' => $jobStatus,
            'reviewed_head_sha' => $oldRelease->commit_hash,
            'reviewed_branch' => 'main',
        ]);
        // The later push that superseded $oldRelease for this project+branch.
        $currentRelease = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        return [$oldRelease, $currentRelease, $job];
    }
}
