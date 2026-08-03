<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\PublishJob;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry): a publish now only succeeds once
 * the full local build + artifact pipeline has, so the report() endpoint
 * must accept every new PublishJob::AGENT_REPORTABLE_FAILURE_CODES value
 * — see local-agent's LocalBuildRunner/ArtifactBuilder/ArtifactUploader
 * for what reports each one.
 */
class PublishJobArtifactFailureCodeTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    public function test_report_accepts_every_agent_reportable_failure_code(): void
    {
        foreach (PublishJob::AGENT_REPORTABLE_FAILURE_CODES as $failureCode) {
            $agent = LocalAgent::factory()->approved()->create();
            $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'action' => PublishJob::ACTION_PUBLISH, 'status' => PublishJob::STATUS_RUNNING]);

            $body = ['status' => 'failed', 'error_message' => 'the local build pipeline failed', 'failure_code' => $failureCode];
            $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
            $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

            // process_timeout is the one pre-existing code that maps to
            // a distinct `timed_out` status rather than plain `failed` —
            // see PublishJobService::reportFailure(); every new code
            // added for the local build/artifact pipeline maps to `failed`.
            $expectedStatus = $failureCode === PublishJob::FAILURE_CODE_PROCESS_TIMEOUT ? PublishJob::STATUS_TIMED_OUT : PublishJob::STATUS_FAILED;
            $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => $expectedStatus]);

            $this->assertSame($failureCode, $job->fresh()->failure_code, "failure_code '{$failureCode}' was not accepted/stored correctly.");
        }
    }

    public function test_report_rejects_an_unknown_failure_code(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $job = PublishJob::factory()->create(['local_agent_id' => $agent->id, 'action' => PublishJob::ACTION_PUBLISH, 'status' => PublishJob::STATUS_RUNNING]);

        $body = ['status' => 'failed', 'error_message' => 'x', 'failure_code' => 'not_a_real_failure_code'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertStatus(422);
    }
}
