<?php

namespace Tests\Unit\Services\Deployment;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\Recipe\RecipeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Root-cause fix (sandogh-family-fund incomplete deploys), Phase 2:
 * AgentJobService's lease/heartbeat lifecycle (mirroring
 * PublishJobLeaseTest for the separate Deployment domain), the
 * deployment_recipe/recipe_rollback precedence rules that let a recipe
 * take over from `pipeline` without touching a single existing Project,
 * and the intermediate-phase status machine that makes `deployed_at`
 * (not `finished_at`) Phase 5's real "verified deployed" signal.
 */
class AgentJobServiceLeaseAndRecipeTest extends TestCase
{
    use RefreshDatabase;

    private function queuedDeployment(?array $recipe = null, string $action = Deployment::ACTION_DEPLOY): Deployment
    {
        $server = Server::factory()->create();
        $project = Project::factory()->create([
            'server_id' => $server->id,
            'deployment_recipe' => $recipe,
        ]);

        return Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => $action,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);
    }

    private function minimalRecipe(): array
    {
        return app(RecipeValidator::class)->validate([
            ['type' => 'shell', 'command' => 'echo hi'],
        ]);
    }

    public function test_claim_next_job_sets_a_lease_and_heartbeat(): void
    {
        $deployment = $this->queuedDeployment();

        $claimed = app(AgentJobService::class)->claimNextJob($deployment->server);

        $this->assertNotNull($claimed);
        $updated = $deployment->fresh();
        $this->assertSame(Deployment::STATUS_RUNNING, $updated->status);
        $this->assertNotNull($updated->heartbeat_at);
        $this->assertNotNull($updated->lease_expires_at);
        $this->assertTrue($updated->lease_expires_at->isFuture());
    }

    public function test_claim_next_job_omits_deployment_recipe_for_a_plain_pipeline_project(): void
    {
        $deployment = $this->queuedDeployment(recipe: null);

        $claimed = app(AgentJobService::class)->claimNextJob($deployment->server);

        $this->assertNull($claimed['job']['deployment_recipe']);
        $this->assertFalse($claimed['job']['recipe_rollback']);
        $this->assertIsArray($claimed['job']['pipeline']);
    }

    public function test_claim_next_job_includes_the_normalized_recipe_when_the_project_has_one(): void
    {
        $recipe = $this->minimalRecipe();
        $deployment = $this->queuedDeployment(recipe: $recipe);

        $claimed = app(AgentJobService::class)->claimNextJob($deployment->server);

        $this->assertSame($recipe, $claimed['job']['deployment_recipe']);
    }

    public function test_claim_next_job_marks_recipe_rollback_only_for_a_rollback_action_on_a_recipe_project(): void
    {
        $recipe = $this->minimalRecipe();
        $deployRecipe = $this->queuedDeployment(recipe: $recipe, action: Deployment::ACTION_DEPLOY);
        $claimedDeploy = app(AgentJobService::class)->claimNextJob($deployRecipe->server);
        $this->assertFalse($claimedDeploy['job']['recipe_rollback']);

        $rollbackRecipe = $this->queuedDeployment(recipe: $recipe, action: Deployment::ACTION_ROLLBACK);
        $claimedRollback = app(AgentJobService::class)->claimNextJob($rollbackRecipe->server);
        $this->assertTrue($claimedRollback['job']['recipe_rollback']);

        $rollbackPlain = $this->queuedDeployment(recipe: null, action: Deployment::ACTION_ROLLBACK);
        $claimedPlainRollback = app(AgentJobService::class)->claimNextJob($rollbackPlain->server);
        $this->assertFalse($claimedPlainRollback['job']['recipe_rollback']);
    }

    public function test_update_status_accepts_intermediate_phases_without_setting_finished_at(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $service->updateStatus($deployment, Deployment::STATUS_RUNNING);

        foreach ([Deployment::STATUS_BUILDING, Deployment::STATUS_MIGRATING, Deployment::STATUS_ACTIVATING, Deployment::STATUS_VERIFYING] as $phase) {
            $updated = $service->updateStatus($deployment, $phase);
            $this->assertSame($phase, $updated->status);
            $this->assertNull($updated->finished_at);
            $this->assertNull($updated->deployed_at);
        }
    }

    public function test_update_status_succeeded_sets_deployed_at(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $service->updateStatus($deployment, Deployment::STATUS_RUNNING);

        $updated = $service->updateStatus($deployment, Deployment::STATUS_SUCCEEDED);

        $this->assertNotNull($updated->finished_at);
        $this->assertNotNull($updated->deployed_at);
        $this->assertTrue($updated->isVerifiedDeployed());
        $this->assertNull($updated->failure_code);
    }

    public function test_update_status_failed_sets_failure_code_and_no_deployed_at(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $service->updateStatus($deployment, Deployment::STATUS_RUNNING);

        $updated = $service->updateStatus($deployment, Deployment::STATUS_FAILED, 'build blew up', Deployment::FAILURE_CODE_BUILD_FAILED);

        $this->assertNotNull($updated->finished_at);
        $this->assertNull($updated->deployed_at);
        $this->assertFalse($updated->isVerifiedDeployed());
        $this->assertSame(Deployment::FAILURE_CODE_BUILD_FAILED, $updated->failure_code);
        $this->assertSame('build blew up', $updated->failure_message);
    }

    public function test_update_status_rolled_back_sets_rolled_back_at(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $service->updateStatus($deployment, Deployment::STATUS_RUNNING);
        $service->updateStatus($deployment, Deployment::STATUS_ACTIVATING);

        $updated = $service->updateStatus($deployment, Deployment::STATUS_ROLLED_BACK, 'health check failed after activation', Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED);

        $this->assertSame(Deployment::STATUS_ROLLED_BACK, $updated->status);
        $this->assertNotNull($updated->rolled_back_at);
        $this->assertNotNull($updated->finished_at);
        $this->assertTrue($updated->isTerminal());
        $this->assertSame(Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED, $updated->failure_code);
    }

    public function test_update_status_rejects_an_unknown_status(): void
    {
        $deployment = $this->queuedDeployment();

        $this->expectException(HttpException::class);

        app(AgentJobService::class)->updateStatus($deployment, 'pending_approval');
    }

    public function test_heartbeat_renews_the_lease_for_an_in_progress_deployment(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $claimed = $service->claimNextJob($deployment->server);
        $original = $deployment->fresh()->lease_expires_at;

        sleep(1);
        $service->heartbeat($deployment->fresh());

        $this->assertTrue($deployment->fresh()->lease_expires_at->gt($original));
    }

    public function test_heartbeat_on_a_terminal_deployment_is_a_no_op(): void
    {
        $deployment = $this->queuedDeployment();
        $service = app(AgentJobService::class);
        $service->updateStatus($deployment, Deployment::STATUS_RUNNING);
        $service->updateStatus($deployment, Deployment::STATUS_SUCCEEDED);
        $leaseBefore = $deployment->fresh()->lease_expires_at;

        $result = $service->heartbeat($deployment->fresh());

        $this->assertSame(Deployment::STATUS_SUCCEEDED, $result->status);
        $this->assertEquals($leaseBefore, $result->fresh()->lease_expires_at);
    }

    public function test_heartbeat_on_a_not_yet_claimed_deployment_is_rejected(): void
    {
        $deployment = $this->queuedDeployment();

        $this->expectException(HttpException::class);

        app(AgentJobService::class)->heartbeat($deployment);
    }

    public function test_record_log_stores_step_type_status_and_failure_code(): void
    {
        $deployment = $this->queuedDeployment();
        app(AgentJobService::class)->updateStatus($deployment, Deployment::STATUS_RUNNING);

        $entry = app(AgentJobService::class)->recordLog($deployment, [
            'sequence' => 1,
            'step_name' => 'install_dependencies',
            'command' => 'npm ci',
            'output' => 'added 42 packages',
            'exit_code' => 0,
            'status' => 'succeeded',
            'step_type' => 'install_dependencies',
            'failure_code' => null,
        ]);

        $this->assertSame('succeeded', $entry->status);
        $this->assertSame('install_dependencies', $entry->step_type);
        $this->assertNotNull($deployment->fresh()->heartbeat_at);
    }

    public function test_record_log_defaults_step_metadata_to_null_for_a_legacy_pipeline_step(): void
    {
        $deployment = $this->queuedDeployment();
        app(AgentJobService::class)->updateStatus($deployment, Deployment::STATUS_RUNNING);

        $entry = app(AgentJobService::class)->recordLog($deployment, [
            'sequence' => 1,
            'step_name' => 'git pull',
            'command' => 'git fetch origin main',
            'exit_code' => 0,
        ]);

        $this->assertNull($entry->status);
        $this->assertNull($entry->step_type);
        $this->assertNull($entry->failure_code);
    }
}
