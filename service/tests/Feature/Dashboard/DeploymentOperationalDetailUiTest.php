<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentOperationalDetailUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_successful_deployment_tells_one_operational_story_from_artifact_to_health(): void
    {
        $agent = Agent::factory()->approved()->create([
            'agent_version' => '2.4.0',
            'last_seen_at' => now(),
        ]);
        $project = Project::factory()->create([
            'server_id' => $agent->server_id,
            'environment' => 'production',
            'pipeline' => [['name' => 'activate release', 'command' => 'echo activate']],
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'production']);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'name' => 'web-01',
        ]);
        $commit = str_repeat('a', 40);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commit,
            'checksum_sha256' => str_repeat('b', 64),
            'uploaded_by' => 'builder-01',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $agent->server_id,
            'artifact_id' => $artifact->id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Deployment::STATUS_SUCCEEDED,
            'approved_by' => 'operator@example.test',
            'approved_at' => now()->subMinutes(4),
            'queued_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'deployed_at' => now()->subMinute(),
            'http_health_config' => [
                'enabled' => true,
                'url' => 'https://customer.example/health',
                'expected_status' => 200,
            ],
            'http_health_status' => Deployment::HTTP_HEALTH_VERIFIED,
            'http_health_verified_at' => now()->subMinute(),
            'http_health_details' => [
                'duration_ms' => 84,
                'output' => 'HTTP 200 OK',
                'exit_code' => 0,
            ],
        ]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_SUCCEEDED,
        ]);
        AgentJob::create([
            'deployment_task_id' => $task->id,
            'agent_id' => $agent->id,
            'attempt' => 1,
            'status' => AgentJob::STATUS_SUCCEEDED,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);
        DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
            'sequence' => 1,
            'step_name' => 'activate release',
            'command' => 'echo activate',
            'output' => 'activated',
            'exit_code' => 0,
            'duration_ms' => 250,
        ]);

        $response = $this->actingAs($this->admin())->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertSee('ui-deployment-verdict--success', false);
        $response->assertSee(__('deployment_ui.verdict.succeeded_title'));
        $response->assertSee($deployment->public_id);
        $response->assertSee($artifact->public_id);
        $response->assertSee(str_repeat('b', 64));
        $response->assertSee('builder-01');
        $response->assertSee('https://customer.example/health');
        $response->assertSee('84 ms');
        $response->assertSee('web-01');
        $response->assertSee('2.4.0');
        $response->assertSee('ui-timeline', false);
        $response->assertSee('<details class="ui-log-entry">', false);
        $response->assertDontSee('<details class="ui-log-entry" open>', false);
    }

    public function test_failed_deployment_prioritizes_failure_summary_without_expanding_raw_logs(): void
    {
        $project = Project::factory()->create([
            'pipeline' => [['name' => 'build assets', 'command' => 'npm run build']],
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_FAILED,
            'failure_code' => Deployment::FAILURE_CODE_BUILD_FAILED,
            'failure_message' => 'Asset compilation failed.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
            'sequence' => 1,
            'step_name' => 'build assets',
            'command' => 'npm run build',
            'output' => "Error: compilation failed\nstack frame 1",
            'exit_code' => 1,
            'duration_ms' => 900,
        ]);

        $response = $this->actingAs($this->admin())->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertSee('ui-deployment-verdict--danger', false);
        $response->assertSee(__('deployment_ui.failure_title'));
        $response->assertSee('build_failed');
        $response->assertSee('build assets');
        $response->assertSee('Asset compilation failed.');
        $response->assertSee('Check the step log below');
        $response->assertSee('<details class="ui-log-entry">', false);
        $response->assertDontSee('<details class="ui-log-entry" open>', false);
    }
}
