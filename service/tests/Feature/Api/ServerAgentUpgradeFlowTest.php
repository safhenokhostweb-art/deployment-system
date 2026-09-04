<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\Release;
use App\Models\ServerAgentControlJob;
use App\Models\ServerAgentPackage;
use App\Models\ServerAgentPackageBuildJob;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsAgentRequests;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Full end-to-end exact-commit Server Agent self-upgrade flow, exercised
 * exactly as the real Local Agent and Server Agent processes would call
 * it: materialize+upload a package (Local Agent), queue an upgrade
 * (Dashboard), poll/download/report (Server Agent), verify by heartbeat.
 */
class ServerAgentUpgradeFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;
    use SignsLocalAgentRequests;

    private function builderHeaders(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    private function agentHeadersFor(Agent $agent, string $token, string $secret, string $method, string $path, string $body = ''): array
    {
        return $this->agentHeaders($method, $path, $body, $agent->public_id, $token, $secret);
    }

    public function test_full_flow_from_package_materialization_to_verified_server_agent_upgrade(): void
    {
        Storage::fake('server-agent-packages');

        $commit = str_repeat('a', 40);
        $project = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $builder = LocalAgent::factory()->productionBuild()->create();
        $project->update(['authoritative_build_local_agent_id' => $builder->id]);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'master']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'master',
            'finished_at' => now(),
        ]);

        // --- Local Agent: poll for a package build job (none queued yet) ---
        $this->get('/api/local-agent/server-agent-package-jobs/next', $this->builderHeaders($builder, 'GET', '/api/local-agent/server-agent-package-jobs/next'))
            ->assertOk()
            ->assertJson(['job' => null]);

        // --- Admin queues the upgrade: no package exists yet, so it fails
        // closed and auto-queues a build job (see
        // ServerAgentControlJobServiceTest for the unit-level proof of
        // this specific behavior). ---
        $admin = User::factory()->create();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $agentToken = AgentFactory::$lastPlainToken;
        $agentSecret = AgentFactory::$lastPlainSecret;

        $this->actingAs($admin)->post(route('agents.upgrade', $agent))->assertStatus(409);
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);

        // --- Local Agent: claim, materialize (simulated), upload ---
        $this->get('/api/local-agent/server-agent-package-jobs/next', $this->builderHeaders($builder, 'GET', '/api/local-agent/server-agent-package-jobs/next'))
            ->assertOk()
            ->assertJsonPath('job.commit_hash', $commit);

        $buildJob = ServerAgentPackageBuildJob::sole();
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_RUNNING, $buildJob->status);

        $content = 'fake-server-agent-package-zip-bytes';
        $reportBody = [
            'status' => 'succeeded',
            'commit_hash' => $commit,
            'repository_mapping' => 'deployment-system',
            'agent_version' => '1.4.1',
            'tree_hash' => str_repeat('9', 40),
            'checksum_sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
            'content_base64' => base64_encode($content),
        ];
        $reportPath = "/api/local-agent/server-agent-package-jobs/{$buildJob->public_id}/report";
        $this->postJson($reportPath, $reportBody, $this->builderHeaders($builder, 'POST', $reportPath, json_encode($reportBody)))
            ->assertOk()
            ->assertJson(['status' => 'succeeded']);

        $package = ServerAgentPackage::sole();
        $this->assertTrue($package->isAvailable());
        $this->assertSame($commit, $package->commit_hash);

        // --- Admin queues the upgrade again: package now exists ---
        $this->actingAs($admin)->post(route('agents.upgrade', $agent))
            ->assertRedirect(route('agents.show', $agent));

        $controlJob = ServerAgentControlJob::sole();
        $this->assertSame(ServerAgentControlJob::STATUS_QUEUED, $controlJob->status);
        $this->assertSame('1.4.1', $controlJob->target_version);

        // --- Server Agent: poll, claim ---
        $polled = $this->get('/api/agent/control-jobs/next', $this->agentHeadersFor($agent, $agentToken, $agentSecret, 'GET', '/api/agent/control-jobs/next'))
            ->assertOk();
        $polled->assertJsonPath('job.target_commit_sha', $commit);
        $polled->assertJsonPath('job.expected_package_hash', $package->checksum_sha256);
        $packageId = $polled->json('job.package_id');
        $this->assertSame($package->public_id, $packageId);

        // --- Server Agent: download the package (authorized: active job references it) ---
        $downloadPath = "/api/agent/server-agent-packages/{$packageId}/download";
        $this->get($downloadPath, $this->agentHeadersFor($agent, $agentToken, $agentSecret, 'GET', $downloadPath))
            ->assertOk()
            ->assertHeader('X-Server-Agent-Package-Checksum-Sha256', $package->checksum_sha256);

        // --- A DIFFERENT, unrelated approved Server Agent must NOT be able
        // to download the same package (no active job references it for
        // that agent) ---
        $otherAgent = Agent::factory()->approved()->create();
        $this->get($downloadPath, $this->agentHeadersFor($otherAgent, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret, 'GET', $downloadPath))
            ->assertStatus(403);

        // --- Server Agent: report upgrade started with verified evidence ---
        $startBody = ['status' => 'started', 'source_commit' => $commit, 'package_hash' => $package->checksum_sha256, 'output' => 'validated'];
        $startPath = "/api/agent/control-jobs/{$controlJob->public_id}/report";
        $this->postJson($startPath, $startBody, $this->agentHeadersFor($agent, $agentToken, $agentSecret, 'POST', $startPath, json_encode($startBody)))
            ->assertOk()
            ->assertJson(['status' => 'awaiting_heartbeat']);

        // --- Server Agent process restarts, heartbeats as the new process/version ---
        $heartbeatBody = ['agent_version' => '1.4.1'];
        $this->postJson('/api/agent/heartbeat', $heartbeatBody, $this->agentHeadersFor($agent, $agentToken, $agentSecret, 'POST', '/api/agent/heartbeat', json_encode($heartbeatBody)))
            ->assertOk();

        $this->assertSame(ServerAgentControlJob::STATUS_SUCCEEDED, $controlJob->fresh()->status);
        $this->assertSame('1.4.1', $agent->fresh()->agent_version);
        $this->assertTrue($agent->fresh()->enforcesPreActivationMaintenanceOrdering());
    }
}
