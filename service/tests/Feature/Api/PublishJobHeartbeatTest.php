<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\PublishJob;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix: the Job-heartbeat channel — distinct from
 * /api/local-agent/heartbeat (the agent's general online signal, see
 * LocalAgentControlJobFlowTest) — renews a running PublishJob's lease so
 * a slow-but-alive `git add`/`push` is never mistaken for an abandoned
 * one by deployments:recover-stale.
 */
class PublishJobHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    public function test_agent_renews_the_lease_of_its_own_running_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'status' => PublishJob::STATUS_RUNNING,
            'lease_expires_at' => now()->addSeconds(10),
        ]);

        $path = "/api/local-agent/publish-jobs/{$job->public_id}/heartbeat";
        $headers = $this->headersFor($agent, 'POST', $path);

        $response = $this->post($path, [], $headers)->assertOk();
        $response->assertJsonPath('status', PublishJob::STATUS_RUNNING);

        $this->assertTrue($job->fresh()->lease_expires_at->isAfter(now()->addSeconds(60)));
    }

    public function test_agent_cannot_heartbeat_another_agents_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        // Captured immediately — creating $otherAgent below overwrites the
        // factory's shared static plain-credential fields.
        $agentToken = LocalAgentFactory::$lastPlainToken;
        $agentSecret = LocalAgentFactory::$lastPlainSecret;
        $otherAgent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $otherAgent->id, 'status' => PublishJob::STATUS_RUNNING]);

        $path = "/api/local-agent/publish-jobs/{$job->public_id}/heartbeat";
        $headers = $this->localAgentHeaders('POST', $path, '', $agent->public_id, $agentToken, $agentSecret);

        $this->post($path, [], $headers)->assertStatus(403);
    }

    public function test_heartbeat_for_an_already_terminal_job_is_a_no_op_not_an_error(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'status' => PublishJob::STATUS_ABANDONED]);

        $path = "/api/local-agent/publish-jobs/{$job->public_id}/heartbeat";
        $headers = $this->headersFor($agent, 'POST', $path);

        $response = $this->post($path, [], $headers)->assertOk();
        $response->assertJsonPath('status', PublishJob::STATUS_ABANDONED);
    }
}
