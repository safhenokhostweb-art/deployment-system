<?php

namespace Tests\Feature\Api;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\PublishJob;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix (Deployment #184 — independent artifact verification
 * without a Deployment record): read-only status + physical integrity
 * preflight for Server Agents. See ArtifactVerificationController.
 */
class ArtifactVerificationTest extends TestCase
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

    public function test_verified_artifact_returns_metadata_without_deployment(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'verified-artifact-contents';
        $artifact = Artifact::factory()->physicallyAvailable($content)->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        // No Deployment is created — this endpoint must not require one.

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $response = $this->get($path, $headers);

        $response->assertOk();
        $response->assertJson([
            'public_id' => $artifact->public_id,
            'status' => Artifact::STATUS_AVAILABLE,
            'commit_hash' => $artifact->commit_hash,
            'branch' => $artifact->branch,
            'checksum_sha256' => $artifact->checksum_sha256,
            'size_bytes' => strlen($content),
            'verified' => true,
        ]);
        // Internal storage details must never leak to the agent.
        $response->assertJsonMissing(['disk_path']);
        // No Deployment or Publish Job should be created by a read-only verify.
        $this->assertCount(0, Deployment::where('artifact_id', $artifact->id)->get());
        $this->assertCount(0, PublishJob::all());
    }

    public function test_unknown_artifact_public_id_returns_404(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $unknownId = \Illuminate\Support\Str::uuid();

        $path = '/api/agent/artifacts/' . $unknownId . '/verify';
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $response = $this->get($path, $headers);

        $response->assertStatus(404);
        $response->assertJson([
            'public_id' => $unknownId,
            'status' => 'not_found',
            'verified' => false,
            'reason' => 'artifact_not_found',
        ]);
    }

    public function test_non_available_artifact_returns_409(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_UPLOADING,
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)
            ->assertStatus(409)
            ->assertJson([
                'public_id' => $artifact->public_id,
                'status' => Artifact::STATUS_UPLOADING,
                'verified' => false,
                'reason' => 'artifact_unavailable',
            ]);
    }

    public function test_missing_file_from_storage_returns_409(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'disk_path' => 'never/written.zip',
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)
            ->assertStatus(409)
            ->assertJson([
                'public_id' => $artifact->public_id,
                'status' => Artifact::STATUS_AVAILABLE,
                'verified' => false,
                'reason' => 'artifact_file_missing',
            ]);
    }

    public function test_size_mismatch_returns_409(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'verified-artifact-contents';
        $artifact = Artifact::factory()->physicallyAvailable($content)->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'size_bytes' => 999999, // deliberately wrong
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)
            ->assertStatus(409)
            ->assertJson([
                'public_id' => $artifact->public_id,
                'status' => Artifact::STATUS_AVAILABLE,
                'verified' => false,
                'reason' => 'artifact_size_mismatch',
            ]);
    }

    public function test_checksum_mismatch_returns_409(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'verified-artifact-contents';
        $artifact = Artifact::factory()->physicallyAvailable($content)->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'checksum_sha256' => '0000000000000000000000000000000000000000000000000000000000000000', // deliberately wrong
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)
            ->assertStatus(409)
            ->assertJson([
                'public_id' => $artifact->public_id,
                'status' => Artifact::STATUS_AVAILABLE,
                'verified' => false,
                'reason' => 'artifact_checksum_mismatch',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";

        $this->get($path)->assertStatus(401);
    }

    public function test_response_does_not_leak_internal_paths_or_secrets(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'verified-artifact-contents';
        $artifact = Artifact::factory()->physicallyAvailable($content)->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $response = $this->get($path, $headers);

        $response->assertOk();
        $response->assertJsonMissing([
            'disk_path',
            'signing_secret',
            'agent_token',
        ]);
    }

    public function test_verify_creates_no_deployment_or_publish_job(): void
    {
        [$server, $token, $secret] = $this->makeServerWithCredentials();
        $project = Project::factory()->create(['server_id' => $server->id]);
        $content = 'verified-artifact-contents';
        $artifact = Artifact::factory()->physicallyAvailable($content)->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $path = "/api/agent/artifacts/{$artifact->public_id}/verify";
        $headers = $this->agentHeaders('GET', $path, '', $server->public_id, $token, $secret);

        $this->get($path, $headers)->assertOk();

        $this->assertCount(0, Deployment::all());
        $this->assertCount(0, PublishJob::all());
    }
}
