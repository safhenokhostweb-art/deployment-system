<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.5: Retry for a failed Publish — closes the "git push failed"
 * error-recovery gap. Reuses PublishJobService::queueApprovedPublish()
 * verbatim (the same method ChangeDetectionController's approve() and
 * AutoPublishStableChanges already call) — no new git/service logic, just
 * a controller action that requeues the same commit message.
 */
class PublishRetryTest extends TestCase
{
    use RefreshDatabase;

    private function failedPublishJob(): array
    {
        $project = Project::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);
        LocalProjectMapping::where('local_agent_id', $agent->id)->where('project_id', $project->id)->first()->update(['local_head_sha' => str_repeat('a', 40), 'working_tree_dirty' => true]);
        $service = app(PublishJobService::class);

        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);
        $service->approve($job, 'Fix the widget', 'dev@example.test');
        $service->reportFailure($job, 'git push rejected (non-fast-forward)');

        return [$project, $job];
    }

    public function test_retry_queues_a_new_approved_publish_with_the_same_commit_message(): void
    {
        $user = User::factory()->create();
        [$project, $job] = $this->failedPublishJob();

        $response = $this->actingAs($user)->post(route('projects.publish.retry', [$project, $job]));

        $newJob = PublishJob::orderByDesc('id')->first();
        $response->assertRedirect(route('projects.publish.show', [$project, $newJob]));
        $this->assertNotSame($job->id, $newJob->id);
        $this->assertSame(PublishJob::ACTION_PUBLISH, $newJob->action);
        $this->assertSame(PublishJob::STATUS_APPROVED, $newJob->status);
        $this->assertSame('Fix the widget', $newJob->commit_message);
    }

    public function test_retry_rejects_a_job_that_is_not_failed(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $this->actingAs($user)->post(route('projects.publish.retry', [$project, $job]))->assertStatus(409);
    }

    public function test_retry_rejects_a_failed_rollback_job(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');
        $service->approveRollback($job, 'dev@example.test');
        $service->reportFailure($job, 'git revert failed');

        $this->actingAs($user)->post(route('projects.publish.retry', [$project, $job]))->assertStatus(409);
    }

    public function test_viewer_cannot_retry(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        [$project, $job] = $this->failedPublishJob();

        $this->actingAs($viewer)->post(route('projects.publish.retry', [$project, $job]))->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$project, $job] = $this->failedPublishJob();

        $this->post(route('projects.publish.retry', [$project, $job]))->assertRedirect(route('login'));
    }
}
