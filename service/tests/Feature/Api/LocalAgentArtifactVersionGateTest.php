<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase: Laravel Release Completeness — the artifact upload endpoint must
 * reject a Local Agent too old to embed the in-artifact release manifest
 * (fail-closed), instead of letting an unverifiable artifact through.
 */
class LocalAgentArtifactVersionGateTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_artifact_create_is_rejected_for_an_outdated_local_agent(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.4.2']);
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => str_repeat('b', 64), 'size_bytes' => 16];
        $path = '/api/local-agent/artifacts';
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $response = $this->postJson($path, $body, $headers);

        $response->assertStatus(409);
    }

    public function test_artifact_create_is_rejected_for_a_local_agent_with_unknown_version(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => null]);
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => str_repeat('b', 64), 'size_bytes' => 16];
        $path = '/api/local-agent/artifacts';
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(409);
    }

    public function test_artifact_create_is_accepted_for_a_current_local_agent(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.4.3']);
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => str_repeat('b', 64), 'size_bytes' => 16];
        $path = '/api/local-agent/artifacts';
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(201);
    }
}