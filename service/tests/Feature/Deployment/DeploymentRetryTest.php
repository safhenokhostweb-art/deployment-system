<?php

namespace Tests\Feature\Deployment;

use App\Models\Agent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\RetryDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deployment-level retry (root-cause fix: agent protocol compatibility).
 *
 * Production incident #110: an artifact-based deployment was failed at
 * claim time with agent_protocol_incompatible because the Server Agent
 * was 1.1.0. After the agent was upgraded to 1.2.0 there was no UI path
 * to re-queue it - the only option was manual DB editing. These tests
 * pin the permanent behavior: retry re-validates the agent, re-queues
 * the exact failed work, preserves full history, and fails closed with a
 * clear actionable message when the agent is STILL too old.
 */
class DeploymentRetryTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_AGENT = '1.2.0';
    private const OLD_AGENT = '1.1.0';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function retryService(): RetryDeploymentService
    {
        return app(RetryDeploymentService::class);
    }

    /** Legacy-shaped deployment (no DeploymentTask rows) on a project whose agent is current. */
    private function makeLegacyFailedDeployment(array $overrides = []): array
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => self::CURRENT_AGENT]);
        $project = Project::factory()->create(['requires_complete_artifact' => false]);
        $deployment = Deployment::factory()->create(array_merge([
            'project_id' => $project->id,
            'server_id' => $agent->server_id,
            'status' => Deployment::STATUS_FAILED,
            'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
            'failure_message' => 'The server agent (version 1.1.0) is too old to enforce the Laravel release-completeness gate.',
            'finished_at' => now(),
            'commit_hash' => str_repeat('a', 40),
        ], $overrides));

        return compact('agent', 'project', 'deployment');
    }

    /** Multi-target shaped deployment: one parent Deployment + one failed DeploymentTask. */
    private function makeMultiTargetFailedDeployment(array $overrides = []): array
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => self::CURRENT_AGENT]);
        $project = Project::factory()->create(['requires_complete_artifact' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'name' => 'web-01',
        ]);
        $deployment = Deployment::factory()->create(array_merge([
            'project_id' => $project->id,
            'server_id' => $target->server_id,
            'status' => Deployment::STATUS_FAILED,
            'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
            'failure_message' => 'The server agent (version 1.1.0) is too old to enforce the Laravel release-completeness gate.',
            'finished_at' => now(),
            'commit_hash' => str_repeat('a', 40),
        ], $overrides));
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_FAILED,
        ]);

        return compact('agent', 'project', 'deployment', 'task', 'target');
    }

    public function test_legacy_failed_deployment_is_requeued_and_history_preserved(): void
    {
        $ctx = $this->makeLegacyFailedDeployment();
        $deployment = $ctx['deployment'];

        $result = $this->retryService()->retry($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $result->status);
        $this->assertNull($result->failure_code);
        $this->assertNull($result->failure_message);
        $this->assertNull($result->finished_at);
        $this->assertNotNull($result->queued_at);

        // History preserved: the deployment row is the SAME row, not a clone.
        $this->assertSame($deployment->id, $result->id);
        $this->assertSame($deployment->public_id, $result->public_id);

        $audit = \App\Models\AuditLogEntry::where('action', 'deployment.retried')
            ->where('subject_id', $deployment->id)->first();
        $this->assertNotNull($audit);
    }

    public function test_multi_target_retry_requeues_only_failed_task(): void
    {
        $ctx = $this->makeMultiTargetFailedDeployment();
        $deployment = $ctx['deployment'];
        $task = $ctx['task'];

        $result = $this->retryService()->retry($deployment, 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $result->status);
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $task->fresh()->status);
        $this->assertNull($task->fresh()->next_retry_at);
        $this->assertNull($result->failure_code);
        $this->assertNull($result->finished_at);
    }

    public function test_retry_preserves_succeeded_sibling_tasks(): void
    {
        $ctx = $this->makeMultiTargetFailedDeployment();
        // Second task already succeeded - retry must NEVER touch it.
        $succeededTarget = \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $ctx['target']->environment_id,
            'server_id' => $ctx['agent']->server_id,
            'name' => 'web-02',
        ]);
        $succeededTask = DeploymentTask::create([
            'deployment_id' => $ctx['deployment']->id,
            'deployment_target_id' => $succeededTarget->id,
            'status' => DeploymentTask::STATUS_SUCCEEDED,
        ]);
        $ctx['deployment']->update(['status' => Deployment::STATUS_PARTIAL_SUCCESS]);

        $result = $this->retryService()->retry($ctx['deployment'], 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $result->status);
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $ctx['task']->fresh()->status);
        $this->assertSame(DeploymentTask::STATUS_SUCCEEDED, $succeededTask->fresh()->status);
    }

    public function test_retry_fails_closed_when_agent_is_still_too_old(): void
    {
        $ctx = $this->makeLegacyFailedDeployment();
        $ctx['agent']->update(['agent_version' => self::OLD_AGENT]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $ctx['deployment']->commit_hash,
        ]);
        $ctx['deployment']->update(['artifact_id' => $artifact->id]);

        try {
            $this->retryService()->retry($ctx['deployment'], 'admin@example.test');
            $this->fail('Expected 409 when agent is still too old.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fail('Unexpected ValidationException.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('too old', $e->getMessage());
            $this->assertStringContainsString(self::OLD_AGENT, $e->getMessage());
        }

        // Deployment stays failed - retry never half-requeues before the gate passes.
        $this->assertSame(Deployment::STATUS_FAILED, $ctx['deployment']->fresh()->status);
    }

    public function test_retry_succeeds_after_agent_upgrade_for_artifact_deployment(): void
    {
        // Exact #110 timeline: agent was 1.1.0 at failure, now reports 1.2.0.
        $ctx = $this->makeLegacyFailedDeployment();
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $ctx['deployment']->commit_hash,
        ]);
        $ctx['deployment']->update(['artifact_id' => $artifact->id]);

        $result = $this->retryService()->retry($ctx['deployment'], 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $result->status);
        $this->assertSame($artifact->id, $result->artifact_id);
    }

    public function test_http_health_retry_requires_capable_agent_and_resets_health_state(): void
    {
        $ctx = $this->makeLegacyFailedDeployment([
            'http_health_config' => ['enabled' => true, 'url' => 'https://customer.example/'],
            'http_health_status' => Deployment::HTTP_HEALTH_FAILED,
            'http_health_details' => ['output' => 'HTTP 500'],
            'execution_status' => Deployment::EXECUTION_SUCCEEDED,
            'execution_finished_at' => now(),
        ]);

        try {
            $this->retryService()->retry($ctx['deployment'], 'admin@example.test');
            $this->fail('Expected HTTP health retry to reject Agent 1.2.0.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('1.3.0', $exception->getMessage());
        }

        $ctx['agent']->update(['agent_version' => '1.3.0']);
        $retried = $this->retryService()->retry($ctx['deployment']->fresh(), 'admin@example.test');
        $this->assertSame(Deployment::STATUS_QUEUED, $retried->status);
        $this->assertSame(Deployment::EXECUTION_PENDING, $retried->execution_status);
        $this->assertSame(Deployment::HTTP_HEALTH_PENDING, $retried->http_health_status);
        $this->assertNull($retried->http_health_details);
        $this->assertNull($retried->http_health_verified_at);
    }

    public function test_retry_rejects_non_retryable_terminal_states(): void
    {
        foreach ([Deployment::STATUS_SUCCEEDED, Deployment::STATUS_CANCELLED, Deployment::STATUS_ROLLED_BACK] as $status) {
            $ctx = $this->makeLegacyFailedDeployment(['status' => $status]);
            try {
                $this->retryService()->retry($ctx['deployment'], 'admin@example.test');
                $this->fail("Expected 409 for status {$status}.");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
    }

    public function test_retry_unknown_agent_version_fails_closed(): void
    {
        $ctx = $this->makeLegacyFailedDeployment();
        // No agent row at all on the server -> version unknown -> incompatible.
        $ctx['agent']->delete();
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $ctx['deployment']->commit_hash,
        ]);
        $ctx['deployment']->update(['artifact_id' => $artifact->id]);

        try {
            $this->retryService()->retry($ctx['deployment'], 'admin@example.test');
            $this->fail('Expected 409 for unknown agent version.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('unknown', $e->getMessage());
        }
    }

    public function test_retry_task_requeues_single_failed_task(): void
    {
        $ctx = $this->makeMultiTargetFailedDeployment();

        $result = $this->retryService()->retryTask($ctx['task'], 'admin@example.test');

        $this->assertSame(Deployment::STATUS_QUEUED, $result->status);
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $ctx['task']->fresh()->status);

        $audit = \App\Models\AuditLogEntry::where('action', 'deployment_task.retried')
            ->where('subject_id', $ctx['task']->id)->first();
        $this->assertNotNull($audit);
    }

    public function test_retry_task_rejects_succeeded_task(): void
    {
        $ctx = $this->makeMultiTargetFailedDeployment();
        $ctx['task']->update(['status' => DeploymentTask::STATUS_SUCCEEDED]);

        try {
            $this->retryService()->retryTask($ctx['task'], 'admin@example.test');
            $this->fail('Expected 409 for a succeeded task.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_failed_agent_protocol_deployment_is_retryable(): void
    {
        $ctx = $this->makeLegacyFailedDeployment();
        $this->assertTrue($ctx['deployment']->isRetryable());
        $this->assertTrue($ctx['deployment']->isTerminal());
    }

    public function test_succeeded_deployment_is_not_retryable(): void
    {
        $deployment = Deployment::factory()->create(['status' => Deployment::STATUS_SUCCEEDED]);
        $this->assertFalse($deployment->isRetryable());
    }

    /** End-to-end: retry makes the deployment claimable by the (now-current) agent again. */
    public function test_retried_deployment_can_be_claimed_again(): void
    {
        $ctx = $this->makeMultiTargetFailedDeployment();
        $this->retryService()->retry($ctx['deployment'], 'admin@example.test');

        $claimed = app(\App\Services\Deployment\AgentTaskService::class)->claimNextTask($ctx['agent']);

        $this->assertNotNull($claimed);
        $this->assertSame(DeploymentTask::STATUS_RUNNING, $ctx['task']->fresh()->status);
    }
}
