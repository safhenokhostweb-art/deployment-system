<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\Notification;
use App\Models\OperationJob;
use App\Models\Project;
use App\Models\Server;
use App\Services\Operations\OperationJobService;
use App\Support\Operations\OperationRegistry;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Phase 4-K Task 13: the OperationJob lifecycle over the *same* signed
 * agent channel every other agent endpoint uses — mirrors
 * DiscoveryJobFlowTest's structure exactly.
 */
class OperationJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_agent_claims_a_queued_server_group_operation(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::DISK_CHECK, null, 'admin@example.test');

        $headers = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/operation-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $response->assertJsonPath('job.operation_type', OperationRegistry::DISK_CHECK);
        $response->assertJsonPath('job.project_key', null);
        $this->assertSame(OperationJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_agent_claims_a_laravel_group_operation_with_its_project_key(): void
    {
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['server_id' => $agent->server_id, 'project_key' => 'saas-platform']);
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::CACHE_CLEAR, $project, 'admin@example.test');

        $headers = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/operation-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.operation_type', OperationRegistry::CACHE_CLEAR);
        $response->assertJsonPath('job.project_key', 'saas-platform');
    }

    public function test_legacy_server_credential_never_claims_an_operation_job(): void
    {
        // Only reachable via the "New Agent" identity path — mirrors the
        // Discovery/DeploymentTask Agent-only claiming precedent.
        $legacyServer = Server::factory()->create();
        $legacyToken = \Database\Factories\ServerFactory::$lastPlainToken;
        $legacySecret = \Database\Factories\ServerFactory::$lastPlainSecret;

        $headers = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $legacyServer->public_id, $legacyToken, $legacySecret);
        $this->get('/api/agent/operation-jobs/next', $headers)->assertOk()->assertJson(['job' => null]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $agent = Agent::factory()->approved()->create();
        $headers = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $headers['X-Deployment-Signature'] = 'tampered-signature';

        $this->get('/api/agent/operation-jobs/next', $headers)->assertStatus(401);
    }

    public function test_agent_reports_a_successful_operation(): void
    {
        \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::SYSTEM_HEALTH, null, 'admin@example.test');

        $reportBody = ['status' => 'succeeded', 'output' => "load average: 0.10\nMem: 1024 512"];
        $path = "/api/agent/operation-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => 'succeeded']);

        $job->refresh();
        $this->assertSame(OperationJob::STATUS_SUCCEEDED, $job->status);
        $this->assertStringContainsString('load average', $job->output);
        $this->assertDatabaseHas('notifications', ['type' => Notification::TYPE_OPERATION_SUCCESS, 'related_type' => OperationJob::class, 'related_id' => $job->id]);
    }

    public function test_agent_reports_a_failed_operation(): void
    {
        \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::DISK_CHECK, null, 'admin@example.test');

        $reportBody = ['status' => 'failed', 'error_message' => 'df: command not found'];
        $path = "/api/agent/operation-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => 'failed']);

        $job->refresh();
        $this->assertSame(OperationJob::STATUS_FAILED, $job->status);
        $this->assertDatabaseHas('notifications', ['type' => Notification::TYPE_OPERATION_FAILED, 'related_type' => OperationJob::class, 'related_id' => $job->id]);
    }

    public function test_reported_output_is_redacted_before_persisting(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::SYSTEM_HEALTH, null, 'admin@example.test');

        $reportBody = ['status' => 'succeeded', 'output' => 'token=ghp_1234567890abcdef1234567890abcdef1234'];
        $path = "/api/agent/operation-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk();

        $job->refresh();
        $this->assertStringNotContainsString('ghp_1234567890abcdef1234567890abcdef1234', $job->output);
        $this->assertStringContainsString('[REDACTED]', $job->output);
    }

    public function test_agent_cannot_report_on_another_agents_operation_job(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(OperationJobService::class)->queue($agent->server, $agent, OperationRegistry::DISK_CHECK, null, 'admin@example.test');

        $otherAgent = Agent::factory()->approved()->create();
        $otherToken = AgentFactory::$lastPlainToken;
        $otherSecret = AgentFactory::$lastPlainSecret;

        $reportBody = ['status' => 'failed', 'error_message' => 'nope'];
        $path = "/api/agent/operation-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $otherAgent->public_id, $otherToken, $otherSecret);

        $this->postJson($path, $reportBody, $headers)->assertStatus(403);
    }

    public function test_heartbeat_accepts_optional_health_metrics(): void
    {
        $agent = Agent::factory()->approved()->create();

        $body = ['agent_version' => '1.2.0', 'cpu_load_percent' => 12.5, 'memory_usage_percent' => 40.2, 'disk_usage_percent' => 55.0];
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson('/api/agent/heartbeat', $body, $headers)->assertOk();

        $agent->refresh();
        $this->assertEqualsWithDelta(12.5, $agent->cpu_load_percent, 0.01);
        $this->assertEqualsWithDelta(40.2, $agent->memory_usage_percent, 0.01);
        $this->assertEqualsWithDelta(55.0, $agent->disk_usage_percent, 0.01);
        $this->assertNotNull($agent->health_reported_at);
    }

    public function test_heartbeat_without_health_metrics_still_works_exactly_as_before(): void
    {
        // Compatibility: a legacy agent binary that only ever sends
        // agent_version must keep working unchanged.
        $agent = Agent::factory()->approved()->create();

        $body = ['agent_version' => '1.0.0'];
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson('/api/agent/heartbeat', $body, $headers)->assertOk()->assertJson(['status' => 'ok']);

        $agent->refresh();
        $this->assertNull($agent->cpu_load_percent);
        $this->assertNull($agent->health_reported_at);
    }
}
