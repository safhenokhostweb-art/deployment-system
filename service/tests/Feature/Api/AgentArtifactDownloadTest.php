<?php

namespace Tests\Feature\Api;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): the Server Agent
 * downloads its artifact from the Hub — a connection it already needs
 * for polling — instead of ever fetching from GitHub.
 */
class AgentArtifactDownloadTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function makeServerWithCredentials(): array
    {
        $server = Server::factory()->create();

        return [$server, ServerFactory::$lastPlainToken, ServerFactory::$lastPlainSecret];
    }

    public function test_agent_can_download_an_artifact_belonging_to_its_own_deployment(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'zipped-artifact-bytes';
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'disk_path' => "{$project->project_key}/artifact.zip",
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, $content);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $server->id, 'artifact_id' => $artifact->id]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/download";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $response = $this->get($path, $headers);

        $response->assertOk();
        $response->assertHeader('X-Artifact-Checksum-Sha256', $artifact->checksum_sha256);
        $this->assertSame($content, $response->streamedContent());
    }

    public function test_agent_cannot_download_an_artifact_belonging_to_another_servers_deployment(): void
    {
        [$serverA, $tokenA, $secretA] = $this->makeServerWithCredentials();
        [$serverB] = $this->makeServerWithCredentials();
        $projectB = Project::factory()->create(['server_id' => $serverB->id]);
        $artifact = Artifact::factory()->create(['project_id' => $projectB->id, 'status' => Artifact::STATUS_AVAILABLE]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'content');
        Deployment::factory()->create(['project_id' => $projectB->id, 'server_id' => $serverB->id, 'artifact_id' => $artifact->id]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/download";
        $headers = $this->agentHeaders('GET', $path, '', $serverA->public_id, $tokenA, $secretA);

        $this->get($path, $headers)->assertStatus(403);
    }

    public function test_agent_cannot_download_an_artifact_not_yet_finalized(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $artifact = Artifact::factory()->create(['project_id' => $project->id, 'status' => Artifact::STATUS_UPLOADING]);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $server->id, 'artifact_id' => $artifact->id]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/download";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)->assertStatus(409);
    }

    public function test_agent_gets_a_clean_404_when_the_artifact_file_is_missing_from_storage(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $artifact = Artifact::factory()->create(['project_id' => $project->id, 'status' => Artifact::STATUS_AVAILABLE, 'disk_path' => 'never/written.zip']);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $server->id, 'artifact_id' => $artifact->id]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/download";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)->assertStatus(404);
    }

    public function test_unknown_artifact_public_id_returns_404(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();

        $path = '/api/agent/artifacts/' . \Illuminate\Support\Str::uuid() . '/download';
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)->assertStatus(404);
    }
}
