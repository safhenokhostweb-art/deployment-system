<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\DiscoveryJob;
use App\Services\Discovery\DiscoveryJobService;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Phase 4-H Task 11: the Discovery job lifecycle over the *same* signed
 * agent channel (/api/agent/discovery-jobs/*) every other agent endpoint
 * uses — no new protocol, no new auth scheme. Mirrors AgentTaskClaimTest's
 * structure for the deployment-job flow.
 */
class DiscoveryJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_agent_claims_a_queued_discovery_job(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(DiscoveryJobService::class)->queue($agent, '/home/example/www', 'admin@example.test');

        $headers = $this->agentHeaders('GET', '/api/agent/discovery-jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/discovery-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $response->assertJsonPath('job.workspace_path', '/home/example/www');
        $this->assertSame(DiscoveryJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_legacy_server_credential_never_claims_a_discovery_job(): void
    {
        // A bare legacy Server (no Agent row) polling the discovery
        // endpoint must see nothing — only reachable via the "New Agent"
        // identity path, mirroring AgentTaskService's Agent-only claiming.
        $legacyServer = \App\Models\Server::factory()->create();
        $legacyToken = \Database\Factories\ServerFactory::$lastPlainToken;
        $legacySecret = \Database\Factories\ServerFactory::$lastPlainSecret;

        $headers = $this->agentHeaders('GET', '/api/agent/discovery-jobs/next', '', $legacyServer->public_id, $legacyToken, $legacySecret);
        $this->get('/api/agent/discovery-jobs/next', $headers)->assertOk()->assertJson(['job' => null]);
    }

    public function test_agent_reports_a_successful_scan_result(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(DiscoveryJobService::class)->queue($agent, '/home/example/www', 'admin@example.test');

        $reportBody = [
            'status' => 'succeeded',
            'projects' => [
                [
                    'name' => 'saas-platform',
                    'path' => '/home/example/www/saas-platform',
                    'type' => 'laravel',
                    'framework_version' => '^11.0',
                    'git' => [
                        'remote_url' => 'https://github.com/acme/saas-platform.git',
                        'provider' => 'github',
                        'repository' => 'acme/saas-platform',
                        'branch' => 'main',
                        'commit_hash' => str_repeat('a', 40),
                    ],
                ],
            ],
        ];
        $path = "/api/agent/discovery-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => 'succeeded']);

        $job->refresh();
        $this->assertSame(DiscoveryJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame('saas-platform', $job->result[0]['name']);
        $this->assertSame('acme/saas-platform', $job->result[0]['git']['repository']);
    }

    public function test_reported_secrets_are_redacted_before_persisting(): void
    {
        // Defense in depth (SecretRedactor reuse, Hub-side): even if an
        // agent somehow reported something secret-shaped, it must never
        // reach the database unredacted.
        $agent = Agent::factory()->approved()->create();
        $job = app(DiscoveryJobService::class)->queue($agent, '/home/example/www', 'admin@example.test');

        $reportBody = [
            'status' => 'succeeded',
            'projects' => [
                [
                    'name' => 'token=ghp_1234567890abcdef1234567890abcdef1234',
                    'path' => '/home/example/www/leaky',
                    'type' => 'node',
                ],
            ],
        ];
        $path = "/api/agent/discovery-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk();

        $job->refresh();
        $this->assertStringNotContainsString('ghp_1234567890abcdef1234567890abcdef1234', json_encode($job->result));
        $this->assertStringContainsString('[REDACTED]', $job->result[0]['name']);
    }

    public function test_agent_reports_a_failed_scan(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(DiscoveryJobService::class)->queue($agent, '/no/such/path', 'admin@example.test');

        $reportBody = ['status' => 'failed', 'error_message' => 'Workspace path does not exist.'];
        $path = "/api/agent/discovery-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => 'failed']);

        $job->refresh();
        $this->assertSame(DiscoveryJob::STATUS_FAILED, $job->status);
        $this->assertSame('Workspace path does not exist.', $job->error_message);
    }

    public function test_agent_cannot_report_on_a_discovery_job_belonging_to_another_agent(): void
    {
        $agent = Agent::factory()->approved()->create();
        $job = app(DiscoveryJobService::class)->queue($agent, '/home/example/www', 'admin@example.test');

        $otherAgent = Agent::factory()->approved()->create();
        $otherToken = AgentFactory::$lastPlainToken;
        $otherSecret = AgentFactory::$lastPlainSecret;

        $reportBody = ['status' => 'failed', 'error_message' => 'nope'];
        $path = "/api/agent/discovery-jobs/{$job->public_id}/report";
        $headers = $this->agentHeaders('POST', $path, json_encode($reportBody), $otherAgent->public_id, $otherToken, $otherSecret);

        $this->postJson($path, $reportBody, $headers)->assertStatus(403);
    }
}
