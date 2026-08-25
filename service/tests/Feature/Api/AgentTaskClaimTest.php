<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Security\JobPayloadSigner;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * End-to-end new-domain flow through the *same* /api/agent/jobs/*
 * endpoints legacy agents use — Task 5 + Task 10 (backward compatibility
 * verification for the new flow specifically; the legacy flow's own
 * verification lives in the pre-existing AgentJobFlowTest, which this
 * phase does not modify).
 */
class AgentTaskClaimTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function makeClaimableTask(): array
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => (string) config('deployment.min_server_agent_version_for_artifact')]);
        $project = Project::factory()->create(['pipeline' => [
            ['name' => 'echo', 'command' => 'echo hello'],
        ]]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'name' => 'web-01',
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $target->server_id,
            'status' => Deployment::STATUS_QUEUED,
        ]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_QUEUED,
        ]);

        return compact('agent', 'project', 'environment', 'target', 'deployment', 'task');
    }

    public function test_agent_claims_a_new_domain_task_via_the_same_next_endpoint(): void
    {
        $ctx = $this->makeClaimableTask();
        $agent = $ctx['agent'];

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.project_key', $ctx['project']->project_key);
        $this->assertSame(DeploymentTask::STATUS_RUNNING, $ctx['task']->fresh()->status);
        $this->assertCount(1, $ctx['task']->fresh()->agentJobs);

        // The job payload signature must verify against the AGENT's own
        // signing_secret, exactly like a legacy job does against the
        // server's.
        $job = $response->json('job');
        $signer = app(JobPayloadSigner::class);
        $this->assertTrue(hash_equals($signer->sign($job, AgentFactory::$lastPlainSecret), $response->json('signature')));
    }

    public function test_agent_task_payload_carries_the_exact_attached_artifact(): void
    {
        $ctx = $this->makeClaimableTask();
        $agent = $ctx['agent'];
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $ctx['project']->id,
            'commit_hash' => $ctx['deployment']->commit_hash,
            'required_paths' => ['artisan', 'vendor/autoload.php'],
        ]);
        $ctx['deployment']->update(['artifact_id' => $artifact->id]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.artifact.public_id', $artifact->public_id);
        $response->assertJsonPath('job.artifact.checksum_sha256', $artifact->checksum_sha256);
        $response->assertJsonPath('job.artifact.size_bytes', $artifact->size_bytes);
        $response->assertJsonPath('job.artifact.required_paths', ['artisan', 'vendor/autoload.php']);
    }

    public function test_legacy_job_is_preferred_over_a_new_domain_task_on_the_same_poll(): void
    {
        $ctx = $this->makeClaimableTask();
        $agent = $ctx['agent'];

        // A legacy-shaped Deployment also queued directly against the same server.
        $legacyProject = Project::factory()->create(['server_id' => $agent->server_id]);
        Deployment::create([
            'project_id' => $legacyProject->id,
            'server_id' => $agent->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/jobs/next', $headers)->assertOk();

        $this->assertSame($legacyProject->project_key, $response->json('job.project_key'));
        // The new-domain task must still be untouched — this poll went to
        // the legacy job, not silently skipping it.
        $this->assertSame(DeploymentTask::STATUS_QUEUED, $ctx['task']->fresh()->status);
    }

    public function test_status_update_propagates_to_task_and_recalculates_deployment(): void
    {
        $ctx = $this->makeClaimableTask();
        $agent = $ctx['agent'];

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $job = $this->get('/api/agent/jobs/next', $headers)->json('job');

        $statusBody = ['status' => 'succeeded'];
        $statusPath = "/api/agent/jobs/{$job['id']}/status";
        $statusHeaders = $this->agentHeaders('POST', $statusPath, json_encode($statusBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $this->postJson($statusPath, $statusBody, $statusHeaders)->assertOk()->assertJson(['status' => 'succeeded']);

        $this->assertSame(DeploymentTask::STATUS_SUCCEEDED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::STATUS_SUCCEEDED, $ctx['deployment']->fresh()->status);
    }

    public function test_log_entries_are_recorded_and_redacted(): void
    {
        $ctx = $this->makeClaimableTask();
        $agent = $ctx['agent'];

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $job = $this->get('/api/agent/jobs/next', $headers)->json('job');

        $logBody = [
            'sequence' => 1,
            'step_name' => 'echo',
            'command' => 'export API_KEY=sk_live_leak123',
            'output' => 'hello',
            'exit_code' => 0,
        ];
        $logPath = "/api/agent/jobs/{$job['id']}/log";
        $logHeaders = $this->agentHeaders('POST', $logPath, json_encode($logBody), $agent->public_id, AgentFactory::$lastPlainToken, AgentFactory::$lastPlainSecret);
        $this->postJson($logPath, $logBody, $logHeaders)->assertCreated();

        $agentJob = AgentJob::where('public_id', $job['id'])->firstOrFail();
        $this->assertCount(1, $agentJob->logEntries);
        $this->assertStringNotContainsString('sk_live_leak123', $agentJob->logEntries->first()->command);
    }

    public function test_agent_cannot_report_on_a_task_belonging_to_another_server(): void
    {
        $ctx = $this->makeClaimableTask();
        // Capture this agent's credentials before creating another one —
        // AgentFactory::$lastPlainToken/$lastPlainSecret are overwritten
        // by the next factory call.
        $token = AgentFactory::$lastPlainToken;
        $secret = AgentFactory::$lastPlainSecret;

        $otherAgent = Agent::factory()->approved()->create();
        $otherToken = AgentFactory::$lastPlainToken;
        $otherSecret = AgentFactory::$lastPlainSecret;

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $ctx['agent']->public_id, $token, $secret);
        $job = $this->get('/api/agent/jobs/next', $headers)->json('job');

        $statusBody = ['status' => 'succeeded'];
        $statusPath = "/api/agent/jobs/{$job['id']}/status";
        $otherHeaders = $this->agentHeaders('POST', $statusPath, json_encode($statusBody), $otherAgent->public_id, $otherToken, $otherSecret);

        $this->postJson($statusPath, $statusBody, $otherHeaders)->assertStatus(403);
    }

    public function test_legacy_server_credential_never_claims_a_new_domain_task_and_surfaces_the_protocol_mismatch(): void
    {
        // A bare legacy Server (no Agent row at all) whose server_id
        // happens to have an eligible new-domain task queued against it
        // must still see nothing when it polls — new-domain claiming is
        // only ever attempted when VerifyAgentRequestSignature resolved
        // an Agent, per AgentJobController::next().
        $ctx = $this->makeClaimableTask();

        $legacyServer = \App\Models\Server::factory()->create();
        $legacyToken = \Database\Factories\ServerFactory::$lastPlainToken;
        $legacySecret = \Database\Factories\ServerFactory::$lastPlainSecret;

        // Re-point the existing task's target at this bare legacy server
        // so it would otherwise be "claimable" by server_id alone.
        $ctx['target']->update(['server_id' => $legacyServer->id]);

        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $legacyServer->public_id, $legacyToken, $legacySecret);
        $this->get('/api/agent/jobs/next', $headers)->assertOk()->assertJson(['job' => null]);

        $this->assertSame(DeploymentTask::STATUS_FAILED, $ctx['task']->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $ctx['deployment']->fresh()->failure_code);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment_task.rejected_legacy_agent_protocol',
            'subject_id' => $ctx['task']->id,
        ]);
    }
}
