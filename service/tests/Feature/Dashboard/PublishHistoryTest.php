<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.4: the cross-project Publish History page — the one aggregate
 * view that didn't exist before (publish.show is per-project,
 * local-agents.show's "recent publishes" table is per-agent). Reuses the
 * existing PublishJob rows and the existing rollback route/permission —
 * no new business logic to test here beyond the page itself and the
 * rollback-eligibility rule.
 */
class PublishHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_lists_publish_jobs_across_multiple_projects_and_agents(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['name' => 'Project Alpha']);
        $projectB = Project::factory()->create(['name' => 'Project Beta']);
        $agent = LocalAgent::factory()->approved()->create(['name' => 'dev-laptop-a']);

        PublishJob::factory()->create([
            'project_id' => $projectA->id,
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'result_commit_hash' => str_repeat('a', 40),
        ]);
        PublishJob::factory()->create([
            'project_id' => $projectB->id,
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_AWAITING_APPROVAL,
        ]);

        $response = $this->actingAs($user)->get(route('publish.history'));

        $response->assertOk()
            ->assertSee('Project Alpha')
            ->assertSee('Project Beta')
            ->assertSee('dev-laptop-a');
    }

    public function test_empty_state_renders_when_nothing_has_been_published(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('publish.history'))->assertOk();
    }

    public function test_viewer_can_see_history_but_not_rollback_button(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $project = Project::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);
        PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'result_commit_hash' => str_repeat('b', 40),
        ]);

        $this->actingAs($viewer)->get(route('publish.history'))
            ->assertOk()
            ->assertDontSee(__('ui.publish.rollback_button'));
    }

    public function test_rollback_button_appears_only_on_the_most_recent_succeeded_publish_for_its_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $agent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        $older = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'result_commit_hash' => str_repeat('c', 40),
        ]);
        $newer = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'result_commit_hash' => str_repeat('d', 40),
        ]);

        $response = $this->actingAs($user)->get(route('publish.history'));

        $response->assertOk();
        $response->assertSee(route('projects.publish.rollback', $project));

        // Only one rollback form should exist on the page — for the
        // newer of the two succeeded publishes, not the older one.
        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'action="' . route('projects.publish.rollback', $project) . '"'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('publish.history'))->assertRedirect(route('login'));
    }
}
