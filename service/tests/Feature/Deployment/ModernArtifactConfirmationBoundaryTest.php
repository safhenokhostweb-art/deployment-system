<?php

namespace Tests\Feature\Deployment;

use App\Models\Agent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ModernArtifactConfirmationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_incident_path_is_not_claimable_until_the_exact_candidate_is_approved(): void
    {
        [$project, $release, $artifact] = $this->modernArtifact(['auto_deploy' => true]);
        Agent::factory()->approved()->create([
            'server_id' => $project->server_id,
            'agent_version' => (string) config('deployment.min_server_agent_version_for_artifact'),
        ]);
        $staleProject = Project::factory()->create();
        $stale = Deployment::factory()->create([
            'project_id' => $staleProject->id,
            'server_id' => $staleProject->server_id,
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approved_by' => null,
            'approved_at' => null,
            'queued_at' => null,
        ]);

        $deployment = app(DeploymentDispatcher::class)
            ->createFromExistingArtifact($project, $artifact, 'candidate-creator@example.test');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertNull($deployment->approved_by);
        $this->assertNull($deployment->approved_at);
        $this->assertNull($deployment->queued_at);
        $this->assertSame($project->id, $deployment->project_id);
        $this->assertSame($release->id, $deployment->release_id);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame($artifact->commit_hash, $deployment->commit_hash);
        $this->assertSame($artifact->branch, $deployment->branch);
        $this->assertDatabaseCount('deployment_tasks', 0);
        $this->assertDatabaseCount('agent_jobs', 0);
        $this->assertNull(app(AgentJobService::class)->claimNextJob($project->server));

        $approved = app(DeploymentDispatcher::class)
            ->approve($deployment, 'actual-approver@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $approved->status);
        $this->assertSame('actual-approver@example.test', $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->queued_at);
        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $stale->fresh()->status);
        $this->assertNull($stale->fresh()->approved_by);
        $this->assertNotNull(app(AgentJobService::class)->claimNextJob($project->server));
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment.approved',
            'subject_id' => $deployment->id,
            'actor_identifier' => 'actual-approver@example.test',
        ]);
    }

    public function test_multi_target_candidate_creates_no_task_or_agent_job_before_approval(): void
    {
        [$project, $release, $artifact] = $this->modernArtifact(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);
        $agent = Agent::factory()->approved()->create([
            'server_id' => $target->server_id,
            'agent_version' => (string) config('deployment.min_server_agent_version_for_artifact'),
        ]);

        $deployment = app(DeploymentDispatcher::class)
            ->createFromExistingArtifact($project, $artifact, 'candidate-creator@example.test');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertSame($release->id, $deployment->release_id);
        $this->assertDatabaseCount('deployment_tasks', 0);
        $this->assertDatabaseCount('agent_jobs', 0);
        $this->assertNull(app(AgentTaskService::class)->claimNextTask($agent));

        app(DeploymentDispatcher::class)->approve($deployment, 'actual-approver@example.test');

        $this->assertDatabaseHas('deployment_tasks', [
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_QUEUED,
        ]);
        $this->assertNotNull(app(AgentTaskService::class)->claimNextTask($agent));
        $this->assertDatabaseCount('agent_jobs', 1);
    }

    public function test_manual_creation_cannot_reclassify_an_exact_commit_release_as_legacy(): void
    {
        [$project, $release, $artifact] = $this->modernArtifact(['auto_deploy' => true]);

        $deployment = app(DeploymentDispatcher::class)
            ->createManual($project, $artifact->commit_hash, 'operator@example.test');

        $this->assertInstanceOf(Deployment::class, $deployment);
        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertSame($release->id, $deployment->release_id);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame(Release::READINESS_POLICY_EXACT_COMMIT, $release->fresh()->readiness_policy);
        $this->assertNull($release->fresh()->legacy_fallback_eligible_at);
    }

    public function test_approval_rechecks_release_readiness_before_queueing(): void
    {
        [$project, $release, $artifact] = $this->modernArtifact();
        $deployment = app(DeploymentDispatcher::class)
            ->createFromExistingArtifact($project, $artifact, 'candidate-creator@example.test');
        $release->update(['status' => Release::STATUS_FAILED]);

        try {
            app(DeploymentDispatcher::class)->approve($deployment, 'approver@example.test');
            $this->fail('A candidate with a no-longer-ready Release unexpectedly entered the queue.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->fresh()->status);
        $this->assertNull($deployment->fresh()->approved_by);
        $this->assertDatabaseCount('deployment_tasks', 0);
        $this->assertDatabaseCount('agent_jobs', 0);
    }

    public function test_pre_release_artifact_selection_retains_legacy_manual_approval_behavior(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => null,
            'commit_hash' => str_repeat('d', 40),
            'branch' => $project->branch,
        ]);

        $deployment = app(DeploymentDispatcher::class)
            ->createFromExistingArtifact($project, $artifact, 'legacy-operator@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame('legacy-operator@example.test', $deployment->approved_by);
        $this->assertNull($deployment->release_id);
    }

    /** @return array{Project, Release, Artifact} */
    private function modernArtifact(array $projectAttributes = []): array
    {
        $sha = str_repeat('a', 40);
        $project = Project::factory()->create(array_merge([
            'branch' => 'main',
            'requires_complete_artifact' => true,
        ], $projectAttributes));
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Release::STATUS_READY,
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
            'metadata' => ['release_ready' => true, 'risk_flags' => []],
            'ready_at' => now(),
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
            'semantic_verified_at' => now(),
        ]);

        return [$project, $release, $artifact];
    }
}
