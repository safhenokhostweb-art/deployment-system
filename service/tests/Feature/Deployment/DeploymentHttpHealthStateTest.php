<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Agent;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentHttpHealthStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_500_after_successful_execution_is_recorded_as_unhealthy(): void
    {
        $deployment = $this->queuedHealthDeployment();
        $this->recordHealth($deployment, exitCode: 1, output: 'Expected HTTP 200, got HTTP 500.');

        $updated = app(AgentJobService::class)->updateStatus(
            $deployment,
            Deployment::STATUS_FAILED,
            'Homepage returned HTTP 500.',
            Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED,
        );

        $this->assertSame(Deployment::STATUS_FAILED, $updated->status);
        $this->assertSame(Deployment::EXECUTION_SUCCEEDED, $updated->execution_status);
        $this->assertSame(Deployment::HTTP_HEALTH_FAILED, $updated->http_health_status);
        $this->assertFalse($updated->isVerifiedDeployed());
        $this->assertSame(Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED, $updated->failure_code);
        $this->assertStringContainsString('HTTP 500', $updated->http_health_details['output']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('deployments.show', $updated))
            ->assertOk()
            ->assertSee('اعتبارسنجی Artifact')
            ->assertSee('اجرای استقرار')
            ->assertSee('سلامت HTTP')
            ->assertSee('HTTP 500');
    }

    public function test_http_timeout_is_not_health_verified(): void
    {
        $deployment = $this->queuedHealthDeployment();
        $this->recordHealth($deployment, exitCode: 1, output: 'Request timed out.', timedOut: true);

        $updated = app(AgentJobService::class)->updateStatus(
            $deployment,
            Deployment::STATUS_FAILED,
            'Customer-facing health request timed out.',
            Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED,
        );

        $this->assertSame(Deployment::HTTP_HEALTH_FAILED, $updated->http_health_status);
        $this->assertFalse($updated->isVerifiedDeployed());
        $this->assertStringContainsString('timed out', $updated->http_health_details['output']);
    }

    public function test_success_report_without_required_http_evidence_fails_closed(): void
    {
        $updated = app(AgentJobService::class)->updateStatus(
            $this->queuedHealthDeployment(),
            Deployment::STATUS_SUCCEEDED,
        );

        $this->assertSame(Deployment::STATUS_FAILED, $updated->status);
        $this->assertSame(Deployment::HTTP_HEALTH_FAILED, $updated->http_health_status);
        $this->assertSame(Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED, $updated->failure_code);
        $this->assertFalse($updated->isVerifiedDeployed());
    }

    public function test_http_200_evidence_marks_runtime_health_verified(): void
    {
        $deployment = $this->queuedHealthDeployment();
        $this->recordHealth($deployment, exitCode: 0, output: 'Homepage returned HTTP 200.');

        $updated = app(AgentJobService::class)->updateStatus($deployment, Deployment::STATUS_SUCCEEDED);

        $this->assertSame(Deployment::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame(Deployment::EXECUTION_SUCCEEDED, $updated->execution_status);
        $this->assertSame(Deployment::HTTP_HEALTH_VERIFIED, $updated->http_health_status);
        $this->assertNotNull($updated->http_health_verified_at);
        $this->assertTrue($updated->isVerifiedDeployed());
    }

    public function test_no_configured_http_check_can_succeed_but_remains_not_verified(): void
    {
        $deployment = Deployment::factory()->create([
            'status' => Deployment::STATUS_QUEUED,
            'http_health_config' => null,
            'http_health_status' => Deployment::HTTP_HEALTH_NOT_CONFIGURED,
        ]);

        $updated = app(AgentJobService::class)->updateStatus($deployment, Deployment::STATUS_SUCCEEDED);

        $this->assertSame(Deployment::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame(Deployment::EXECUTION_SUCCEEDED, $updated->execution_status);
        $this->assertSame(Deployment::HTTP_HEALTH_NOT_CONFIGURED, $updated->http_health_status);
        $this->assertFalse($updated->isVerifiedDeployed());
    }

    public function test_available_artifact_and_release_manifest_do_not_imply_runtime_health(): void
    {
        $artifact = Artifact::factory()->create([
            'status' => Artifact::STATUS_AVAILABLE,
            'required_paths' => ['artisan', 'bootstrap/app.php'],
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $artifact->project_id,
            'artifact_id' => $artifact->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'deployed_at' => now(),
            'http_health_status' => Deployment::HTTP_HEALTH_NOT_CONFIGURED,
        ]);

        $this->assertTrue($artifact->isAvailable());
        $this->assertFalse($deployment->isVerifiedDeployed());
    }

    public function test_manual_webhook_and_rollback_deployments_snapshot_project_health_policy(): void
    {
        $config = $this->healthConfig();
        $project = Project::factory()->create([
            'auto_deploy' => true,
            'requires_complete_artifact' => false,
            'http_health_check' => $config,
        ]);

        $manual = app(DeploymentDispatcher::class)->createManual($project, str_repeat('1', 40), 'admin@example.test');
        Release::query()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('2', 40),
            'branch' => 'main',
            'source' => 'admin_backfill',
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'test_fixture_explicit_legacy_grant',
            'legacy_fallback_eligible_by' => 'test-harness',
        ]);
        $webhook = app(DeploymentDispatcher::class)->createFromWebhook($project, str_repeat('2', 40), 'main', 'github');
        $successful = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('3', 40),
            'finished_at' => now(),
        ]);
        $rollback = app(RollbackService::class)->rollbackTo($successful, 'admin@example.test');

        foreach ([$manual, $webhook, $rollback] as $deployment) {
            $this->assertSame($config['url'], $deployment->http_health_config['url']);
            $this->assertSame(Deployment::HTTP_HEALTH_PENDING, $deployment->http_health_status);
        }
    }

    public function test_old_server_agent_is_blocked_before_executing_required_http_health(): void
    {
        $deployment = $this->queuedHealthDeployment();
        Agent::factory()->approved()->create([
            'server_id' => $deployment->server_id,
            'agent_version' => '1.2.0',
        ]);

        $this->assertNull(app(AgentJobService::class)->claimNextJob($deployment->server));
        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $deployment->failure_code);
        $this->assertStringContainsString('1.3.0', $deployment->failure_message);
    }

    private function queuedHealthDeployment(): Deployment
    {
        return Deployment::factory()->create([
            'status' => Deployment::STATUS_QUEUED,
            'http_health_config' => $this->healthConfig(),
            'http_health_status' => Deployment::HTTP_HEALTH_PENDING,
        ]);
    }

    private function recordHealth(Deployment $deployment, int $exitCode, string $output, bool $timedOut = false): void
    {
        DeploymentLogEntry::create([
            'deployment_id' => $deployment->id,
            'sequence' => 10,
            'step_name' => 'customer-facing HTTP health',
            'step_type' => 'http_health_check',
            'command' => 'GET https://customer.example/',
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => 1200,
            'timed_out' => $timedOut,
            'status' => $exitCode === 0 ? 'succeeded' : 'failed',
            'failure_code' => $exitCode === 0 ? null : Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED,
        ]);
    }

    /** @return array<string, mixed> */
    private function healthConfig(): array
    {
        return [
            'enabled' => true,
            'base_url' => 'https://customer.example',
            'path' => '/',
            'url' => 'https://customer.example/',
            'expected_status' => 200,
            'timeout_seconds' => 15,
            'retries' => 3,
            'retry_interval_seconds' => 5,
            'allowed_redirects' => false,
            'expected_content' => null,
        ];
    }
}
