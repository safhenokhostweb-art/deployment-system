<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

class AgentCredentialReissueTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_guest_and_non_admin_cannot_reissue_agent_credentials(): void
    {
        $agent = Agent::factory()->approved()->create();
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);

        $this->post(route('agents.reissue-credentials', $agent))->assertRedirect(route('login'));
        $this->actingAs($developer)->post(route('agents.reissue-credentials', $agent))->assertForbidden();
    }

    public function test_reissue_rotates_only_the_existing_agent_and_invalidates_the_previous_credentials(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();
        $oldToken = AgentFactory::$lastPlainToken;
        $oldSecret = AgentFactory::$lastPlainSecret;
        $originalId = $agent->id;
        $originalPublicId = $agent->public_id;
        $originalHash = $agent->agent_token_hash;

        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'agent_id' => $agent->id,
        ]);
        $deployment = Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $agent->server_id]);

        $response = $this->actingAs($admin)->post(route('agents.reissue-credentials', $agent));
        $response->assertRedirect(route('agents.show', $agent));
        $response->assertSessionHasAll(['generated_token', 'generated_secret', 'generated_agent_identity' => $originalPublicId]);
        $newToken = $response->getSession()->get('generated_token');
        $newSecret = $response->getSession()->get('generated_secret');

        $fresh = $agent->fresh();
        $this->assertSame($originalId, $fresh->id);
        $this->assertSame($originalPublicId, $fresh->public_id);
        $this->assertSame(Agent::STATUS_APPROVED, $fresh->status);
        $this->assertNotSame($originalHash, $fresh->agent_token_hash);
        $this->assertNotSame($oldSecret, $fresh->signing_secret);
        $this->assertSame(1, Agent::count());
        $this->assertSame($agent->id, $target->fresh()->agent_id);
        $this->assertDatabaseHas('deployments', ['id' => $deployment->id]);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'agent.credentials_reissued',
            'subject_type' => Agent::class,
            'subject_id' => $agent->id,
        ]);
        $this->assertSame(['server_id' => $agent->server_id], AuditLogEntry::latest('id')->firstOrFail()->metadata);

        $body = ['agent_version' => '2.0.0'];
        $oldHeaders = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $originalPublicId, $oldToken, $oldSecret);
        $this->postJson('/api/agent/heartbeat', $body, $oldHeaders)->assertUnauthorized();

        $newHeaders = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $originalPublicId, $newToken, $newSecret);
        $this->postJson('/api/agent/heartbeat', $body, $newHeaders)->assertOk();
    }

    public function test_repeated_reissue_invalidates_the_immediately_previous_credential(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();

        $first = $this->actingAs($admin)->post(route('agents.reissue-credentials', $agent));
        $firstToken = $first->getSession()->get('generated_token');
        $firstSecret = $first->getSession()->get('generated_secret');
        $second = $this->actingAs($admin)->post(route('agents.reissue-credentials', $agent));
        $secondToken = $second->getSession()->get('generated_token');
        $secondSecret = $second->getSession()->get('generated_secret');

        $body = ['agent_version' => '2.0.0'];
        $oldHeaders = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, $firstToken, $firstSecret);
        $this->postJson('/api/agent/heartbeat', $body, $oldHeaders)->assertUnauthorized();

        $newHeaders = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, $secondToken, $secondSecret);
        $this->postJson('/api/agent/heartbeat', $body, $newHeaders)->assertOk();
        $this->assertSame(2, AuditLogEntry::where('action', 'agent.credentials_reissued')->count());
    }

    public function test_disabled_agent_can_rotate_but_cannot_authenticate_until_reenabled(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->disabled()->create();

        $response = $this->actingAs($admin)->post(route('agents.reissue-credentials', $agent));
        $token = $response->getSession()->get('generated_token');
        $secret = $response->getSession()->get('generated_secret');
        $this->assertSame(Agent::STATUS_DISABLED, $agent->fresh()->status);

        $body = ['agent_version' => '2.0.0'];
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $agent->public_id, $token, $secret);
        $this->postJson('/api/agent/heartbeat', $body, $headers)->assertUnauthorized();
    }

    public function test_reissued_credential_authenticates_the_same_agent_and_claims_a_task(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['pipeline' => [['name' => 'safe', 'command' => 'echo safe']]]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $target = DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'agent_id' => $agent->id,
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $agent->server_id,
            'status' => Deployment::STATUS_QUEUED,
        ]);
        $task = DeploymentTask::create([
            'deployment_id' => $deployment->id,
            'deployment_target_id' => $target->id,
            'status' => DeploymentTask::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($admin)->post(route('agents.reissue-credentials', $agent));
        $token = $response->getSession()->get('generated_token');
        $secret = $response->getSession()->get('generated_secret');
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $agent->public_id, $token, $secret);

        $this->get('/api/agent/jobs/next', $headers)->assertOk()->assertJsonPath('job.commit_hash', $deployment->commit_hash);
        $this->assertSame(DeploymentTask::STATUS_RUNNING, $task->fresh()->status);
        $this->assertDatabaseHas('agent_jobs', ['deployment_task_id' => $task->id, 'agent_id' => $agent->id]);
    }
}
