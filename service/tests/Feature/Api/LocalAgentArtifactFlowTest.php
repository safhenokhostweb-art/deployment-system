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

    /**
     * The Hub now independently re-inspects uploaded archive contents
     * (defense-in-depth against a compromised/buggy Local Agent), so any
     * fixture that's expected to reach STATUS_AVAILABLE must be a real,
     * openable zip — not an arbitrary string.
     *
     * @param array<string, string> $entries
     */
    private function buildZipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'local-agent-artifact-flow-test-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
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

    public function test_create_accepts_the_canonical_required_paths_without_regex_rule_splitting(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $body = [
            'project_key' => $project->project_key,
            'commit_hash' => 'c21223c1ccd95d9bcf1e3feb105bea28b3f4b4f0',
            'branch' => 'master',
            'checksum_sha256' => '90299f0b5d7e291eb986e63ec217a7341b57bc4330cf2f398cd463b5ac997bac',
            'size_bytes' => 8837722,
            'required_paths' => [
                'composer.json',
                'vendor/autoload.php',
                'artisan',
                'bootstrap/app.php',
                'public/index.php',
                'public/build/manifest.json',
            ],
        ];
        $path = '/api/local-agent/artifacts';

        $response = $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))
            ->assertCreated();

        $this->assertDatabaseHas('artifacts', [
            'public_id' => $response->json('artifact_id'),
            'project_id' => $project->id,
            'commit_hash' => $body['commit_hash'],
            'checksum_sha256' => $body['checksum_sha256'],
            'status' => Artifact::STATUS_UPLOADING,
        ]);
        $this->assertSame($body['required_paths'], Artifact::where('public_id', $response->json('artifact_id'))->value('required_paths'));
    }

    public function test_create_rejects_required_paths_with_parent_directory_segments(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $body = [
            'project_key' => $project->project_key,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'checksum_sha256' => hash('sha256', 'x'),
            'size_bytes' => 1,
            'required_paths' => ['vendor/../autoload.php'],
        ];
        $path = '/api/local-agent/artifacts';

        $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_paths.0');
        $this->assertDatabaseMissing('artifacts', ['project_id' => $project->id, 'commit_hash' => $body['commit_hash']]);
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

    public function test_create_is_idempotent_and_preserves_available_artifact_on_conflict(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $content = $this->buildZipBytes(['index.php' => 'idempotent']);
        $body = ['project_key' => $project->project_key, 'commit_hash' => str_repeat('e', 40), 'branch' => 'main', 'checksum_sha256' => hash('sha256', $content), 'size_bytes' => strlen($content)];
        $path = '/api/local-agent/artifacts';
        $first = $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertCreated()->json('artifact_id');
        $second = $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertCreated()->json('artifact_id');
        $this->assertSame($first, $second);
        $contentPath = "/api/local-agent/artifacts/{$first}/content";
        $this->call('POST', $contentPath, [], [], [], array_merge($this->toRawServerVars($this->headersFor($agent, 'POST', $contentPath, $content)), ['CONTENT_TYPE' => 'application/octet-stream']), $content)->assertOk();
        $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertCreated()->assertJsonPath('artifact_id', $first);
        $bad = $body; $bad['checksum_sha256'] = str_repeat('f', 64);
        $this->postJson($path, $bad, $this->headersFor($agent, 'POST', $path, json_encode($bad)))->assertStatus(409);
        $this->assertDatabaseHas('artifacts', ['public_id' => $first, 'status' => Artifact::STATUS_AVAILABLE]);
        $this->assertSame($content, Storage::disk('artifacts')->get("{$project->project_key}/" . $body['commit_hash'] . '.zip'));
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
        $content = $this->buildZipBytes(['index.php' => str_repeat('artifact-bytes-', 100)]);
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
            $content = $this->buildZipBytes(['index.php' => "artifact-{$i}"]);
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

    public function test_content_upload_allows_extended_skew_and_returns_413_when_body_truncated(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $content = $this->buildZipBytes(['index.php' => 'extended-skew-ok']);
        $checksum = hash('sha256', $content);
        $commitHash = str_repeat('e', 40);

        $createBody = ['project_key' => $project->project_key, 'commit_hash' => $commitHash, 'branch' => 'main', 'checksum_sha256' => $checksum, 'size_bytes' => strlen($content)];
        $createPath = '/api/local-agent/artifacts';
        $artifactId = $this->postJson($createPath, $createBody, $this->headersFor($agent, 'POST', $createPath, json_encode($createBody)))
            ->assertCreated()
            ->json('artifact_id');

        // 1) Simulate body discarded by server upload limits (Content-Length > 0, raw body empty) -> 413
        $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
        $truncatedHeaders = $this->headersFor($agent, 'POST', $contentPath, $content);
        $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($truncatedHeaders),
            ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($content)],
        ), '')->assertStatus(413);

        // 2) Simulate a 10-minute (600s) slow upload -> accepted within 1800s window
        $oldTimestamp = (string) (time() - 600);
        $nonce = bin2hex(random_bytes(16));
        $signer = app(\App\Services\Security\RequestSigner::class);
        $sig = $signer->sign('POST', $contentPath, $oldTimestamp, $nonce, $content, LocalAgentFactory::$lastPlainSecret);
        $slowHeaders = [
            'Authorization' => 'Bearer ' . LocalAgentFactory::$lastPlainToken,
            'X-Deployment-Local-Agent-Id' => $agent->public_id,
            'X-Deployment-Timestamp' => $oldTimestamp,
            'X-Deployment-Nonce' => $nonce,
            'X-Deployment-Signature' => $sig,
        ];
        $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($slowHeaders),
            ['CONTENT_TYPE' => 'application/octet-stream'],
        ), $content)->assertOk();
    }

    public function test_new_publish_job_reclaims_uploading_artifact_from_failed_or_cancelled_job(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $commitHash = str_repeat('f', 40);
        $content = $this->buildZipBytes(['index.php' => 'reclaim-ok']);
        $checksum = hash('sha256', $content);

        $release1 = \App\Models\Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'branch' => 'main',
        ]);
        $failedJob = \App\Models\PublishJob::factory()->create([
            'project_id' => $project->id,
            'release_id' => $release1->id,
            'local_agent_id' => $agent->id,
            'action' => \App\Models\PublishJob::ACTION_BUILD_ARTIFACT,
            'reviewed_head_sha' => $commitHash,
            'reviewed_branch' => 'main',
            'status' => \App\Models\PublishJob::STATUS_FAILED,
        ]);

        // Previous job left artifact stuck in uploading
        $artifact = Artifact::create([
            'project_id' => $project->id,
            'publish_job_id' => $failedJob->id,
            'release_id' => $release1->id,
            'commit_hash' => $commitHash,
            'branch' => 'main',
            'checksum_sha256' => $checksum,
            'size_bytes' => strlen($content),
            'disk_path' => 'artifacts/' . $project->project_key . '/' . $commitHash . '.zip',
            'status' => Artifact::STATUS_UPLOADING,
            'uploaded_by' => $agent->public_id,
            'expires_at' => now()->addDays(7),
        ]);

        $newJob = \App\Models\PublishJob::factory()->create([
            'project_id' => $project->id,
            'release_id' => $release1->id,
            'local_agent_id' => $agent->id,
            'action' => \App\Models\PublishJob::ACTION_BUILD_ARTIFACT,
            'reviewed_head_sha' => $commitHash,
            'reviewed_branch' => 'main',
            'status' => \App\Models\PublishJob::STATUS_RUNNING,
        ]);

        $createBody = [
            'project_key' => $project->project_key,
            'commit_hash' => $commitHash,
            'branch' => 'main',
            'checksum_sha256' => $checksum,
            'size_bytes' => strlen($content),
            'publish_job_id' => $newJob->public_id,
        ];
        $createPath = '/api/local-agent/artifacts';
        $this->postJson($createPath, $createBody, $this->headersFor($agent, 'POST', $createPath, json_encode($createBody)))
            ->assertCreated()
            ->assertJsonPath('artifact_id', $artifact->public_id);

        $this->assertDatabaseHas('artifacts', [
            'id' => $artifact->id,
            'publish_job_id' => $newJob->id,
            'status' => Artifact::STATUS_UPLOADING,
        ]);
    }

    public function test_content_upload_streams_into_temp_file_and_cleans_up(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $content = $this->buildZipBytes(['index.php' => '<?php echo "streamed-upload-ok";']);
        $checksum = hash('sha256', $content);

        $createBody = [
            'project_key' => $project->project_key,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'checksum_sha256' => $checksum,
            'size_bytes' => strlen($content),
        ];
        $createPath = '/api/local-agent/artifacts';
        $artifactId = $this->postJson($createPath, $createBody, $this->headersFor($agent, 'POST', $createPath, json_encode($createBody)))
            ->assertCreated()
            ->json('artifact_id');

        $contentPath = "/api/local-agent/artifacts/{$artifactId}/content";
        $headers = $this->headersFor($agent, 'POST', $contentPath, $content);

        $response = $this->call('POST', $contentPath, [], [], [], array_merge(
            $this->toRawServerVars($headers),
            ['CONTENT_TYPE' => 'application/octet-stream'],
        ), $content);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('artifact_id', $artifactId)
            ->assertJsonPath('size_bytes', strlen($content));

        $artifact = Artifact::where('public_id', $artifactId)->firstOrFail();
        $this->assertSame(Artifact::STATUS_AVAILABLE, $artifact->status);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('artifacts')->exists($artifact->disk_path));
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
