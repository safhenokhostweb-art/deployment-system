<?php

namespace Tests\Feature\Releases;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseEvidenceService;
use App\Services\Releases\ReleaseOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Root-cause regression: `projects.auto_deploy` had no effect after the
 * release-readiness contract (faa75e7) because nothing created a Deployment
 * when a Release became READY. These tests pin the opt-in automatic path and
 * every guard on it.
 */
class ReleaseAutoDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('artifacts');
    }

    public function test_exact_commit_release_is_queued_automatically_when_auto_deploy_is_on(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: true);

        $artifact = $this->buildAndReport($project, $agent, $release, $job);

        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
        $deployment = Deployment::sole();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame('auto_deploy', $deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);
        $this->assertSame(Deployment::TRIGGER_PUBLISH, $deployment->trigger);
        $this->assertSame('system:release-auto-deploy', $deployment->triggered_by);
        $this->assertSame($release->id, $deployment->release_id);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame($release->commit_hash, $deployment->commit_hash);
        $this->assertTrue(AuditLogEntry::where('action', 'deployment.created_from_ready_release_auto_deploy')->where('subject_id', $deployment->id)->exists());
        $this->assertTrue(AuditLogEntry::where('action', 'release.auto_deployed')->where('subject_id', $release->id)->exists());

        // A repeated READY sync (re-upload, recovery sweep) is a no-op.
        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_auto_deploy_off_keeps_the_explicit_confirmation_contract(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: false);

        $this->buildAndReport($project, $agent, $release, $job);

        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertFalse(AuditLogEntry::where('action', 'like', 'release.auto_deploy%')->exists());
    }

    public function test_evidence_missing_never_auto_deploys(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: true);
        app(PublishJobService::class)->claimNext($agent);
        $bytes = $this->artifactBytes($project, $release);
        $artifact = app(ArtifactService::class)->createPending(
            $project, $release->commit_hash, $release->branch, hash('sha256', $bytes), strlen($bytes),
            $agent->public_id, ['.deployment/release-manifest.json'], $job->id,
        );
        $artifact->update(['release_id' => $release->id]);
        app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 10);

        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());

        $this->assertNotSame(Release::STATUS_READY, $release->fresh()->status);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_superseded_release_is_not_auto_deployed(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: true);
        // A newer push already exists on the deployable branch.
        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
            'branch' => 'main',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        $this->buildAndReport($project, $agent, $release, $job);

        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
        $this->assertDatabaseCount('deployments', 0);
        $skip = AuditLogEntry::where('action', 'release.auto_deploy_skipped')->where('subject_id', $release->id)->sole();
        $this->assertSame('release_superseded', $skip->metadata['reason']);
    }

    public function test_cancelled_auto_deployment_is_never_recreated(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: true);
        $this->buildAndReport($project, $agent, $release, $job);
        Deployment::sole()->update(['status' => Deployment::STATUS_CANCELLED]);

        // Force a fresh not-READY -> READY transition for the same Release.
        $release->fresh()->forceFill(['status' => Release::STATUS_AWAITING_ARTIFACT])->save();
        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());

        $this->assertSame(Release::STATUS_READY, $release->fresh()->status);
        $this->assertDatabaseCount('deployments', 1);
        $this->assertSame('deployment_already_exists', AuditLogEntry::where('action', 'release.auto_deploy_skipped')->latest('id')->first()->metadata['reason']);
    }

    public function test_release_that_was_already_ready_is_not_deployed_retroactively(): void
    {
        [$project, $agent, $release, $job] = $this->exactCommitContext(autoDeploy: false);
        $this->buildAndReport($project, $agent, $release, $job);
        $this->assertDatabaseCount('deployments', 0);

        // Owner ticks the box later; a later sync of the already-READY
        // backlog Release must not deploy it behind the owner's back.
        $project->update(['auto_deploy' => true]);
        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_signed_project_contract_release_is_auto_deployed_once_its_artifact_is_available(): void
    {
        // The path release-prepare uses: signed metadata -> legacy-fallback
        // Release; here the exact Artifact is already available, so the
        // metadata POST itself makes the Release READY.
        [$project, $sha] = $this->contractProject(autoDeploy: true);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();

        $release = Release::sole();
        $this->assertSame(Release::STATUS_READY, $release->status);
        $deployment = Deployment::sole();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame('auto_deploy', $deployment->approved_by);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame($release->id, $deployment->release_id);
    }

    public function test_failed_auto_deploy_is_audited_and_never_breaks_the_ready_transition(): void
    {
        [$project, $sha] = $this->contractProject(autoDeploy: true);
        // Available in the DB but the bytes are gone from storage: the
        // physical integrity gate must refuse it.
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
            'semantic_status' => 'legacy',
        ]);

        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();

        $release = Release::sole();
        $this->assertSame(Release::STATUS_READY, $release->status);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertTrue(AuditLogEntry::where('action', 'release.auto_deploy_failed')->where('subject_id', $release->id)->exists());
    }

    public function test_inactive_project_is_not_auto_deployed(): void
    {
        [$project, $sha] = $this->contractProject(autoDeploy: true);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();
        $this->assertDatabaseCount('deployments', 1);

        // Same transition on a deactivated project (release API itself
        // requires an active project, so drive the orchestrator directly).
        Deployment::query()->delete();
        $project->update(['is_active' => false]);
        $release = Release::sole();
        $release->forceFill(['status' => Release::STATUS_AWAITING_ARTIFACT])->save();
        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());

        $this->assertDatabaseCount('deployments', 0);
        $this->assertSame('project_inactive', AuditLogEntry::where('action', 'release.auto_deploy_skipped')->latest('id')->first()->metadata['reason']);
    }

    // --- fixtures -----------------------------------------------------------

    /** @return array{Project, LocalAgent, Release, PublishJob} */
    private function exactCommitContext(bool $autoDeploy): array
    {
        $sha = str_repeat('a', 40);
        $project = Project::factory()->create([
            'branch' => 'main',
            'requires_complete_artifact' => true,
            'github_webhook_secret' => 'topsecret',
            'auto_deploy' => $autoDeploy,
        ]);
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
            'X-GitHub-Event' => 'push', 'X-GitHub-Delivery' => 'auto-'.bin2hex(random_bytes(4)),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'topsecret'),
        ])->assertAccepted();

        return [$project->fresh(), $agent->fresh(), Release::sole(), PublishJob::sole()];
    }

    private function buildAndReport(Project $project, LocalAgent $agent, Release $release, PublishJob $job): Artifact
    {
        app(PublishJobService::class)->claimNext($agent);
        $bytes = $this->artifactBytes($project, $release);
        $artifact = app(ArtifactService::class)->createPending(
            $project, $release->commit_hash, $release->branch, hash('sha256', $bytes), strlen($bytes),
            $agent->public_id, ['.deployment/release-manifest.json'], $job->id,
        );
        $artifact->update(['release_id' => $release->id]);
        $artifact = app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 10);
        app(ReleaseOrchestrator::class)->syncArtifactState($release->fresh());
        $this->assertDatabaseCount('deployments', 0);

        app(ReleaseEvidenceService::class)->validateAndRecord($job->fresh(), $agent, $this->evidence($job->fresh(), $agent));
        app(PublishJobService::class)->reportResult($job->fresh(), $release->commit_hash, 'verified exact build');

        return $artifact->fresh();
    }

    /** @return array{Project, string} */
    private function contractProject(bool $autoDeploy): array
    {
        $sha = str_repeat('c', 40);
        $project = Project::factory()->create([
            'project_key' => 'release-fixture',
            'repository' => 'acme/release-fixture',
            'branch' => 'main',
            'requires_complete_artifact' => true,
            'github_webhook_secret' => 'fixture-secret',
            'auto_deploy' => $autoDeploy,
        ]);

        return [$project->fresh(), $sha];
    }

    private function metadata(Project $project, string $sha): array
    {
        return [
            'metadata_version' => 1,
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'base_sha' => str_repeat('b', 40),
            'branch' => 'main',
            'summary' => 'Auto-deploy fixture release',
            'changed_files' => ['src/Feature.php'],
            'migration_files' => [],
            'test_results' => [['name' => 'suite', 'exit_code' => 0]],
            'build_result' => ['required' => true, 'status' => 'pending'],
            'health_contract' => ['required' => true, 'endpoint' => '/health'],
            'risk_flags' => [],
            'created_timestamp' => now()->toIso8601String(),
            'release_ready' => true,
        ];
    }

    private function postMetadata(Project $project, array $metadata)
    {
        $raw = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'fixture-secret');

        return $this->call('POST', route('projects.releases.store', $project->project_key), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEPLOYMENT_SIGNATURE_256' => $signature,
        ], $raw);
    }

    private function artifactBytes(Project $project, Release $release): string
    {
        $path = tempnam(sys_get_temp_dir(), 'auto-deploy-artifact-').'.zip';
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
