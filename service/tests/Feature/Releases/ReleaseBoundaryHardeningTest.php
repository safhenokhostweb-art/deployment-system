<?php

namespace Tests\Feature\Releases;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ReleaseBoundaryHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_ordinary_release_cannot_be_made_ready_by_signed_fallback(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'semantic_status' => 'legacy',
        ]);

        $metadata = $this->readyMetadata($project, $sha);
        $raw = json_encode($metadata, JSON_THROW_ON_ERROR);
        $this->call('POST', "/api/projects/{$project->project_key}/releases", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEPLOYMENT_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $project->github_webhook_secret),
        ], $raw)->assertStatus(409);

        $release = Release::sole();
        $this->assertFalse($release->isLegacyFallbackEligible());
        $this->assertNotSame(Release::STATUS_READY, $release->status);
    }

    public function test_explicit_signed_legacy_release_can_use_approved_fallback(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'semantic_status' => 'legacy',
        ]);
        $metadata = $this->readyMetadata($project, $sha);
        $raw = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->call('POST', "/api/projects/{$project->project_key}/releases", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEPLOYMENT_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $project->github_webhook_secret),
        ], $raw)->assertAccepted();

        $release = Release::sole();
        $this->assertTrue($release->isLegacyFallbackEligible());
        $this->assertSame('signed_project_release_contract', $release->legacy_fallback_reason);
        $this->assertSame(Release::STATUS_READY, $release->status);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'release.legacy_fallback_eligibility_created',
            'subject_id' => $release->id,
        ]);
    }

    public function test_new_contract_release_blocks_automatic_webhook_and_publish_deployments(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
        ]);

        foreach (['webhook', 'publish'] as $path) {
            try {
                if ($path === 'webhook') {
                    app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
                } else {
                    app(DeploymentDispatcher::class)->createFromPublish($project, $sha, 'main', 'agent');
                }
                $this->fail("{$path} unexpectedly created a Deployment without Confirm.");
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_queued_legacy_deployment_request_cannot_bypass_new_contract(): void
    {
        [$project, $sha] = $this->projectAndSha();
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
        ]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_RUNNING,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
            'deployment_request' => ['trigger' => 'webhook', 'triggered_by' => 'github'],
        ]);

        $this->assertNull(app(DeploymentDispatcher::class)->resumeRequiredArtifactDeployment($job));
        $this->assertDatabaseCount('deployments', 0);
        $this->assertSame('explicit_confirm_required', $job->fresh()->deployment_request['blocked_reason']);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.legacy_deployment_request_blocked',
            'subject_id' => $job->id,
        ]);
    }

    public function test_no_source_change_bridge_cannot_create_deployment_for_new_contract_release(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
        ]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_PUBLISH,
            'status' => PublishJob::STATUS_RUNNING,
            'no_source_change' => true,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
        ]);

        app(PublishJobService::class)->reportResult($job, $sha, 'already remote');

        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.deployment_bridge_failed',
            'subject_id' => $job->id,
        ]);
        $audit = \App\Models\AuditLogEntry::query()
            ->where('action', 'publish_job.deployment_bridge_failed')
            ->where('subject_id', $job->id)
            ->firstOrFail();
        $this->assertSame('explicit_confirm_required', $audit->metadata['reason']);
    }

    public function test_uncontracted_legacy_dispatch_creates_an_explicit_auditable_legacy_boundary(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
        ]);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');

        $this->assertSame($sha, $deployment->commit_hash);
        $release = Release::sole();
        $this->assertTrue($release->isLegacyFallbackEligible());
        $this->assertSame('legacy_webhook_dispatch', $release->legacy_fallback_reason);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'release.legacy_automatic_deployment_eligibility_created',
            'subject_id' => $release->id,
        ]);
    }

    private function projectAndSha(): array
    {
        return [Project::factory()->create([
            'branch' => 'main',
            'github_webhook_secret' => 'boundary-secret',
        ]), str_repeat('a', 40)];
    }

    private function readyMetadata(Project $project, string $sha): array
    {
        return [
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'base_sha' => str_repeat('b', 40),
            'branch' => 'main',
            'changed_files' => ['app/Test.php'],
            'migration_files' => [],
            'test_results' => [['name' => 'tests', 'status' => 'passed']],
            'build_result' => ['required' => true, 'status' => 'succeeded'],
            'risk_flags' => [],
            'created_timestamp' => now()->toIso8601String(),
            'release_ready' => true,
        ];
    }
}
