<?php

namespace Tests\Feature\Releases;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseEvidenceService;
use App\Services\Releases\ReleaseOrchestrator;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

class ExactCommitEvidenceClosureTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_exact_evidence_and_semantic_artifact_make_release_ready_without_auto_deployment_and_confirm_uses_exact_artifact(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->claimNext($agent);

        $bytes = $this->artifactBytes($project, $release);
        $artifact = app(ArtifactService::class)->createPending(
            $project, $release->commit_hash, $release->branch, hash('sha256', $bytes), strlen($bytes),
            $agent->public_id, ['.deployment/release-manifest.json'], $job->id,
        );
        $artifact->update(['release_id' => $release->id]);
        $artifact = app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 10);

        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());
        $this->assertNotSame(Release::STATUS_READY, $release->fresh()->status, 'Artifact/build success without trusted evidence must not make a release ready.');
        $this->assertDatabaseCount('deployments', 0);

        app(ReleaseEvidenceService::class)->validateAndRecord($job->fresh(), $agent, $this->evidence($job->fresh(), $agent));
        app(PublishJobService::class)->reportResult($job->fresh(), $release->commit_hash, 'verified exact build');

        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
        $this->assertTrue((bool) data_get($release->fresh()->metadata, 'release_ready'));
        $this->assertDatabaseCount('deployments', 0);

        $deployment = app(DeploymentDispatcher::class)->createFromExistingArtifact($project, $artifact, 'operator@example.test');
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame($release->commit_hash, $deployment->commit_hash);
    }

    public function test_evidence_identity_mismatches_and_missing_or_failed_checks_fail_closed(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->claimNext($agent);
        $job->refresh();
        $valid = $this->evidence($job, $agent);

        $mutations = [
            'sha' => fn (array $e): array => array_replace($e, ['commit_sha' => str_repeat('f', 40)]),
            'branch' => fn (array $e): array => array_replace($e, ['branch' => 'wrong']),
            'job' => fn (array $e): array => array_replace($e, ['publish_job_id' => '00000000-0000-4000-8000-000000000000']),
            'agent' => fn (array $e): array => array_replace($e, ['agent_id' => '00000000-0000-4000-8000-000000000000']),
            'missing_check' => function (array $e): array {
                array_pop($e['checks']);

                return $e;
            },
            'failed_check' => function (array $e): array {
                $e['checks'][1]['status'] = 'failed';

                return $e;
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $bad = $mutate($valid);
            $bad['evidence_digest'] = $this->digestWithout($bad, 'evidence_digest');
            try {
                app(ReleaseEvidenceService::class)->validateAndRecord($job->fresh(), $agent, $bad);
                $this->fail("{$name} evidence unexpectedly passed validation.");
            } catch (HttpExceptionInterface $e) {
                $this->assertContains($e->getStatusCode(), [403, 422], $name);
            }
            $this->assertNull($job->fresh()->evidence_verified_at);
            $this->assertNotSame(Release::STATUS_READY, $release->fresh()->status);
        }
    }

    public function test_artifact_job_binding_rejects_another_mapped_agent(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->claimNext($agent);
        $other = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create(['project_id' => $project->id, 'local_agent_id' => $other->id, 'source_workspace_path' => 'C:\\other', 'git_branch' => 'main']);

        $body = [
            'project_key' => $project->project_key, 'commit_hash' => $release->commit_hash, 'branch' => $release->branch,
            'checksum_sha256' => str_repeat('c', 64), 'size_bytes' => 100, 'publish_job_id' => $job->public_id,
        ];
        $path = '/api/local-agent/artifacts';
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $other->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
        $this->postJson($path, $body, $headers)->assertForbidden();
        $this->assertDatabaseCount('artifacts', 0);
    }

    public function test_artifact_metadata_requires_the_assigned_publish_job_public_id(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->claimNext($agent);

        $path = '/api/local-agent/artifacts';
        $body = [
            'project_key' => $project->project_key,
            'commit_hash' => $release->commit_hash,
            'branch' => $release->branch,
            'checksum_sha256' => str_repeat('c', 64),
            'size_bytes' => 100,
        ];

        $headers = fn (array $payload): array => $this->localAgentHeaders(
            'POST',
            $path,
            json_encode($payload),
            $agent->public_id,
            LocalAgentFactory::$lastPlainToken,
            LocalAgentFactory::$lastPlainSecret,
        );

        $this->postJson($path, $body, $headers($body))
            ->assertStatus(422)
            ->assertSee('publish_job_id is required');

        $databaseIdBody = [...$body, 'publish_job_id' => (string) $job->id];
        $this->postJson($path, $databaseIdBody, $headers($databaseIdBody))
            ->assertStatus(422)
            ->assertJsonValidationErrors('publish_job_id');

        $mismatchBody = [...$body, 'publish_job_id' => '00000000-0000-4000-8000-000000000000'];
        $this->postJson($path, $mismatchBody, $headers($mismatchBody))->assertNotFound();

        $validBody = [...$body, 'publish_job_id' => $job->public_id];
        $this->postJson($path, $validBody, $headers($validBody))->assertCreated();

        $this->assertDatabaseHas('artifacts', [
            'project_id' => $project->id,
            'publish_job_id' => $job->id,
            'release_id' => $release->id,
        ]);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_artifact_metadata_for_an_unlinked_modern_build_fails_closed(): void
    {
        [$project, $agent] = $this->context();
        PublishJob::query()->delete();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'release_id' => null,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_RUNNING,
            'reviewed_head_sha' => str_repeat('b', 40),
            'reviewed_branch' => 'main',
        ]);
        $body = [
            'project_key' => $project->project_key,
            'commit_hash' => str_repeat('b', 40),
            'branch' => 'main',
            'checksum_sha256' => str_repeat('c', 64),
            'size_bytes' => 100,
            'publish_job_id' => $job->public_id,
        ];
        $path = '/api/local-agent/artifacts';
        $headers = $this->localAgentHeaders(
            'POST',
            $path,
            json_encode($body),
            $agent->public_id,
            LocalAgentFactory::$lastPlainToken,
            LocalAgentFactory::$lastPlainSecret,
        );

        $this->postJson($path, $body, $headers)
            ->assertStatus(422)
            ->assertSee('Artifact build is not linked to a Release.');

        $this->assertDatabaseCount('artifacts', 0);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_signed_job_report_with_invalid_evidence_fails_the_job_and_release_closed(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->claimNext($agent);
        $evidence = $this->evidence($job, $agent);
        $evidence['commit_sha'] = str_repeat('f', 40);
        $evidence['evidence_digest'] = $this->digestWithout($evidence, 'evidence_digest');
        $body = ['status' => 'succeeded', 'commit_hash' => $release->commit_hash, 'evidence' => $evidence];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(422);
        $this->assertSame(PublishJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertSame('release_evidence_invalid', $job->fresh()->failure_code);
        $this->assertSame(Release::STATUS_FAILED, $release->fresh()->status);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_failed_build_fails_release_and_valid_retry_returns_it_to_building_without_duplicate_active_job(): void
    {
        [$project, $agent, $release, $job] = $this->context();
        app(PublishJobService::class)->reportFailure($job, 'composer failed', null, PublishJob::FAILURE_CODE_LOCAL_COMPOSER_FAILED);
        $this->assertSame(Release::STATUS_FAILED, $release->fresh()->status);
        $this->assertSame('artifact_build_local_composer_failed', $release->fresh()->failure_code);

        $retry = app(PublishJobService::class)->queueArtifactBuild($agent, $project, $release->commit_hash, $release->branch, 'operator@example.test');
        $duplicate = app(PublishJobService::class)->queueArtifactBuild($agent, $project, $release->commit_hash, $release->branch, 'operator@example.test');

        $this->assertNotSame($job->id, $retry->id);
        $this->assertSame($retry->id, $duplicate->id);
        $this->assertSame($release->id, $retry->release_id);
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->fresh()->status);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame(1, PublishJob::where('active_build_key', "artifact:{$project->id}:{$release->commit_hash}")->count());
    }

    private function context(): array
    {
        $sha = str_repeat('a', 40);
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true, 'github_webhook_secret' => 'topsecret']);
        $agent = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id, 'local_agent_id' => $agent->id,
            'source_workspace_path' => 'C:\\workspaces\\project', 'git_branch' => 'main',
            'local_head_sha' => $sha, 'remote_tracking_sha' => $sha, 'reported_branch' => 'main',
            'working_tree_dirty' => false, 'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);
        $payload = ['ref' => 'refs/heads/main', 'after' => $sha, 'pusher' => ['name' => 'octocat']];
        $body = json_encode($payload);
        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, [
            'X-GitHub-Event' => 'push', 'X-GitHub-Delivery' => 'evidence-'.bin2hex(random_bytes(4)),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'topsecret'),
        ])->assertAccepted();
        $release = Release::sole();
        $job = PublishJob::sole();

        return [$project->fresh(), $agent->fresh(), $release->fresh(), $job->fresh()];
    }

    private function artifactBytes(Project $project, Release $release): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-artifact-').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('app/Test.php', '<?php return true;');
        $zip->addFromString('.deployment/release-manifest.json', json_encode([
            'format' => 'deployment-system-release-manifest', 'project_key' => $project->project_key,
            'commit_sha' => $release->commit_hash, 'branch' => $release->branch, 'status' => 'pass',
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function evidence(PublishJob $job, LocalAgent $agent): array
    {
        $contract = $job->evidence_contract;
        $now = now()->toIso8601String();
        $evidence = [
            'contract_version' => $contract['contract_version'], 'project_key' => $contract['project_key'],
            'release_id' => $contract['release_id'], 'publish_job_id' => $contract['publish_job_id'],
            'commit_sha' => $contract['commit_sha'], 'branch' => $contract['branch'], 'agent_id' => $contract['agent_id'],
            'agent_version' => $agent->agent_version, 'contract_digest' => $contract['contract_digest'],
            'started_at' => $now, 'completed_at' => $now, 'duration_ms' => 1,
            'checks' => array_map(fn (array $check): array => [
                'id' => $check['id'], 'status' => 'passed', 'started_at' => $now,
                'completed_at' => $now, 'duration_ms' => 1, 'details' => ['verified' => true],
            ], $contract['required_checks']),
        ];
        $evidence['evidence_digest'] = $this->digestWithout($evidence, 'evidence_digest');

        return $evidence;
    }

    private function digestWithout(array $value, string $key): string
    {
        unset($value[$key]);

        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
