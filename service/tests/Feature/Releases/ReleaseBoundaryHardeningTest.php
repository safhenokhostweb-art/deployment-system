<?php

namespace Tests\Feature\Releases;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseReadiness;
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

    /**
     * Release::isLegacyFallbackEligible() is the single predicate both
     * DeploymentDispatcher's Confirm gate and ReleaseReadiness's evidence-
     * strictness check depend on -- these isolate each of its five
     * independently-required conditions (policy + allowed source +
     * timestamp + non-empty reason + non-empty actor) directly against the
     * model, in-memory, so a future edit that silently drops any one of
     * them fails a test here rather than only being caught (if at all) by
     * a much larger integration test exercising a real write path.
     */
    public function test_legacy_eligibility_requires_the_legacy_fallback_policy_itself(): void
    {
        $release = Release::factory()->make([
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'some reason',
            'legacy_fallback_eligible_by' => 'admin@example.test',
        ]);

        $this->assertFalse($release->isLegacyFallbackEligible());
    }

    public function test_legacy_eligibility_requires_an_allowed_source(): void
    {
        $release = Release::factory()->make([
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'some reason',
            'legacy_fallback_eligible_by' => 'admin@example.test',
        ]);

        $this->assertFalse($release->isLegacyFallbackEligible());
    }

    public function test_legacy_eligibility_requires_an_eligibility_timestamp(): void
    {
        $release = Release::factory()->make([
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => null,
            'legacy_fallback_reason' => 'some reason',
            'legacy_fallback_eligible_by' => 'admin@example.test',
        ]);

        $this->assertFalse($release->isLegacyFallbackEligible());
    }

    public function test_legacy_eligibility_requires_a_non_empty_reason(): void
    {
        $release = Release::factory()->make([
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => '',
            'legacy_fallback_eligible_by' => 'admin@example.test',
        ]);

        $this->assertFalse($release->isLegacyFallbackEligible());
    }

    public function test_legacy_eligibility_requires_a_non_empty_actor(): void
    {
        $release = Release::factory()->make([
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'some reason',
            'legacy_fallback_eligible_by' => '',
        ]);

        $this->assertFalse($release->isLegacyFallbackEligible());
    }

    public function test_legacy_eligibility_is_true_only_when_every_required_field_is_present(): void
    {
        $release = Release::factory()->make([
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'some reason',
            'legacy_fallback_eligible_by' => 'admin@example.test',
        ]);

        $this->assertTrue($release->isLegacyFallbackEligible());
    }

    /**
     * An incomplete legacy grant (e.g. missing eligible_by/actor) must be
     * blocked by DeploymentDispatcher exactly like an ordinary EXACT_COMMIT
     * release -- not merely at the isolated model-predicate level above,
     * but through the real enforcement point.
     */
    public function test_incomplete_legacy_fallback_release_is_blocked_by_the_dispatcher(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'incomplete grant',
            'legacy_fallback_eligible_by' => null, // missing actor -- incomplete
        ]);

        try {
            app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
            $this->fail('An incomplete legacy fallback contract unexpectedly created a Deployment.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * auto_deploy is an auto-APPROVE flag for a deployment that is already
     * otherwise authorized -- it must never itself substitute for the
     * Release contract check. Proven against both an uncontracted commit
     * and an existing EXACT_COMMIT release, with auto_deploy explicitly
     * turned on.
     */
    public function test_auto_deploy_never_bypasses_the_release_contract_check(): void
    {
        [$project, $sha] = $this->projectAndSha();
        $project->forceFill(['auto_deploy' => true])->save();

        try {
            app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
            $this->fail('auto_deploy=true unexpectedly bypassed the Release contract for an uncontracted commit.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('releases', 0);

        Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
        ]);

        try {
            app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
            $this->fail('auto_deploy=true unexpectedly bypassed the Release contract for an EXACT_COMMIT release.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * Root-cause fix: an earlier draft made an uncontracted request fail
     * closed only AFTER manufacturing its own EXACT_COMMIT Release row to
     * test as ineligible -- resolveAutomaticDeploymentContract() must
     * never create anything at all. Absence of a Release is itself the
     * fail-closed answer, with zero side effects, for both webhook and
     * publish triggers.
     */
    public function test_uncontracted_webhook_and_publish_requests_fail_closed_without_creating_a_release(): void
    {
        [$project, $sha] = $this->projectAndSha();

        foreach (['webhook', 'publish'] as $path) {
            try {
                if ($path === 'webhook') {
                    app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
                } else {
                    app(DeploymentDispatcher::class)->createFromPublish($project, $sha, 'main', 'agent');
                }
                $this->fail("{$path} unexpectedly created a Deployment for an uncontracted commit.");
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(409, $e->getStatusCode());
                $this->assertSame('This Release requires explicit deployment confirmation.', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('releases', 0);
        $this->assertDatabaseCount('deployments', 0);
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

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $sha, 'main', 'github');
        $this->assertInstanceOf(Deployment::class, $deployment);
        $this->assertSame($sha, $deployment->commit_hash);
    }

    public function test_confirmed_exact_release_can_create_deployment_from_its_verified_artifact(): void
    {
        [$project, $sha] = $this->projectAndSha();
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'source' => 'webhook',
            'status' => Release::STATUS_READY,
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
            'metadata' => ['release_ready' => true, 'risk_flags' => []],
        ]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
            'evidence_verified_at' => now(),
        ]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'publish_job_id' => $job->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'semantic_status' => 'verified',
        ]);

        $readiness = app(ReleaseReadiness::class)->for($sha, $release, $artifact);
        $this->assertTrue($readiness['deployable']);

        $deployment = app(DeploymentDispatcher::class)->createFromExistingArtifact($project, $artifact, 'operator@example.test');
        $this->assertInstanceOf(Deployment::class, $deployment);
        $this->assertSame($artifact->id, $deployment->artifact_id);
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
                $this->assertSame('This Release requires explicit deployment confirmation.', $e->getMessage());
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

    public function test_queued_deployment_request_without_a_release_fails_closed_without_creating_one(): void
    {
        [$project, $sha] = $this->projectAndSha();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
        ]);
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_RUNNING,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
            'deployment_request' => ['trigger' => 'webhook', 'triggered_by' => 'github'],
        ]);

        $this->assertNull(app(DeploymentDispatcher::class)->resumeRequiredArtifactDeployment($job));
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('releases', 0);
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
        $audit = \App\Models\AuditLogEntry::query()
            ->where('action', 'publish_job.deployment_bridge_failed')
            ->where('subject_id', $job->id)
            ->firstOrFail();
        $this->assertSame('explicit_confirm_required', $audit->metadata['reason']);
    }

    public function test_no_source_change_bridge_without_a_release_fails_closed_without_creating_one(): void
    {
        [$project, $sha] = $this->projectAndSha();
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
        $this->assertDatabaseCount('releases', 0);
        $audit = \App\Models\AuditLogEntry::query()
            ->where('action', 'publish_job.deployment_bridge_failed')
            ->where('subject_id', $job->id)
            ->firstOrFail();
        $this->assertSame('explicit_confirm_required', $audit->metadata['reason']);
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
