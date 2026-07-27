<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Notification;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\Publish\LocalChangeDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.2: LocalChangeDetectionJob's upsert-on-report semantics and
 * mode-based initial status — the rules that keep the watcher from
 * flooding the Hub with one row (or one notification) per poll cycle.
 */
class LocalChangeDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mappingWithMode(string $mode): LocalProjectMapping
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        return LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'deployment_mode' => $mode,
        ]);
    }

    public function test_report_on_a_manual_mapping_stays_detected_without_notifying(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_MANUAL);

        $job = app(LocalChangeDetectionService::class)->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $this->assertSame(LocalChangeDetectionJob::STATUS_DETECTED, $job->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_report_on_an_auto_mapping_stays_detected_without_notifying(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_AUTO);

        $job = app(LocalChangeDetectionService::class)->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $this->assertSame(LocalChangeDetectionJob::STATUS_DETECTED, $job->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_report_on_an_approval_mapping_notifies_once(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);

        $job = app(LocalChangeDetectionService::class)->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $this->assertSame(LocalChangeDetectionJob::STATUS_WAITING_APPROVAL, $job->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_CHANGE_DETECTION_AWAITING_APPROVAL,
            'related_type' => LocalChangeDetectionJob::class,
            'related_id' => $job->id,
        ]);
    }

    public function test_repeated_report_updates_the_same_open_row_without_a_second_notification(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);

        $first = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);
        $second = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php', 'app/Bar.php']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->files_changed_count);
        $this->assertSame(1, LocalChangeDetectionJob::count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_report_without_a_mapping_is_rejected(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(LocalChangeDetectionService::class)->report($agent, $project, ['app/Foo.php']);
    }

    public function test_approve_creates_an_approved_publish_job_and_links_it(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $job = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $publishJob = $service->approve($job, 'Fix the widget', 'dev@example.test');

        $this->assertSame(PublishJob::STATUS_APPROVED, $publishJob->status);
        $this->assertSame(PublishJob::ACTION_PUBLISH, $publishJob->action);
        $this->assertSame('Fix the widget', $publishJob->commit_message);

        $job->refresh();
        $this->assertSame(LocalChangeDetectionJob::STATUS_APPROVED, $job->status);
        $this->assertSame($publishJob->id, $job->publish_job_id);
    }

    public function test_approve_without_a_commit_message_falls_back_to_the_suggested_one(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $job = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $publishJob = $service->approve($job, null, 'dev@example.test');

        $this->assertSame($job->suggested_commit_message, $publishJob->commit_message);
    }

    public function test_approve_rejects_a_detection_with_no_changed_files(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $job = $service->report($mapping->localAgent, $mapping->project, []);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve($job, 'message', 'dev@example.test');
    }

    public function test_ignore_marks_the_detection_ignored(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $job = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);

        $service->ignore($job, 'dev@example.test');

        $this->assertSame(LocalChangeDetectionJob::STATUS_IGNORED, $job->fresh()->status);
    }

    public function test_ignore_rejects_an_already_closed_detection(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $job = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);
        $service->ignore($job, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->ignore($job, 'dev@example.test');
    }

    public function test_a_new_report_after_a_detection_was_published_starts_a_fresh_row(): void
    {
        $mapping = $this->mappingWithMode(LocalProjectMapping::MODE_APPROVAL);
        $service = app(LocalChangeDetectionService::class);
        $first = $service->report($mapping->localAgent, $mapping->project, ['app/Foo.php']);
        $service->ignore($first, 'dev@example.test');

        $second = $service->report($mapping->localAgent, $mapping->project, ['app/Bar.php']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, LocalChangeDetectionJob::count());
    }
}
