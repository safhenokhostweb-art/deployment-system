<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Operations\OperationJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * A real Ubuntu 24.04 install reported "Poll returned HTTP 500" and
 * "Operation poll returned HTTP 500" for the exact credentials a
 * one-click-installer config.php embeds (Server-level agent_token/
 * signing_secret, no separate Agent identity — the installer never runs
 * the Agent registration/approval flow, by design, see
 * docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md §6). This local Hub instance's
 * own storage/logs/laravel.log has no entry anywhere near the reported
 * incident timestamp, so the request that actually 500'd did not reach
 * this codebase/instance — there is no real stack trace to read from
 * here, and this suite cannot reach whatever Hub deployment the real
 * Ubuntu box actually talked to. What IS verified: this exact code, in
 * this environment, does not 500 for that credential/request shape
 * (§below), and any genuinely uncaught exception on an agent route now
 * renders as safe JSON instead of depending on APP_DEBUG (see
 * bootstrap/app.php).
 */
class OneClickInstallerPollIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    /**
     * Registers a real Server exactly the way the Server Setup Wizard
     * does, and returns the same plaintext credentials
     * buildOneClickInstallerContent() would have embedded in
     * install-agent.sh for it.
     *
     * @return array{server: Server, token: string, secret: string}
     */
    private function registerServerLikeTheInstallerWould(string $name): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => $name]);
        $server = Server::where('name', $name)->firstOrFail();

        return [
            'server' => $server,
            'token' => (string) session('generated_token'),
            'secret' => (string) session('generated_secret'),
        ];
    }

    public function test_installer_generated_credentials_poll_both_endpoints_without_a_500(): void
    {
        ['server' => $server, 'token' => $token, 'secret' => $secret] = $this->registerServerLikeTheInstallerWould('Poll Test Server');

        $heartbeatHeaders = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode(['agent_version' => '1.1.0']), $server->public_id, $token, $secret);
        $this->postJson('/api/agent/heartbeat', ['agent_version' => '1.1.0'], $heartbeatHeaders)->assertOk();

        $jobsHeaders = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/jobs/next', $jobsHeaders)->assertOk()->assertJson(['job' => null]);

        $operationHeaders = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/operation-jobs/next', $operationHeaders)->assertOk()->assertJson(['job' => null]);
    }

    /**
     * Mirrors the real "ParsVDS" server's actual shape as closely as this
     * suite can: a Server with one real, active Project attached and zero
     * deployments — the exact state a freshly one-click-installed Agent
     * polls into for the first time.
     */
    public function test_installer_generated_credentials_poll_correctly_with_a_real_project_attached(): void
    {
        ['server' => $server, 'token' => $token, 'secret' => $secret] = $this->registerServerLikeTheInstallerWould('Poll Test Server With Project');
        Project::factory()->create(['server_id' => $server->id, 'project_key' => 'real-project', 'is_active' => true]);

        $jobsHeaders = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/jobs/next', $jobsHeaders)->assertOk()->assertJson(['job' => null]);

        $operationHeaders = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $server->public_id, $token, $secret);
        $this->get('/api/agent/operation-jobs/next', $operationHeaders)->assertOk()->assertJson(['job' => null]);
    }

    /**
     * Forces a genuine uncaught exception inside an agent route (the
     * class of failure the real Ubuntu report actually hit) and proves
     * the response is safe, structured JSON — not Laravel's raw debug
     * page. APP_DEBUG=true in this suite's .env, which is the harder,
     * more meaningful case: it proves the override in bootstrap/app.php
     * actually suppresses debug detail, not just that debug happened to
     * be off.
     */
    public function test_an_uncaught_exception_on_an_agent_route_renders_safe_json_not_debug_output(): void
    {
        $this->assertTrue(config('app.debug'), 'This test is only meaningful when APP_DEBUG is true.');

        $agent = Agent::factory()->approved()->create();
        $this->mock(OperationJobService::class, function ($mock) {
            $mock->shouldReceive('claimNext')
                ->andThrow(new \RuntimeException('Simulated failure — must never reach the wire: sec_SHOULD_NOT_LEAK'));
        });

        $headers = $this->agentHeaders('GET', '/api/agent/operation-jobs/next', '', $agent->public_id, \Database\Factories\AgentFactory::$lastPlainToken, \Database\Factories\AgentFactory::$lastPlainSecret);
        $response = $this->get('/api/agent/operation-jobs/next', $headers);

        $response->assertStatus(500);
        $response->assertExactJson([
            'error' => 'agent_request_failed',
            'message' => 'The Deployment Service could not process this request. See the Hub application log for details.',
        ]);
        $response->assertDontSee('sec_SHOULD_NOT_LEAK');
        $response->assertDontSee('RuntimeException');
    }

    /**
     * The intentional-rejection path (VerifyAgentRequestSignature::reject())
     * must be completely unaffected by the new safe-JSON render() hook —
     * it already returns a deliberate, safe 401 body.
     */
    public function test_an_invalid_signature_still_returns_the_existing_401_shape(): void
    {
        $server = Server::factory()->create();
        $headers = $this->agentHeaders('GET', '/api/agent/jobs/next', '', $server->public_id, \Database\Factories\ServerFactory::$lastPlainToken, \Database\Factories\ServerFactory::$lastPlainSecret);
        $headers['X-Deployment-Signature'] = 'tampered';

        $this->get('/api/agent/jobs/next', $headers)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized']);
    }

    /**
     * Regression test for a real bug this exact fix introduced and caught
     * via the full suite: ValidationException (thrown by
     * $request->validate(), e.g. heartbeat()'s 'agent_version' rule) does
     * NOT implement HttpExceptionInterface, so the first version of the
     * new render() callback caught it too and replaced Laravel's correct
     * 422 with a misleading 500. Both exception types must be excluded.
     */
    public function test_a_validation_failure_on_an_agent_route_still_returns_422_not_500(): void
    {
        $server = Server::factory()->create();
        $token = \Database\Factories\ServerFactory::$lastPlainToken;
        $secret = \Database\Factories\ServerFactory::$lastPlainSecret;

        $body = []; // missing required 'agent_version'
        $headers = $this->agentHeaders('POST', '/api/agent/heartbeat', json_encode($body), $server->public_id, $token, $secret);

        $response = $this->postJson('/api/agent/heartbeat', $body, $headers);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('agent_version');
    }
}
