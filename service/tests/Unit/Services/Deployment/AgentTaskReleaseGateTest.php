<?php

namespace Tests\Unit\Services\Deployment;

use App\Models\Agent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\Artifact\ArtifactService;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase: Laravel Release Completeness - the multi-target/task execution
 * path must carry the full artifact contract (commit_hash + required_paths),
 * must never dispatch an artifact task to a Server Agent too old to enforce
 * the release gate, and must fail closed (artifact_required_missing) for
 * requires_complete_artifact projects that have no exact-commit artifact -
 * never silently falling back to the live-directory git pipeline.
 */
class AgentTaskReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_AGENT = '1.2.0';
    private const OLD_AGENT = '1.1.0';

    private function makeTask(array $overrides = []): array
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => self::CURRENT_AGENT]);
        $project = Project::factory()->create(array_merge([
            'pipeline' => [['name' => 'echo', 'command' => 'echo hello']],
            'requires_complete_artifact' => false,
        ], $overrides['project'] ?? []));
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'name' => 'web-01',
        ]);
        // Some tests deliberately manufacture legacy invalid rows to keep
        // the claim-time defense covered; normal creation now rejects them.
        $deployment = Deployment::withoutEvents(fn () => Deployment::factory()->create(array_merge([
                'public_id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'server_id' => $target->server_id,
                'status' => Deployment::STATUS_QUEUED,
                'commit_hash' => str_repeat('a', 40),
            ], $overrides['deployment'] ?? [])));
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_QUEUED,
        ]);

        return compact('agent', 'project', 'deployment', 'task');
    }

    private function attachArtifact(array $ctx, string $commitHash, array $requiredPaths = ['artisan', 'vendor/autoload.php']): Artifact
    {
        $artifact = Artifact::factory()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $commitHash,
            'required_paths' => $requiredPaths,
        ]);
        $ctx['deployment']->update(['artifact_id' => $artifact->id]);

        return $artifact;
    }

    public function test_task_artifact_payload_carries_commit_hash_and_required_paths(): void
    {
        $ctx = $this->makeTask();
        $artifact = $this->attachArtifact($ctx, $ctx['deployment']->commit_hash);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $artifactPayload = $claimed['job']['artifact'];
        $this->assertSame($artifact->public_id, $artifactPayload['public_id']);
        $this->assertSame($ctx['deployment']->commit_hash, $artifactPayload['commit_hash']);
        $this->assertSame(['artisan', 'vendor/autoload.php'], $artifactPayload['required_paths']);
        $this->assertSame($ctx['deployment']->commit_hash, $claimed['job']['commit_hash']);
    }

    public function test_task_payload_carries_persistent_runtime_mapping(): void
    {
        $ctx = $this->makeTask(['project' => [
            'artifact_preserve_paths' => ['.env'],
            'artifact_persistent_paths' => ['public/uploads' => 'uploads'],
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]]);
        $ctx['agent']->update(['agent_version' => '1.3.3']);
        $this->attachArtifact($ctx, $ctx['deployment']->commit_hash);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertSame([
            'paths' => ['public/uploads' => 'uploads'],
            'root' => '/var/www/customer-site-shared',
            'preserve_paths' => ['.env'],
        ], $claimed['job']['artifact_persistence']);
    }

    public function test_old_server_agent_rejects_artifact_task_before_execution(): void
    {
        $ctx = $this->makeTask();
        $this->attachArtifact($ctx, $ctx['deployment']->commit_hash);
        $ctx['agent']->update(['agent_version' => self::OLD_AGENT]);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $ctx['deployment']->fresh()->failure_code);
    }

    public function test_unknown_server_agent_version_rejects_artifact_task(): void
    {
        $ctx = $this->makeTask();
        $this->attachArtifact($ctx, $ctx['deployment']->commit_hash);
        $ctx['agent']->update(['agent_version' => null]);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $ctx['deployment']->fresh()->failure_code);
    }

    public function test_current_server_agent_accepts_artifact_task(): void
    {
        $ctx = $this->makeTask();
        $this->attachArtifact($ctx, $ctx['deployment']->commit_hash);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_RUNNING, $ctx['task']->fresh()->status);
    }

    public function test_agent_without_http_health_protocol_is_rejected_before_task_execution(): void
    {
        $ctx = $this->makeTask([
            'deployment' => [
                'http_health_config' => ['enabled' => true, 'url' => 'https://customer.example/'],
                'http_health_status' => Deployment::HTTP_HEALTH_PENDING,
            ],
        ]);

        $this->assertNull(app(AgentTaskService::class)->claimNextTask($ctx['agent']));
        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $ctx['deployment']->fresh()->failure_code);
    }

    public function test_http_health_capable_agent_receives_the_snapshotted_health_contract(): void
    {
        $ctx = $this->makeTask([
            'deployment' => [
                'http_health_config' => ['enabled' => true, 'url' => 'https://customer.example/'],
                'http_health_status' => Deployment::HTTP_HEALTH_PENDING,
            ],
        ]);
        $ctx['agent']->update(['agent_version' => '1.3.0']);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $this->assertSame('https://customer.example/', $claimed['job']['http_health_check']['url']);
    }

    public function test_requires_complete_artifact_with_no_artifact_fails_closed(): void
    {
        $ctx = $this->makeTask(['project' => ['requires_complete_artifact' => true]]);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING, $ctx['deployment']->fresh()->failure_code);
        $this->assertNull($ctx['deployment']->fresh()->artifact_id);
    }

    public function test_requires_complete_artifact_with_wrong_commit_artifact_fails_closed(): void
    {
        $ctx = $this->makeTask(['project' => ['requires_complete_artifact' => true]]);
        $this->attachArtifact($ctx, str_repeat('b', 40)); // artifact for a DIFFERENT commit
        // Deliberately restore a legacy invalid row; normal updates now
        // reject removing the artifact from a queued required deployment.
        Deployment::withoutEvents(fn () => $ctx['deployment']->update(['artifact_id' => null]));

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNull($claimed);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING, $ctx['deployment']->fresh()->failure_code);
        $this->assertNull($ctx['deployment']->fresh()->artifact_id); // wrong-commit artifact NEVER attached
    }

    public function test_requires_complete_artifact_with_exact_artifact_reattaches_and_dispatches(): void
    {
        $ctx = $this->makeTask(['project' => ['requires_complete_artifact' => true]]);
        // Simulate the real timing race: webhook deployment created BEFORE
        // the Local Agent finished uploading -> artifact exists but was not
        // attached to the deployment yet.
        $artifact = Artifact::factory()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $ctx['deployment']->commit_hash,
            'required_paths' => ['artisan'],
        ]);

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $this->assertSame($artifact->public_id, $claimed['job']['artifact']['public_id']);
        $this->assertSame($artifact->commit_hash, $claimed['job']['artifact']['commit_hash']);
        $this->assertSame($artifact->id, $ctx['deployment']->fresh()->artifact_id);
    }

    public function test_non_artifact_required_project_keeps_git_pipeline_behavior(): void
    {
        $ctx = $this->makeTask(); // requires_complete_artifact = false, no artifact

        $claimed = app(AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $this->assertNull($claimed['job']['artifact']); // git pipeline payload, unchanged
        $this->assertSame(DeploymentTask::STATUS_RUNNING, $ctx['task']->fresh()->status);
    }

    // --- ArtifactService::attachToDeploymentIfAvailable exact-commit correlation ---

    public function test_attach_only_exact_commit_artifact(): void
    {
        $ctx = $this->makeTask();
        $exact = Artifact::factory()->create(['project_id' => $ctx['project']->id, 'commit_hash' => $ctx['deployment']->commit_hash]);
        $otherCommit = Artifact::factory()->create(['project_id' => $ctx['project']->id, 'commit_hash' => str_repeat('c', 40)]);
        $otherProject = Artifact::factory()->create(['project_id' => Project::factory()->create()->id, 'commit_hash' => $ctx['deployment']->commit_hash]);

        $attached = app(ArtifactService::class)->attachToDeploymentIfAvailable($ctx['deployment']->fresh());

        $this->assertSame($exact->id, $attached->id);
        $this->assertSame($exact->id, $ctx['deployment']->fresh()->artifact_id);
        $this->assertNotNull($otherCommit);
        $this->assertNotNull($otherProject);
    }

    public function test_attach_never_matches_wrong_commit_or_wrong_project(): void
    {
        $ctx = $this->makeTask();
        $wrongCommit = Artifact::factory()->create(['project_id' => $ctx['project']->id, 'commit_hash' => str_repeat('d', 40)]);
        $wrongProject = Artifact::factory()->create(['project_id' => Project::factory()->create()->id, 'commit_hash' => $ctx['deployment']->commit_hash]);

        $attached = app(ArtifactService::class)->attachToDeploymentIfAvailable($ctx['deployment']->fresh());

        $this->assertNull($attached);
        $this->assertNull($ctx['deployment']->fresh()->artifact_id);
        $this->assertNotNull($wrongCommit);
        $this->assertNotNull($wrongProject);
    }
}
