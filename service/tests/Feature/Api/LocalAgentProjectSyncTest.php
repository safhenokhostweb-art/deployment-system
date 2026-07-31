<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 6 (project-config sync): the endpoint deployment-local-agent's
 * ProjectConfigCache/syncProjectConfig() downloads from — replaces the
 * agent's own manually-maintained config.php `projects` array. Same
 * signed channel and identity resolution as every other Local Agent API
 * (see LocalAgentControlJobFlowTest), just a GET with no side effects.
 */
class LocalAgentProjectSyncTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function headersFor(LocalAgent $agent, string $method, string $path, string $token, string $secret): array
    {
        return $this->localAgentHeaders($method, $path, '', $agent->public_id, $token, $secret);
    }

    public function test_returns_only_projects_mapped_to_the_authenticated_agent(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        // Captured immediately — creating $otherAgent below overwrites the
        // factory's shared static plain-credential fields, since only the
        // hash/encrypted form is ever persisted to the model itself.
        $agentToken = LocalAgentFactory::$lastPlainToken;
        $agentSecret = LocalAgentFactory::$lastPlainSecret;
        $otherAgent = LocalAgent::factory()->approved()->create();

        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $otherProject = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);

        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_path' => 'C:\\laragon\\www\\saas-platform',
            'remote_name' => 'origin',
            'git_branch' => null,
        ]);

        // Mapped to a different agent entirely — must never appear in
        // $agent's own response.
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $otherAgent->id,
            'project_id' => $otherProject->id,
        ]);

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/projects', $agentToken, $agentSecret);
        $response = $this->get('/api/local-agent/projects', $headers)->assertOk();

        $response->assertJsonCount(1, 'projects');
        $response->assertJsonPath('projects.0.project_key', 'saas-platform');
        $response->assertJsonPath('projects.0.local_path', 'C:\\laragon\\www\\saas-platform');
        $response->assertJsonPath('projects.0.remote_name', 'origin');
        $response->assertJsonPath('projects.0.branch', 'main');
        $response->assertJsonPath('config_version', $agent->fresh()->projectConfigVersion());
    }

    public function test_config_version_changes_after_a_new_mapping_is_added(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $agentToken = LocalAgentFactory::$lastPlainToken;
        $agentSecret = LocalAgentFactory::$lastPlainSecret;

        $firstResponse = $this->get('/api/local-agent/projects', $this->headersFor($agent, 'GET', '/api/local-agent/projects', $agentToken, $agentSecret))->assertOk();
        $firstVersion = $firstResponse->json('config_version');

        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        $secondResponse = $this->get('/api/local-agent/projects', $this->headersFor($agent, 'GET', '/api/local-agent/projects', $agentToken, $agentSecret))->assertOk();

        $this->assertNotSame($firstVersion, $secondResponse->json('config_version'));
    }

    public function test_branch_falls_back_to_the_project_branch_when_mapping_has_none(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['project_key' => 'family-fund', 'branch' => 'production']);

        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'git_branch' => null,
        ]);

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/projects', LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $response = $this->get('/api/local-agent/projects', $headers)->assertOk();

        $response->assertJsonPath('projects.0.branch', 'production');
    }

    public function test_mapping_branch_override_wins_over_project_branch(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['project_key' => 'family-fund', 'branch' => 'production']);

        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'git_branch' => 'staging',
        ]);

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/projects', LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $response = $this->get('/api/local-agent/projects', $headers)->assertOk();

        $response->assertJsonPath('projects.0.branch', 'staging');
    }

    public function test_excludes_mappings_belonging_to_an_inactive_project(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $inactiveProject = Project::factory()->create(['is_active' => false]);

        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $inactiveProject->id,
        ]);

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/projects', LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $response = $this->get('/api/local-agent/projects', $headers)->assertOk();

        $response->assertJsonCount(0, 'projects');
    }

    public function test_returns_empty_list_when_agent_has_no_mappings(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/projects', LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $response = $this->get('/api/local-agent/projects', $headers)->assertOk();

        $response->assertJsonCount(0, 'projects');
    }

    public function test_rejects_a_request_without_a_valid_signature(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $this->get('/api/local-agent/projects', [
            'Authorization' => 'Bearer wrong-token',
            'X-Deployment-Local-Agent-Id' => $agent->public_id,
            'X-Deployment-Timestamp' => (string) time(),
            'X-Deployment-Nonce' => bin2hex(random_bytes(16)),
            'X-Deployment-Signature' => 'not-a-real-signature',
        ])->assertStatus(401);
    }

    public function test_rejects_a_request_from_an_unapproved_agent(): void
    {
        $agent = LocalAgent::factory()->create(); // pending_approval, no token/secret issued

        $this->get('/api/local-agent/projects', [
            'Authorization' => 'Bearer whatever',
            'X-Deployment-Local-Agent-Id' => $agent->public_id,
            'X-Deployment-Timestamp' => (string) time(),
            'X-Deployment-Nonce' => bin2hex(random_bytes(16)),
            'X-Deployment-Signature' => 'irrelevant',
        ])->assertStatus(401);
    }
}
