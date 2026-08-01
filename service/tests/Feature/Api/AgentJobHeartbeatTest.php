<?php

namespace Tests\Feature\Api;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix (sandogh-family-fund incomplete deploys), Phase 2: the
 * Job-heartbeat channel for the Deployment domain — distinct from
 * /api/agent/heartbeat (the server's general online signal), mirrors
 * PublishJobHeartbeatTest. Renews a running Deployment's lease so a
 * slow-but-alive `npm ci`/Next.js build is never mistaken for a stalled
 * one by deployments:detect-stalled.
 */
class AgentJobHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    private function makeServerWithCredentials(): array
    {
        $server = Server::factory()->create();

        return [$server, ServerFactory::$lastPlainToken, ServerFactory::$lastPlainSecret];
    }

    public function test_agent_renews_the_lease_of_its_own_running_deployment(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_BUILDING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
            'started_at' => now(),
            'lease_expires_at' => now()->addSeconds(10),
        ]);

        $path = "/api/agent/jobs/{$deployment->public_id}/heartbeat";
        $headers = $this->agentHeaders('POST', $path, '', $server->public_id, $token, $secret);

        $response = $this->post($path, [], $headers)->assertOk();
        $response->assertJsonPath('status', Deployment::STATUS_BUILDING);

        $this->assertTrue($deployment->fresh()->lease_expires_at->isAfter(now()->addSeconds(60)));
    }

    public function test_agent_cannot_heartbeat_a_deployment_belonging_to_another_server(): void
    {
        [$serverA, $tokenA, $secretA] = $this->makeServerWithCredentials();
        [$serverB] = $this->makeServerWithCredentials();
        $projectB = Project::factory()->create(['server_id' => $serverB->id]);
        $deploymentOnB = Deployment::create([
            'project_id' => $projectB->id,
            'server_id' => $serverB->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_RUNNING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $path = "/api/agent/jobs/{$deploymentOnB->public_id}/heartbeat";
        $headers = $this->agentHeaders('POST', $path, '', $serverA->public_id, $tokenA, $secretA);

        $this->post($path, [], $headers)->assertStatus(403);
    }

    public function test_heartbeat_for_an_already_terminal_deployment_is_a_no_op_not_an_error(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
            'finished_at' => now(),
        ]);

        $path = "/api/agent/jobs/{$deployment->public_id}/heartbeat";
        $headers = $this->agentHeaders('POST', $path, '', $server->public_id, $token, $secret);

        $response = $this->post($path, [], $headers)->assertOk();
        $response->assertJsonPath('status', Deployment::STATUS_SUCCEEDED);
    }
}
