<?php

namespace Tests\Feature\Console;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\PublishJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrepareDependencyPinningRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
    }

    public function test_cancels_queued_v2_work_and_is_idempotent_by_run_id(): void
    {
        $project = Project::factory()->create(['project_key' => 'customer-site']);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'required_paths' => ['vendor/autoload.php', '.deployment/dependency-lock-v2'],
        ]);
        $deployment = Deployment::withoutEvents(fn () => Deployment::factory()->create([
            'public_id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'artifact_id' => $artifact->id,
            'status' => Deployment::STATUS_QUEUED,
        ]));
        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_APPROVED,
        ]);

        $this->artisan('dependency-pinning:prepare-rollback', ['--run-id' => 'rollback-001'])->assertSuccessful();
        $this->assertSame(Deployment::STATUS_CANCELLED, $deployment->fresh()->status);
        $this->assertSame(PublishJob::STATUS_REJECTED, $job->fresh()->status);
        Storage::disk('local')->assertExists('dependency-pinning-rollback/rollback-001.json');
        $this->artisan('dependency-pinning:prepare-rollback', ['--run-id' => 'rollback-001'])->assertSuccessful();
    }

    public function test_refuses_when_policy_is_still_enabled_or_v2_work_is_running(): void
    {
        $project = Project::factory()->create(['project_key' => 'customer-site']);
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);
        $this->artisan('dependency-pinning:prepare-rollback', ['--run-id' => 'rollback-on'])->assertFailed();

        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
        PublishJob::factory()->create(['project_id' => $project->id, 'action' => PublishJob::ACTION_BUILD_ARTIFACT, 'status' => PublishJob::STATUS_RUNNING]);
        $this->artisan('dependency-pinning:prepare-rollback', ['--run-id' => 'rollback-running'])->assertFailed();
    }
}
