<?php

namespace Tests\Feature\Console;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Deployment\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PruneDeployedArtifactsTest extends TestCase
{
    use RefreshDatabase;

    private int $minute = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function artifact(Project $project, string $name): Artifact
    {
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'disk_path' => "{$project->project_key}/{$name}.zip",
            'size_bytes' => 7,
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'content');

        return $artifact;
    }

    private function deploy(Project $project, Artifact $artifact, string $status = Deployment::STATUS_SUCCEEDED): Deployment
    {
        $this->minute++;

        return Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'artifact_id' => $artifact->id,
            'commit_hash' => $artifact->commit_hash,
            'status' => $status,
            'finished_at' => $status === Deployment::STATUS_SUCCEEDED ? now()->addMinutes($this->minute) : null,
        ]);
    }

    private function assertPruned(Artifact $artifact): void
    {
        $this->assertSame(Artifact::STATUS_DELETED, $artifact->fresh()->status);
        Storage::disk('artifacts')->assertMissing($artifact->disk_path);
    }

    private function assertKept(Artifact $artifact): void
    {
        $this->assertSame(Artifact::STATUS_AVAILABLE, $artifact->fresh()->status);
        Storage::disk('artifacts')->assertExists($artifact->disk_path);
    }

    public function test_dry_run_reports_candidates_and_deletes_nothing(): void
    {
        $project = Project::factory()->create();
        $artifacts = [];
        foreach (range(1, 5) as $i) {
            $artifacts[$i] = $this->artifact($project, "a{$i}");
            $this->deploy($project, $artifacts[$i]);
        }

        $this->artisan('artifacts:prune-deployed', ['--keep' => 3])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('2 candidate(s)')
            ->assertSuccessful();

        foreach ($artifacts as $artifact) {
            $this->assertKept($artifact);
        }
    }

    public function test_execute_keeps_the_newest_n_deployed_and_prunes_the_rest_keeping_rows(): void
    {
        $project = Project::factory()->create();
        $artifacts = [];
        foreach (range(1, 5) as $i) {
            $artifacts[$i] = $this->artifact($project, "a{$i}");
            $this->deploy($project, $artifacts[$i]);
        }

        $this->artisan('artifacts:prune-deployed', ['--keep' => 3, '--execute' => true])->assertSuccessful();

        $this->assertPruned($artifacts[1]);
        $this->assertPruned($artifacts[2]);
        $this->assertKept($artifacts[3]);
        $this->assertKept($artifacts[4]);
        $this->assertKept($artifacts[5]);

        // History keeps its link to the pruned artifact.
        $this->assertSame(1, Deployment::where('artifact_id', $artifacts[1]->id)->count());
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'artifact.deployed_retention_pruned', 'subject_id' => $artifacts[1]->id]);
    }

    public function test_default_keep_comes_from_config(): void
    {
        config(['deployment.artifact_keep_deployed_per_project' => 2]);
        $project = Project::factory()->create();
        $artifacts = [];
        foreach (range(1, 4) as $i) {
            $artifacts[$i] = $this->artifact($project, "a{$i}");
            $this->deploy($project, $artifacts[$i]);
        }

        $this->artisan('artifacts:prune-deployed', ['--execute' => true])->assertSuccessful();

        $this->assertPruned($artifacts[1]);
        $this->assertPruned($artifacts[2]);
        $this->assertKept($artifacts[3]);
        $this->assertKept($artifacts[4]);
    }

    public function test_live_and_rollback_target_survive_even_outside_the_newest_n(): void
    {
        $project = Project::factory()->create();
        $rollbackTarget = $this->artifact($project, 'rollback');
        $this->deploy($project, $rollbackTarget);
        $live = $this->artifact($project, 'live');
        $this->deploy($project, $live);
        // Newer attempts that never succeeded push live/rollback out of N.
        $failedOld = $this->artifact($project, 'failed-old');
        $this->deploy($project, $failedOld, Deployment::STATUS_FAILED);
        $failedNew = $this->artifact($project, 'failed-new');
        $this->deploy($project, $failedNew, Deployment::STATUS_FAILED);

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();

        $this->assertKept($failedNew);   // newest
        $this->assertKept($live);        // live
        $this->assertKept($rollbackTarget); // rollback target
        $this->assertPruned($failedOld);
    }

    public function test_a_rollback_onto_an_old_artifact_makes_it_live_and_protects_the_one_it_replaced(): void
    {
        $project = Project::factory()->create();
        $old = $this->artifact($project, 'old');
        $this->deploy($project, $old);
        $mid = $this->artifact($project, 'mid');
        $this->deploy($project, $mid);
        $bad = $this->artifact($project, 'bad');
        $this->deploy($project, $bad);
        $this->deploy($project, $old); // rolled back to "old": now live

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();

        $this->assertKept($old); // live (and newest by use)
        $this->assertKept($bad); // previous succeeded = rollback target
        $this->assertPruned($mid);
    }

    public function test_an_in_flight_deployment_artifact_is_never_pruned(): void
    {
        $project = Project::factory()->create();
        $inFlight = $this->artifact($project, 'in-flight');
        $this->deploy($project, $inFlight, Deployment::STATUS_RUNNING);
        foreach (range(1, 3) as $i) {
            $this->deploy($project, $this->artifact($project, "a{$i}"));
        }

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();

        $this->assertKept($inFlight);
    }

    public function test_each_deployment_targets_live_and_rollback_artifacts_are_protected(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create(['environment_id' => $environment->id]);

        $targetRollback = $this->artifact($project, 'target-rollback');
        $targetLive = $this->artifact($project, 'target-live');
        foreach ([$targetRollback, $targetLive] as $artifact) {
            DeploymentTask::factory()->create([
                'deployment_id' => $this->deploy($project, $artifact, Deployment::STATUS_PARTIAL_SUCCESS)->id,
                'deployment_target_id' => $target->id,
                'status' => DeploymentTask::STATUS_SUCCEEDED,
            ]);
        }
        $unprotected = $this->artifact($project, 'unprotected');
        $this->deploy($project, $unprotected, Deployment::STATUS_FAILED);
        foreach (range(1, 2) as $i) {
            $this->deploy($project, $this->artifact($project, "n{$i}"));
        }

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();

        $this->assertKept($targetLive);
        $this->assertKept($targetRollback);
        $this->assertPruned($unprotected);
    }

    public function test_undeployed_artifacts_are_left_to_the_existing_retention(): void
    {
        $project = Project::factory()->create();
        $undeployed = $this->artifact($project, 'undeployed');
        foreach (range(1, 3) as $i) {
            $this->deploy($project, $this->artifact($project, "a{$i}"));
        }

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();

        $this->assertKept($undeployed);
    }

    public function test_project_option_limits_the_run(): void
    {
        $one = Project::factory()->create();
        $two = Project::factory()->create();
        $oneOld = $this->artifact($one, 'one-old');
        $this->deploy($one, $oneOld, Deployment::STATUS_FAILED);
        $this->deploy($one, $this->artifact($one, 'one-new'));
        $twoOld = $this->artifact($two, 'two-old');
        $this->deploy($two, $twoOld, Deployment::STATUS_FAILED);
        $this->deploy($two, $this->artifact($two, 'two-new'));

        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true, '--project' => $one->project_key])->assertSuccessful();

        $this->assertPruned($oneOld);
        $this->assertKept($twoOld);
    }

    public function test_a_pruned_artifact_can_be_rebuilt_for_the_same_commit(): void
    {
        $project = Project::factory()->create();
        $old = $this->artifact($project, 'old');
        $this->deploy($project, $old, Deployment::STATUS_FAILED);
        $this->deploy($project, $this->artifact($project, 'new'));
        $this->artisan('artifacts:prune-deployed', ['--keep' => 1, '--execute' => true])->assertSuccessful();
        $this->assertPruned($old);

        $checksum = hash('sha256', 'rebuilt');
        $job = PublishJob::factory()->create(['project_id' => $project->id, 'action' => PublishJob::ACTION_BUILD_ARTIFACT]);
        $revived = app(ArtifactService::class)->createPending($project, $old->commit_hash, 'main', $checksum, 7, 'dev-machine', [], $job->id);

        $this->assertSame($old->id, $revived->id);
        $this->assertSame(Artifact::STATUS_UPLOADING, $revived->status);
        $this->assertSame($checksum, $revived->checksum_sha256);
    }

    public function test_rollback_to_a_deployment_whose_artifact_was_pruned_is_refused(): void
    {
        $project = Project::factory()->create();
        $old = $this->artifact($project, 'old');
        $target = $this->deploy($project, $old);
        $old->update(['status' => Artifact::STATUS_DELETED]);

        try {
            app(RollbackService::class)->rollbackTo($target, 'admin@example.test');
            $this->fail('Rollback onto a pruned artifact must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('removed by retention', $e->getMessage());
        }
    }
}
