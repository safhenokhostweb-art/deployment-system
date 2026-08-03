<?php

namespace Tests\Feature\Api;

use App\Models\Artifact;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): the Local Agent
 * uploads a `git archive` artifact here right after a successful push.
 * Two signed phases — metadata (create), then raw file bytes
 * (uploadContent) — see LocalAgentArtifactController's own docblock for
 * why a single multipart request can't work with the existing HMAC
 * middleware (php://input is unavailable for multipart/form-data).
 */
class LocalAgentArtifactFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function mappedAgentAndProject(): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        return [$agent, $project];
    }

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    public function test_create_starts_a_pending_upload_for_a_mapped_project(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $commitHash = str_repeat('a', 40);
        $checksum = hash('sha256', 'fixture content');

        $body = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $checksum, 'size_bytes' => 16];
        $path = '/api/local-agent/artifacts';
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $response = $this->postJson($path, $body, $headers)->assertCreated();

        $this->assertDatabaseHas('artifacts', [
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_UPLOADING,
        ]);
        $this->assertNotEmpty($response->json('artifact_id'));
    }

    public function test_create_rejects_a_project_the_agent_is_not_mapped_to(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(); // deliberately no LocalProjectMapping

        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => hash('sha256', 'x'), 'size_bytes' => 1];
        $path = '/api/local-agent/artifacts';
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertStatus(403);
    }

    public function test_create_rejects_an_unknown_project_key(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['project_key' => 'does-not-exist', 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => hash('sha256', 'x'), 'size_bytes' => 1];
        $path = '/api/local-agent/artifacts';
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertStatus(404);
    }

    public function test_create_rejects_a_claimed_size_over_the_configured_maximum(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        config(['deployment.artifact_max_size_mb' => 1]);

        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('a', 40), 'branch' => 'main', 'checksum_sha256' => hash('sha256', 'x'), 'size_bytes' => 2 * 1024 * 1024];
        $path = '/api/local-agent/artifacts';
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertStatus(422);
    }

    public function test_upload_content_stores_the_file_and_marks_the_artifact_available(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $content = str_repeat('artifact-bytes-', 100);
        $checksum = hash('sha256', $content);
        $commitHash = str_repeat('b', 40);

        $createBody = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $checksum, 'size_bytes' => strlen($content)];
        $createPath = '/api/local-agent/artifacts';
        $createHeaders = $this->headersFor($agent, 'POST', $createPath, json_encode($createBody));
        $artifactId = $this->postJson($createPath, $createBody, $createHeaders)->assertCreated()->json('artifact_id');

        $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
        $contentHeaders = $this->headersFor($agent, 'POST', $contentPath, $content);
        $response = $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($contentHeaders),
            ['CONTENT_TYPE' => 'application/octet-stream'],
        ), $content);

        $response->assertOk();
        $this->assertDatabaseHas('artifacts', ['public_id' => $artifactId, 'status' => Artifact::STATUS_AVAILABLE]);
        Storage::disk('artifacts')->assertExists("{$project->project_key}/{$commitHash}.zip");
        $this->assertSame($content, Storage::disk('artifacts')->get("{$project->project_key}/{$commitHash}.zip"));
    }

    public function test_upload_content_rejects_a_checksum_mismatch_and_marks_the_artifact_failed(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $commitHash = str_repeat('c', 40);
        $claimedChecksum = hash('sha256', 'what-the-agent-claimed');
        $actualContent = 'something-completely-different';

        $createBody = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $claimedChecksum, 'size_bytes' => strlen($actualContent)];
        $createPath = '/api/local-agent/artifacts';
        $createHeaders = $this->headersFor($agent, 'POST', $createPath, json_encode($createBody));
        $artifactId = $this->postJson($createPath, $createBody, $createHeaders)->assertCreated()->json('artifact_id');

        $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
        $contentHeaders = $this->headersFor($agent, 'POST', $contentPath, $actualContent);
        $response = $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($contentHeaders),
            ['CONTENT_TYPE' => 'application/octet-stream'],
        ), $actualContent);

        $response->assertStatus(422);
        $this->assertDatabaseHas('artifacts', ['public_id' => $artifactId, 'status' => Artifact::STATUS_FAILED]);
        Storage::disk('artifacts')->assertMissing("{$project->project_key}/{$commitHash}.zip");
    }

    public function test_upload_content_rejects_a_different_agent_than_the_one_that_started_it(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        // Captured immediately — creating $otherAgent below overwrites
        // the factory's shared static plain-credential fields.
        $agentToken = LocalAgentFactory::$lastPlainToken;
        $agentSecret = LocalAgentFactory::$lastPlainSecret;
        $otherAgent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $otherAgent->id, 'project_id' => $project->id]);
        $otherToken = LocalAgentFactory::$lastPlainToken;
        $otherSecret = LocalAgentFactory::$lastPlainSecret;

        $content = 'fixture';
        $checksum = hash('sha256', $content);
        $commitHash = str_repeat('d', 40);

        $createBody = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $checksum, 'size_bytes' => strlen($content)];
        $createPath = '/api/local-agent/artifacts';
        $createHeaders = $this->localAgentHeaders('POST', $createPath, json_encode($createBody), $agent->public_id, $agentToken, $agentSecret);
        $artifactId = $this->postJson($createPath, $createBody, $createHeaders)->assertCreated()->json('artifact_id');

        $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
        $contentHeaders = $this->localAgentHeaders('POST', $contentPath, $content, $otherAgent->public_id, $otherToken, $otherSecret);
        $response = $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($contentHeaders),
            ['CONTENT_TYPE' => 'application/octet-stream'],
        ), $content);

        $response->assertStatus(403);
    }

    public function test_retention_keeps_only_the_newest_n_available_artifacts_per_project(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        config(['deployment.artifact_retention_count_per_project' => 2]);

        foreach (range(1, 3) as $i) {
            $content = "artifact-{$i}";
            $checksum = hash('sha256', $content);
            $commitHash = str_repeat((string) $i, 40);

            $createBody = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $checksum, 'size_bytes' => strlen($content)];
            $createPath = '/api/local-agent/artifacts';
            $createHeaders = $this->headersFor($agent, 'POST', $createPath, json_encode($createBody));
            $artifactId = $this->postJson($createPath, $createBody, $createHeaders)->assertCreated()->json('artifact_id');

            $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
            $contentHeaders = $this->headersFor($agent, 'POST', $contentPath, $content);
            $this->call('POST', $contentPath, [], [], [], array_merge(
                $this->toRawServerVars($contentHeaders),
                ['CONTENT_TYPE' => 'application/octet-stream'],
            ), $content)->assertOk();

            // Distinct created_at ordering — SQLite's datetime() resolution
            // is 1 second, and these three requests can land in the same
            // second.
            sleep(1);
        }

        $this->assertSame(2, Artifact::where('project_id', $project->id)->where('status', Artifact::STATUS_AVAILABLE)->count());
        $this->assertDatabaseMissing('artifacts', ['project_id' => $project->id, 'commit_hash' => str_repeat('1', 40)]);
        $this->assertDatabaseHas('artifacts', ['project_id' => $project->id, 'commit_hash' => str_repeat('3', 40), 'status' => Artifact::STATUS_AVAILABLE]);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function toRawServerVars(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (strtoupper($name) === 'AUTHORIZATION') {
                $server['HTTP_AUTHORIZATION'] = $value;

                continue;
            }
            $server[$key] = $value;
        }

        return $server;
    }
}
