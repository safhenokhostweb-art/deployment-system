<?php

namespace Tests\Feature\Console;

use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.2: publish:auto-publish-stable-changes is the Hub-side half of
 * Auto Mode — mirrors DetectOfflineAgentsTest's structure. Unlike that
 * sweep, this one mutates status the instant it acts, so there's no
 * "already handled, don't re-fire" window to test separately — a
 * processed row can never be picked up again by construction.
 */
class AutoPublishStableChangesTest extends TestCase
{
    use RefreshDatabase;

    private function mapping(string $mode): LocalProjectMapping
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        return LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'deployment_mode' => $mode,
        ]);
    }

    public function test_a_stable_detection_on_an_auto_mapping_is_auto_published(): void
    {
        $mapping = $this->mapping(LocalProjectMapping::MODE_AUTO);
        $stabilityMinutes = (int) config('deployment.auto_publish_stability_minutes');

        $job = LocalChangeDetectionJob::factory()->create([
            'local_agent_id' => $mapping->local_agent_id,
            'project_id' => $mapping->project_id,
            'status' => LocalChangeDetectionJob::STATUS_DETECTED,
            'detected_at' => now()->subMinutes($stabilityMinutes + 1),
        ]);

        $this->artisan('publish:auto-publish-stable-changes')->assertExitCode(0);

        $job->refresh();
        $this->assertSame(LocalChangeDetectionJob::STATUS_APPROVED, $job->status);
        $this->assertNotNull($job->publish_job_id);

        $publishJob = PublishJob::find($job->publish_job_id);
        $this->assertSame(PublishJob::STATUS_APPROVED, $publishJob->status);
        $this->assertSame(PublishJob::ACTION_PUBLISH, $publishJob->action);
    }

    public function test_a_not_yet_stable_detection_is_left_alone(): void
    {
        $mapping = $this->mapping(LocalProjectMapping::MODE_AUTO);

        $job = LocalChangeDetectionJob::factory()->create([
            'local_agent_id' => $mapping->local_agent_id,
            'project_id' => $mapping->project_id,
            'status' => LocalChangeDetectionJob::STATUS_DETECTED,
            'detected_at' => now()->subSeconds(30),
        ]);

        $this->artisan('publish:auto-publish-stable-changes')->assertExitCode(0);

        $this->assertSame(LocalChangeDetectionJob::STATUS_DETECTED, $job->fresh()->status);
        $this->assertSame(0, PublishJob::count());
    }

    public function test_a_stable_detection_on_a_manual_mapping_is_not_auto_published(): void
    {
        $mapping = $this->mapping(LocalProjectMapping::MODE_MANUAL);
        $stabilityMinutes = (int) config('deployment.auto_publish_stability_minutes');

        $job = LocalChangeDetectionJob::factory()->create([
            'local_agent_id' => $mapping->local_agent_id,
            'project_id' => $mapping->project_id,
            'status' => LocalChangeDetectionJob::STATUS_DETECTED,
            'detected_at' => now()->subMinutes($stabilityMinutes + 1),
        ]);

        $this->artisan('publish:auto-publish-stable-changes')->assertExitCode(0);

        $this->assertSame(LocalChangeDetectionJob::STATUS_DETECTED, $job->fresh()->status);
        $this->assertSame(0, PublishJob::count());
    }

    public function test_a_stable_detection_with_no_changed_files_is_skipped(): void
    {
        $mapping = $this->mapping(LocalProjectMapping::MODE_AUTO);
        $stabilityMinutes = (int) config('deployment.auto_publish_stability_minutes');

        $job = LocalChangeDetectionJob::factory()->create([
            'local_agent_id' => $mapping->local_agent_id,
            'project_id' => $mapping->project_id,
            'status' => LocalChangeDetectionJob::STATUS_DETECTED,
            'detected_at' => now()->subMinutes($stabilityMinutes + 1),
            'changed_files' => [],
            'files_changed_count' => 0,
        ]);

        $this->artisan('publish:auto-publish-stable-changes')->assertExitCode(0);

        $this->assertSame(LocalChangeDetectionJob::STATUS_DETECTED, $job->fresh()->status);
    }
}
