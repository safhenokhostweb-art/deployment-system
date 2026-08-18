<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5: PublishJob's state-machine guard rails — the rules that
 * protect it from ever committing/pushing something the operator never
 * actually approved. The HTTP-facing behavior these guards produce is
 * covered by PublishJobFlowTest/PublishWorkflowTest; this file targets
 * the service methods directly.
 */
class PublishJobServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_rejects_the_publish_action_directly(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_PUBLISH, 'dev@example.test');
    }

    public function test_approve_rejects_a_job_that_is_not_awaiting_approval(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve($job, 'message', 'dev@example.test'); // still queued, not awaiting_approval
    }

    public function test_approve_rejects_a_rollback_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve($job, 'message', 'dev@example.test');
    }

    public function test_approve_rollback_rejects_a_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approveRollback($job, 'dev@example.test');
    }

    public function test_approve_rollback_rejects_a_rollback_job_that_is_already_approved(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');
        $service->approveRollback($job, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approveRollback($job, 'dev@example.test'); // already approved, not queued
    }

    public function test_report_status_check_rejects_a_publish_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->reportStatusCheck($job, ['file.php']);
    }

    /**
     * Phase 5.5: blocked_files is purely informational (spec §3's "Blocked
     * Files" list) — it must never affect files_changed_count or the
     * approval gate, only be stored for display.
     */
    public function test_report_status_check_stores_blocked_files_alongside_changed_files(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $updated = $service->reportStatusCheck($job, ['app/Foo.php'], ['.env', 'credentials.json']);

        $this->assertSame(['app/Foo.php'], $updated->changed_files);
        $this->assertSame(['.env', 'credentials.json'], $updated->blocked_files);
        $this->assertSame(1, $updated->files_changed_count);
    }

    public function test_report_status_check_defaults_blocked_files_to_an_empty_array(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $updated = $service->reportStatusCheck($job, ['app/Foo.php']);

        $this->assertSame([], $updated->blocked_files);
    }

    /**
     * A changed-files review whose captured evidence (mapping, branch, HEAD,
     * working-tree cleanliness) matches the current LocalProjectMapping.
     */
    private function awaitingChangedFilesReview(): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_head_sha' => str_repeat('a', 40),
            'working_tree_dirty' => true,
        ]);
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);

        return [$agent, $project, $mapping, $job];
    }

    // ------------------------------------------------------------------
    // CASE 1: evidence still matches — approval succeeds.
    // ------------------------------------------------------------------

    public function test_approve_succeeds_when_the_reviewed_evidence_still_matches_the_mapping(): void
    {
        [, , , $job] = $this->awaitingChangedFilesReview();

        $approved = app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');

        $this->assertSame(PublishJob::STATUS_APPROVED, $approved->status);
        $this->assertSame(PublishJob::ACTION_PUBLISH, $approved->action);
        $this->assertSame('Fix the widget', $approved->commit_message);
    }

    // ------------------------------------------------------------------
    // CASE 2: mapping HEAD moved — approval rejected, review finalized.
    // ------------------------------------------------------------------

    public function test_approve_rejects_a_review_whose_mapping_head_has_moved(): void
    {
        [, , $mapping, $job] = $this->awaitingChangedFilesReview();
        $mapping->update(['local_head_sha' => str_repeat('b', 40)]); // developer committed meanwhile

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
    }

    public function test_stale_head_approval_finalizes_the_review_as_rejected_and_audits_it(): void
    {
        [, , $mapping, $job] = $this->awaitingChangedFilesReview();
        $mapping->update(['local_head_sha' => str_repeat('b', 40)]);

        try {
            app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
            $this->fail('Expected the stale-evidence approval to be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.review_evidence_stale_rejected',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
            'actor_identifier' => 'dev@example.test',
        ]);
    }

    // ------------------------------------------------------------------
    // CASE 3: branch differs — approval rejected.
    // ------------------------------------------------------------------

    public function test_approve_rejects_a_review_whose_branch_no_longer_matches_the_mapping(): void
    {
        [, , $mapping, $job] = $this->awaitingChangedFilesReview();
        $mapping->update(['git_branch' => 'develop']); // effective branch changed

        try {
            app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
            $this->fail('Expected the branch-mismatched approval to be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
    }

    // ------------------------------------------------------------------
    // CASE 4: working-tree cleanliness differs — approval rejected.
    // ------------------------------------------------------------------

    public function test_approve_rejects_a_review_whose_working_tree_cleanliness_no_longer_matches(): void
    {
        [, , $mapping, $job] = $this->awaitingChangedFilesReview();
        $mapping->update(['working_tree_dirty' => false]); // developer discarded the changes

        try {
            app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
            $this->fail('Expected the cleanliness-mismatched approval to be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
    }

    // ------------------------------------------------------------------
    // CASE 5: a newer review supersedes an older awaiting_approval review
    // for the same local agent + project.
    // ------------------------------------------------------------------

    public function test_a_newer_review_supersedes_an_older_awaiting_approval_review(): void
    {
        [$agent, $project, $mapping, $older] = $this->awaitingChangedFilesReview();
        $mapping->update(['local_head_sha' => str_repeat('b', 40), 'working_tree_dirty' => true]);

        $newer = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->reportStatusCheck($newer, ['app/Bar.php'], [], str_repeat('b', 40), 'main', false);

        $this->assertSame(PublishJob::STATUS_REJECTED, $older->fresh()->status);
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $newer->fresh()->status);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.review_superseded',
            'subject_type' => PublishJob::class,
            'subject_id' => $older->id,
        ]);

        // The superseded review is no longer approvable (terminal status).
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PublishJobService::class)->approve($older->fresh(), 'Fix the widget', 'dev@example.test');
    }

    public function test_a_newer_review_does_not_supersede_a_review_from_a_different_local_agent(): void
    {
        [$agentA, $project, , $reviewA] = $this->awaitingChangedFilesReview();

        $agentB = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agentB->id,
            'project_id' => $project->id,
            'local_head_sha' => str_repeat('a', 40),
            'working_tree_dirty' => true,
        ]);
        $reviewB = app(PublishJobService::class)->queue($agentB, $project, PublishJob::ACTION_STATUS_CHECK, 'dev2@example.test');
        app(PublishJobService::class)->reportStatusCheck($reviewB, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);

        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $reviewA->fresh()->status, 'Another agent\'s review must not be superseded.');
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $reviewB->fresh()->status);
    }
}
