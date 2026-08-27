<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\Project;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\PublishJob;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessGitHubWebhookPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_creates_a_release_without_deployment(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);

        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $this->assertDatabaseHas('releases', [
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
        ]);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_retry_and_timeout_configuration_is_conservative(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', null);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    public function test_handle_automatically_queues_exact_commit_artifact_on_authoritative_agent(): void
    {
        $sha = str_repeat('d', 40);
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'source_workspace_path' => 'C:\\workspaces\\project',
            'git_branch' => 'main',
            'local_head_sha' => $sha,
            'remote_tracking_sha' => $sha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        $job = new ProcessGitHubWebhookPush($project->fresh(), $sha, 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $release = Release::sole();
        $build = PublishJob::sole();
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $build->action);
        $this->assertSame($sha, $build->reviewed_head_sha);
        $this->assertSame($agent->id, $build->local_agent_id);
        $this->assertSame($release->id, $build->release_id);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_permanent_failure_is_audit_logged(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');

        $job->failed(new RuntimeException('deadlock detected'));

        $this->assertDatabaseHas('audit_log_entries', [
            'actor_type' => 'system',
            'action' => 'webhook.release_preparation_failed',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
        ]);
    }
}
