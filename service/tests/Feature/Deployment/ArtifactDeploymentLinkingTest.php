<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\Server;
use App\Models\Agent;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): a Deployment
 * whose project/commit has a matching Artifact gets it attached at
 * creation time (see DeploymentDispatcher::attachArtifactIfAvailable()),
 * so AgentJobService::claimNextJob() can tell the agent to download it
 * instead of git-fetching from GitHub. A Deployment with no matching
 * artifact is completely unaffected — the exact legacy behavior.
 */
class ArtifactDeploymentLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    /**
     * These tests exercise DeploymentDispatcher's artifact-attachment
     * mechanics (createFromWebhook's legacy/pending-approval lifecycle,
     * approve()'s late-attach behavior) -- none of them are about
     * authorization itself. Under the new Release contract that lifecycle
     * is reachable only through an explicit, auditable LEGACY_FALLBACK
     * grant (auto_deploy alone no longer suffices), so each test is given
     * one for the exact project/commit/branch it dispatches -- never
     * fabricated silently, always the full policy+source+reason+actor+
     * timestamp contract Release::isLegacyFallbackEligible() requires.
     */
    private function grantLegacyFallback(Project $project, string $commitHash, string $branch = 'main'): Release
    {
        return Release::query()->firstOrCreate(
            ['project_id' => $project->id, 'commit_hash' => $commitHash],
            [
                'branch' => $branch,
                'source' => 'admin_backfill',
                'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
                'legacy_fallback_eligible_at' => now(),
                'legacy_fallback_reason' => 'test_fixture_explicit_legacy_grant',
                'legacy_fallback_eligible_by' => 'test-harness',
            ],
        );
    }

    public function test_webhook_created_deployment_attaches_a_matching_artifact(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('a', 40);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $this->grantLegacyFallback($project, $commitHash);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame($artifact->id, $deployment->artifact_id);
    }

    public function test_webhook_created_deployment_has_no_artifact_when_none_matches(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('b', 40);
        $this->grantLegacyFallback($project, $commitHash);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_pending_deployment_attaches_an_artifact_that_becomes_available_before_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('b', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertNull($deployment->artifact_id);

        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertSame($artifact->id, $deployment->fresh()->artifact_id);
    }

    public function test_pending_deployment_without_an_artifact_keeps_the_legacy_fallback_when_approved(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('f', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_manually_triggered_deployment_attaches_a_matching_artifact(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('c', 40);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertSame($artifact->id, $deployment->artifact_id);
    }

    public function test_an_artifact_belonging_to_a_different_project_is_never_attached(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $commitHash = str_repeat('d', 40);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $otherProject->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_an_artifact_for_a_different_commit_is_never_attached_at_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('1', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('2', 40),
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_an_artifact_still_uploading_is_never_attached(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('e', 40);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_UPLOADING,
        ]);

        $deployment = app(DeploymentDispatcher::class)->createManual($project, $commitHash, 'admin@example.test');

        $this->assertNull($deployment->artifact_id);
    }

    public function test_a_failed_artifact_is_never_attached_at_approval(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('9', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_FAILED,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_multi_target_approval_attaches_the_artifact_before_queued_tasks_are_created(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->count(2)->create(['environment_id' => $environment->id]);
        $commitHash = str_repeat('7', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $fresh = $deployment->fresh();
        $this->assertSame($artifact->id, $fresh->artifact_id);
        $this->assertCount(2, $fresh->tasks);
        $this->assertTrue($fresh->tasks->every(fn (DeploymentTask $task) => $task->status === DeploymentTask::STATUS_QUEUED));
    }

    public function test_auto_deploy_does_not_late_attach_an_artifact_uploaded_after_it_was_queued(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $commitHash = str_repeat('8', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNull($deployment->artifact_id);

        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->assertNull($deployment->fresh()->artifact_id);
    }

    public function test_approval_does_not_replace_an_existing_artifact_link(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $commitHash = str_repeat('3', 40);
        $this->grantLegacyFallback($project, $commitHash);
        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $existing = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('4', 40),
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $matching = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $deployment->update(['artifact_id' => $existing->id]);

        app(DeploymentDispatcher::class)->approve($deployment, 'admin@example.test');

        $this->assertSame($existing->id, $deployment->fresh()->artifact_id);
        $this->assertNotSame($matching->id, $deployment->fresh()->artifact_id);
    }

    public function test_rollback_never_attaches_a_matching_deploy_artifact(): void
    {
        $project = Project::factory()->create();
        $commitHash = str_repeat('5', 40);
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commitHash,
            'finished_at' => now(),
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $rollback = app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');

        $this->assertSame(Deployment::ACTION_ROLLBACK, $rollback->action);
        $this->assertNull($rollback->artifact_id);
    }

    /**
     * Regression test (artifact race condition): a project with
     * requires_complete_artifact=true that has no matching artifact at
     * CREATION time must NOT be failed at creation — the Local Agent may
     * still be uploading the exact-commit artifact. The deployment stays
     * queued so AgentJobService::claimNextJob() can re-attach it at claim
     * time, and only then fails closed if it is still missing.
     */
    public function test_requires_complete_artifact_without_artifact_stays_queued_at_creation(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $localAgent = LocalAgent::factory()->productionBuild()->create();
        $project->update(['authoritative_build_local_agent_id' => $localAgent->id]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $localAgent->id,
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('c0', 20),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $commitHash = str_repeat('c0', 20);
        $this->grantLegacyFallback($project, $commitHash);

        $job = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertInstanceOf(PublishJob::class, $job);
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $job->action);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_required_artifact_build_selects_the_capable_isolated_workspace_mapping(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $legacyAgent = LocalAgent::factory()->approved()->create(['agent_version' => '1.4.5']);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $legacyAgent->id,
            'local_path' => '/var/www/customer-site',
            'source_workspace_path' => null,
            'production_path' => '/var/www/customer-site',
            'git_branch' => 'main',
            'reported_at' => now(),
        ]);
        $capableAgent = LocalAgent::factory()->productionBuild()->create([
            'agent_version' => config('deployment.min_local_agent_version_for_artifact'),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $capableAgent->id]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $capableAgent->id,
            'local_path' => '/var/www/customer-site',
            'source_workspace_path' => '/opt/deployment-agent/workspaces/customer-site',
            'production_path' => '/var/www/customer-site',
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('ab', 20),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now()->subMinute(),
        ]);
        $this->grantLegacyFallback($project, str_repeat('ab', 20));

        $job = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('ab', 20), 'main', 'github');

        $this->assertInstanceOf(PublishJob::class, $job);
        $this->assertSame($capableAgent->id, $job->local_agent_id);
        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * Regression test (artifact race condition): the artifact lands a moment
     * AFTER the webhook created the queued deployment — claimNextJob()
     * re-attaches the exact-commit artifact and dispatches the job instead of
     * failing it. This is the exact production race that used to fail
     * deployments before the upload finished.
     */
    public function test_requires_complete_artifact_lands_after_creation_is_reattached_at_claim(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $localAgent = LocalAgent::factory()->productionBuild()->create();
        $project->update(['authoritative_build_local_agent_id' => $localAgent->id]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $localAgent->id,
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('f0', 20),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $commitHash = str_repeat('f0', 20);
        $this->grantLegacyFallback($project, $commitHash);

        $job = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');
        $this->assertInstanceOf(PublishJob::class, $job);
        $this->assertDatabaseCount('deployments', 0);

        // Upload completes a moment later.
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        app(PublishJobService::class)->reportResult($job, $commitHash, 'artifact uploaded');

        $deployment = Deployment::firstOrFail();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame($deployment->id, $job->fresh()->deployment_id);
    }

    public function test_requires_complete_artifact_with_matching_artifact_is_queued_normally(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $commitHash = str_repeat('d0', 20);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $this->grantLegacyFallback($project, $commitHash);

        $deployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->fresh()->status);
        $this->assertNotNull($deployment->fresh()->artifact_id);
    }

    public function test_multi_target_requires_complete_artifact_without_artifact_stays_queued_at_creation(): void
    {
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => true,
        ]);
        $server = Server::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $targets = DeploymentTarget::factory()->count(2)->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
        ]);
        $commitHash = str_repeat('e0', 20);
        $localAgent = LocalAgent::factory()->productionBuild()->create();
        $project->update(['authoritative_build_local_agent_id' => $localAgent->id]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $localAgent->id,
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('e0', 20),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $this->grantLegacyFallback($project, $commitHash);

        $job = app(DeploymentDispatcher::class)->createFromWebhook($project, $commitHash, 'main', 'dev@example.test');

        $this->assertInstanceOf(PublishJob::class, $job);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('deployment_tasks', 0);

        // The artifact lands after creation; the first claiming agent
        // re-attaches it and dispatches instead of failing closed.
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commitHash,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        app(PublishJobService::class)->reportResult($job, $commitHash, 'artifact uploaded');

        $deployment = Deployment::firstOrFail();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertCount(2, $deployment->tasks);
    }

    public function test_artifact_required_deployment_cannot_enter_queue_without_artifact(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);

        $this->expectException(\LogicException::class);

        Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a1', 20),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'triggered_by' => 'github',
        ]);
    }

    public function test_legacy_artifact_required_deployment_cannot_be_requeued_without_artifact(): void
    {
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $deployment = Deployment::withoutEvents(fn () => Deployment::create([
            'public_id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_FAILED,
            'commit_hash' => str_repeat('a2', 20),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'triggered_by' => 'github',
        ]));

        $this->expectException(\LogicException::class);
        $deployment->update(['status' => Deployment::STATUS_QUEUED]);
    }
}
