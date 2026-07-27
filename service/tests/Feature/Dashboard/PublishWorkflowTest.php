<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5: the dashboard side of One-Click Publish — queueing a
 * status_check, approving it with a commit message, and rollback. The
 * actual git work is exercised separately by PublishJobFlowTest (the
 * agent-facing HTTP contract) and the standalone local-agent/ binary's
 * own manual verification — this file only covers what the Hub itself
 * does with a PublishJob's lifecycle and its authorization gates.
 */
class PublishWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithApprovedMapping(): Project
    {
        $project = Project::factory()->create();
        $localAgent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $localAgent->id, 'project_id' => $project->id]);

        return $project;
    }

    public function test_developer_can_start_a_publish_status_check(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = $this->projectWithApprovedMapping();
        $mapping = $project->localProjectMappings()->first();

        $response = $this->actingAs($developer)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
        ]);

        $job = PublishJob::first();
        $response->assertRedirect(route('projects.publish.show', [$project, $job]));
        $this->assertSame(PublishJob::ACTION_STATUS_CHECK, $job->action);
        $this->assertSame(PublishJob::STATUS_QUEUED, $job->status);
        $this->assertSame($developer->email, $job->requested_by);
    }

    public function test_publish_requires_an_approved_local_agent_mapping(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = Project::factory()->create(); // no mapping at all

        $this->actingAs($developer)->post(route('projects.publish', $project))->assertStatus(422);
    }

    public function test_approving_a_publish_job_requires_a_commit_message(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php']);

        $this->actingAs($user)->post(route('projects.publish.approve', [$project, $job]), [])
            ->assertSessionHasErrors('commit_message');

        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->fresh()->status);
    }

    public function test_approving_a_publish_job_with_a_commit_message_moves_it_to_approved(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php']);

        $this->actingAs($user)->post(route('projects.publish.approve', [$project, $job]), [
            'commit_message' => 'Fix the widget',
        ])->assertRedirect(route('projects.publish.show', [$project, $job]));

        $fresh = $job->fresh();
        $this->assertSame(PublishJob::STATUS_APPROVED, $fresh->status);
        $this->assertSame(PublishJob::ACTION_PUBLISH, $fresh->action);
        $this->assertSame('Fix the widget', $fresh->commit_message);
        $this->assertSame($user->email, $fresh->approved_by);
    }

    public function test_a_publish_job_with_no_changed_files_cannot_be_approved(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        app(PublishJobService::class)->reportStatusCheck($job, []);

        $this->actingAs($user)->post(route('projects.publish.approve', [$project, $job]), [
            'commit_message' => 'Nothing to publish',
        ])->assertStatus(422);

        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->fresh()->status);
    }

    public function test_rollback_queues_and_immediately_approves_a_rollback_job(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();

        $this->actingAs($user)->post(route('projects.publish.rollback', $project))->assertRedirect();

        $job = PublishJob::first();
        $this->assertSame(PublishJob::ACTION_ROLLBACK, $job->action);
        $this->assertSame(PublishJob::STATUS_APPROVED, $job->status);
    }

    public function test_a_successful_publish_never_creates_a_deployment_directly(): void
    {
        // The Hub side of Publish only ever writes PublishJob rows — any
        // Deployment created after a real push comes from the existing,
        // unmodified GitHub webhook path, never from this code.
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php']);
        app(PublishJobService::class)->approve($job, 'Fix the widget', $user->email);
        app(PublishJobService::class)->reportResult($job, str_repeat('a', 40), 'main -> main');

        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame(0, Deployment::count());
    }

    public function test_viewer_cannot_publish(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $project = $this->projectWithApprovedMapping();

        $this->actingAs($viewer)->post(route('projects.publish', $project))->assertStatus(403);
    }

    public function test_project_show_page_renders_with_a_publish_section(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();

        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
    }

    public function test_publish_status_page_renders_at_every_stage(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $service = app(PublishJobService::class);

        $job = $service->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))->assertOk();

        $service->reportStatusCheck($job, ['app/Foo.php']);
        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))->assertOk()->assertSee('app/Foo.php');

        $service->approve($job, 'Fix the widget', $user->email);
        $service->reportResult($job, str_repeat('a', 40), 'main -> main');
        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))->assertOk()->assertSee(str_repeat('a', 40));
    }

    /**
     * Phase 5.4: "Local Agent disconnected" — a Publish click must never
     * silently sit at "queued" forever with no explanation. LocalAgent::
     * isOnline() already exists (Phase 5); this just surfaces it.
     */
    public function test_project_page_warns_when_the_mapped_local_agent_is_offline(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        // LocalAgentFactory never sets last_seen_at, so this agent is
        // offline by construction — no extra setup needed.

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(__('ui.publish.agent_offline_warning'));
    }

    public function test_project_page_does_not_warn_when_the_mapped_local_agent_is_online(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $project->localProjectMappings()->first()->localAgent->update(['last_seen_at' => now()]);

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee(__('ui.publish.agent_offline_warning'));
    }

    public function test_publish_show_page_shows_an_offline_specific_message_while_queued(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(__('ui.publish.agent_offline_waiting'));
    }

    public function test_publish_show_page_shows_the_normal_waiting_message_when_the_agent_is_online(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $localAgent->update(['last_seen_at' => now()]);
        $job = app(PublishJobService::class)->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(__('ui.publish.waiting_for_agent'))
            ->assertDontSee(__('ui.publish.agent_offline_waiting'));
    }

    /**
     * Phase 5.5: Blocked Files — the operator must see what the agent
     * discarded (e.g. .env), not just what it kept.
     */
    public function test_publish_show_page_renders_blocked_files_when_present(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $service = app(PublishJobService::class);
        $job = $service->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        $service->reportStatusCheck($job, ['app/Foo.php'], ['.env', 'credentials.json']);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(__('ui.publish.blocked_files_title'))
            ->assertSee('.env')
            ->assertSee('credentials.json');
    }

    public function test_publish_show_page_does_not_render_blocked_files_section_when_empty(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $service = app(PublishJobService::class);
        $job = $service->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        $service->reportStatusCheck($job, ['app/Foo.php']);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertDontSee(__('ui.publish.blocked_files_title'));
    }

    /**
     * Phase 5.5: post-success status linking — PublishJob and Deployment
     * are looked up by (project_id, commit_hash) only; no FK, no new
     * write path into either table.
     */
    public function test_publish_show_page_shows_waiting_for_webhook_when_no_deployment_matches_yet(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $service = app(PublishJobService::class);
        $job = $service->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        $service->reportStatusCheck($job, ['app/Foo.php']);
        $service->approve($job, 'Fix the widget', $user->email);
        $service->reportResult($job, str_repeat('a', 40), 'main -> main');

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(__('ui.publish.deployment_awaiting_webhook'));
    }

    public function test_publish_show_page_shows_the_matching_deployment_when_the_webhook_already_arrived(): void
    {
        $user = User::factory()->create();
        $project = $this->projectWithApprovedMapping();
        $localAgent = $project->localProjectMappings()->first()->localAgent;
        $service = app(PublishJobService::class);
        $job = $service->queue($localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $user->email);
        $service->reportStatusCheck($job, ['app/Foo.php']);
        $service->approve($job, 'Fix the widget', $user->email);
        $service->reportResult($job, str_repeat('b', 40), 'main -> main');

        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
            'status' => Deployment::STATUS_SUCCEEDED,
        ]);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(__('ui.publish.view_deployment_button'))
            ->assertDontSee(__('ui.publish.deployment_awaiting_webhook'))
            ->assertSee(route('deployments.show', $deployment));
    }
}
