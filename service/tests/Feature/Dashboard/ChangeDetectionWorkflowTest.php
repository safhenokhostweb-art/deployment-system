<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\Publish\LocalChangeDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.2: the dashboard side of a detected change — approve/ignore,
 * and the per-mapping Deployment Mode selector. Reuses the exact same
 * 'local_agents.publish'/'local_agents.manage' gates Phase 5 already
 * established.
 */
class ChangeDetectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function mappingWithDetection(string $mode = LocalProjectMapping::MODE_APPROVAL): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'deployment_mode' => $mode,
        ]);
        $job = app(LocalChangeDetectionService::class)->report($agent, $project, ['app/Foo.php']);

        return [$mapping, $job];
    }

    public function test_change_detection_show_page_renders(): void
    {
        $user = User::factory()->create();
        [$mapping, $job] = $this->mappingWithDetection();

        $this->actingAs($user)->get(route('projects.changes.show', [$mapping->project, $job]))
            ->assertOk()
            ->assertSee('app/Foo.php');
    }

    public function test_developer_can_approve_a_detected_change(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        [$mapping, $job] = $this->mappingWithDetection();

        $response = $this->actingAs($developer)->post(route('projects.changes.approve', [$mapping->project, $job]), [
            'commit_message' => 'Fix the widget',
        ]);

        $job->refresh();
        $this->assertSame(LocalChangeDetectionJob::STATUS_APPROVED, $job->status);
        $this->assertNotNull($job->publish_job_id);

        $publishJob = PublishJob::find($job->publish_job_id);
        $response->assertRedirect(route('projects.publish.show', [$mapping->project, $publishJob]));
    }

    public function test_approve_without_a_commit_message_uses_the_suggested_one(): void
    {
        $user = User::factory()->create();
        [$mapping, $job] = $this->mappingWithDetection();

        $this->actingAs($user)->post(route('projects.changes.approve', [$mapping->project, $job]), []);

        $publishJob = PublishJob::find($job->fresh()->publish_job_id);
        $this->assertSame($job->suggested_commit_message, $publishJob->commit_message);
    }

    public function test_developer_can_ignore_a_detected_change(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        [$mapping, $job] = $this->mappingWithDetection();

        $this->actingAs($developer)->post(route('projects.changes.ignore', [$mapping->project, $job]))
            ->assertRedirect(route('projects.show', $mapping->project));

        $this->assertSame(LocalChangeDetectionJob::STATUS_IGNORED, $job->fresh()->status);
    }

    public function test_viewer_cannot_approve_or_ignore(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        [$mapping, $job] = $this->mappingWithDetection();

        $this->actingAs($viewer)->post(route('projects.changes.approve', [$mapping->project, $job]))->assertStatus(403);
        $this->actingAs($viewer)->post(route('projects.changes.ignore', [$mapping->project, $job]))->assertStatus(403);
    }

    public function test_admin_can_change_the_deployment_mode(): void
    {
        $admin = User::factory()->create();
        $mapping = LocalProjectMapping::factory()->create(['deployment_mode' => LocalProjectMapping::MODE_APPROVAL]);
        $localAgent = $mapping->localAgent;

        $this->actingAs($admin)->post(route('local-agents.mappings.deployment-mode', [$localAgent, $mapping]), [
            'deployment_mode' => LocalProjectMapping::MODE_AUTO,
        ])->assertRedirect(route('local-agents.show', $localAgent));

        $this->assertSame(LocalProjectMapping::MODE_AUTO, $mapping->fresh()->deployment_mode);
    }

    public function test_developer_cannot_change_the_deployment_mode(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $mapping = LocalProjectMapping::factory()->create(['deployment_mode' => LocalProjectMapping::MODE_APPROVAL]);

        $this->actingAs($developer)->post(route('local-agents.mappings.deployment-mode', [$mapping->localAgent, $mapping]), [
            'deployment_mode' => LocalProjectMapping::MODE_AUTO,
        ])->assertStatus(403);
    }

    public function test_invalid_deployment_mode_is_rejected(): void
    {
        $admin = User::factory()->create();
        $mapping = LocalProjectMapping::factory()->create(['deployment_mode' => LocalProjectMapping::MODE_APPROVAL]);

        $this->actingAs($admin)->post(route('local-agents.mappings.deployment-mode', [$mapping->localAgent, $mapping]), [
            'deployment_mode' => 'not-a-real-mode',
        ])->assertSessionHasErrors('deployment_mode');
    }

    public function test_project_show_page_renders_watch_status_when_a_change_is_open(): void
    {
        $user = User::factory()->create();
        [$mapping, $job] = $this->mappingWithDetection();

        $this->actingAs($user)->get(route('projects.show', $mapping->project))
            ->assertOk()
            ->assertSee(__('ui.local_agents.view_changes_button'));
    }
}
