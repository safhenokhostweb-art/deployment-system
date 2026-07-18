<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessGitHubWebhookPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_creates_a_deployment(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);

        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');
        $job->handle(app(\App\Services\Deployment\DeploymentDispatcher::class));

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
        ]);
    }

    public function test_retry_and_timeout_configuration_is_conservative(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', null);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    public function test_permanent_failure_is_audit_logged(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');

        $job->failed(new RuntimeException('deadlock detected'));

        $this->assertDatabaseHas('audit_log_entries', [
            'actor_type' => 'system',
            'action' => 'webhook.deployment_dispatch_failed',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
        ]);
    }
}
