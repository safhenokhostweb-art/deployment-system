<?php

namespace Tests\Feature\Deployment;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\Artifact\ArtifactPhysicalIntegrityException;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactPhysicalIntegrityPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_invalid_artifact_is_rejected_before_deployment_or_agent_work_is_dispatched(): void
    {
        $project = Project::factory()->create([
            'requires_complete_artifact' => true,
            'branch' => 'main',
        ]);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        try {
            app(DeploymentDispatcher::class)->createFromExistingArtifact($project, $artifact, 'operator@example.test');
            $this->fail('Expected an ArtifactPhysicalIntegrityException.');
        } catch (ArtifactPhysicalIntegrityException $exception) {
            $this->assertSame('artifact_file_missing', $exception->reason);
            $this->assertStringContainsString('physical file is missing', $exception->getMessage());
        }

        $this->assertSame(0, Deployment::count());
        $this->assertSame(0, DeploymentTask::count());
        $this->assertSame(0, AgentJob::count());
    }

    public function test_preexisting_queued_task_with_invalid_artifact_is_never_dispatched_to_agent(): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.3.3']);
        $project = Project::factory()->create(['requires_complete_artifact' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
        ]);
        $artifact = Artifact::factory()->create(['project_id' => $project->id]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $agent->server_id,
            'artifact_id' => $artifact->id,
            'commit_hash' => $artifact->commit_hash,
            'branch' => $artifact->branch,
            'status' => Deployment::STATUS_QUEUED,
        ]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_QUEUED,
        ]);

        $this->assertNull(app(AgentTaskService::class)->claimNextTask($agent));

        $this->assertSame(DeploymentTask::STATUS_FAILED, $task->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_INTEGRITY_FAILED, $task->fresh()->failure_code);
        $this->assertNotSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
        $this->assertSame(0, AgentJob::count());
    }
}
