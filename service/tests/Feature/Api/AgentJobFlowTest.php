<?php

namespace Tests\Feature\Api;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

class AgentJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    private function makeServerWithCredentials(): array
    {
        $server = Server::factory()->create();

        return [$server, ServerFactory::$lastPlainToken, ServerFactory::$lastPlainSecret];
    }

    public function test_agent_can_claim_queued_job_with_valid_signature(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);

        Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);

        $response = $this->get('/api/agent/jobs/next', $headers);

        $response->assertOk();
        $response->assertJsonPath('job.project_key', $project->project_key);
        $this->assertNotEmpty($response->json('signature'));

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'status' => Deployment::STATUS_RUNNING,
        ]);
    }

    public function test_second_concurrent_poll_does_not_receive_the_same_job(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);

        Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $headers1 = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/jobs/next', $headers1)->assertJsonPath('job.commit_hash', str_repeat('a', 40));

        $headers2 = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/jobs/next', $headers2)->assertJson(['job' => null]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        [$server, , $secret] = $this->makeServerWithCredentials();

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, 'wrong-token', $secret);

        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $headers['X-Deployment-Signature'] = str_repeat('0', 64);

        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();

        $staleTimestamp = (string) (time() - 3600);
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret, $staleTimestamp);

        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }

    public function test_replayed_request_is_rejected(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret, $timestamp, $nonce);

        $this->get('/api/agent/jobs/next', $headers)->assertOk();
        // Exact same timestamp+nonce+signature replayed.
        $this->get('/api/agent/jobs/next', $headers)->assertStatus(401);
    }

    public function test_full_happy_path_log_and_status_reporting(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/jobs/next', $headers)->assertOk();

        $logBody = [
            'sequence' => 1,
            'step_name' => 'git pull',
            'command' => 'git fetch origin main',
            'output' => 'Already up to date.',
            'exit_code' => 0,
            'duration_ms' => 120,
        ];
        $logPath = "/api/agent/jobs/{$deployment->public_id}/log";
        $logHeaders = $this->agentHeaders('POST', $logPath, json_encode($logBody), $server->public_id, $token, $secret);
        $this->postJson($logPath, $logBody, $logHeaders)->assertCreated();

        $statusBody = ['status' => 'succeeded'];
        $statusPath = "/api/agent/jobs/{$deployment->public_id}/status";
        $statusHeaders = $this->agentHeaders('POST', $statusPath, json_encode($statusBody), $server->public_id, $token, $secret);
        $this->postJson($statusPath, $statusBody, $statusHeaders)->assertOk();

        $this->assertDatabaseHas('deployments', ['id' => $deployment->id, 'status' => Deployment::STATUS_SUCCEEDED]);
        $this->assertDatabaseHas('deployment_log_entries', ['deployment_id' => $deployment->id, 'step_name' => 'git pull']);
    }

    public function test_log_output_is_redacted_before_being_stored(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $this->get('/api/agent/jobs/next', $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret))
            ->assertOk();

        $logBody = [
            'sequence' => 1,
            'step_name' => 'deploy env',
            'command' => 'export API_KEY=sk_live_leakedsecret123',
            'output' => 'Authenticating with ghp_' . str_repeat('a', 36),
            'exit_code' => 0,
        ];
        $logPath = "/api/agent/jobs/{$deployment->public_id}/log";
        $this->postJson($logPath, $logBody, $this->agentHeaders('POST', $logPath, json_encode($logBody), $server->public_id, $token, $secret))
            ->assertCreated();

        $stored = $deployment->logEntries()->first();
        $this->assertStringNotContainsString('sk_live_leakedsecret123', $stored->command);
        $this->assertStringNotContainsString('ghp_' . str_repeat('a', 36), $stored->output);
        $this->assertStringContainsString('[REDACTED]', $stored->command);
    }

    public function test_agent_cannot_report_on_a_deployment_belonging_to_another_server(): void
    {
        [$serverA, $tokenA, $secretA] = $this->makeServerWithCredentials();
        [$serverB] = $this->makeServerWithCredentials();
        $projectB = Project::factory()->create(['server_id' => $serverB->id]);

        $deploymentOnB = Deployment::create([
            'project_id' => $projectB->id,
            'server_id' => $serverB->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $statusBody = ['status' => 'succeeded'];
        $path = "/api/agent/jobs/{$deploymentOnB->public_id}/status";
        $headers = $this->agentHeaders('POST', $path, json_encode($statusBody), $serverA->public_id, $tokenA, $secretA);

        $this->postJson($path, $statusBody, $headers)->assertStatus(403);
    }
}
