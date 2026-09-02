<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Services\Publish\LocalAgentControlJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.3: LocalAgentControlJobService's queue/claim/report lifecycle
 * — mirrors OperationJobServiceTest's style (there is none dedicated;
 * this follows PublishJobServiceTest's precedent instead).
 */
class LocalAgentControlJobServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_rejects_an_unknown_action(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(LocalAgentControlJobService::class)->queue($agent, 'not-a-real-action', 'admin@example.test');
    }

    public function test_queue_creates_a_queued_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $job->status);
        $this->assertSame($agent->id, $job->local_agent_id);
        $this->assertSame('admin@example.test', $job->requested_by);
    }

    public function test_queue_accepts_the_reload_config_action(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $job = app(LocalAgentControlJobService::class)->queue($agent, LocalAgentControlJob::ACTION_RELOAD_CONFIG, 'admin@example.test');

        $this->assertSame(LocalAgentControlJob::ACTION_RELOAD_CONFIG, $job->action);
        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $job->status);
    }

    public function test_claim_next_returns_the_oldest_queued_job_for_that_agent_only(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $otherAgent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);

        $service->queue($otherAgent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');
        $first = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');
        $service->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');

        $claimed = $service->claimNext($agent);

        $this->assertSame($first->id, $claimed->id);
        $this->assertSame(LocalAgentControlJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_claim_next_returns_null_when_nothing_is_queued(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $this->assertNull(app(LocalAgentControlJobService::class)->claimNext($agent));
    }

    public function test_report_result_marks_the_job_succeeded(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');
        $service->claimNext($agent);

        $updated = $service->reportResult($job, 'log line 1\nlog line 2');

        $this->assertSame(LocalAgentControlJob::STATUS_SUCCEEDED, $updated->status);
        $this->assertStringContainsString('log line 1', $updated->output);
    }

    public function test_report_failure_marks_the_job_failed(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_RESTART, 'admin@example.test');
        $service->claimNext($agent);

        $updated = $service->reportFailure($job, 'agent could not restart itself');

        $this->assertSame(LocalAgentControlJob::STATUS_FAILED, $updated->status);
        $this->assertSame('agent could not restart itself', $updated->error_message);
    }

    public function test_report_result_redacts_secrets_in_output(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queue($agent, LocalAgentControlJob::ACTION_FETCH_LOGS, 'admin@example.test');

        $updated = $service->reportResult($job, 'token=ghp_1234567890abcdef1234567890abcdef1234');

        $this->assertStringNotContainsString('ghp_1234567890abcdef1234567890abcdef1234', $updated->output);
        $this->assertStringContainsString('[REDACTED]', $updated->output);
    }

    public function test_linux_upgrade_waits_for_the_expected_version_heartbeat(): void
    {
        $target = $this->createUpgradeAuthority();
        // Deliberately stale/wrong on purpose: this must NOT drive
        // expected_version or upgrade eligibility any more (see
        // test_stale_latest_version_config_cannot_block_the_bootstrap_upgrade
        // below for the exact production incident this proves fixed).
        config(['deployment.latest_local_agent_version' => '1.5.2']);
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.2',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);
        $service = app(LocalAgentControlJobService::class);

        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $this->assertSame('1.5.3', $job->expected_version);
        $service->claimNext($agent);
        $job->refresh();
        $activated = $service->reportUpgradeStarted($job, 'validated', $target['commit'], str_repeat('b', 40), 'deployment-system');

        $this->assertSame(LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT, $activated->status);
        $this->assertSame(0, $service->verifyUpgradeHeartbeat($agent, '1.5.2'));
        $this->assertSame(LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT, $job->fresh()->status);
        $this->assertSame(1, $service->verifyUpgradeHeartbeat($agent, '1.5.3'));
        $this->assertSame(LocalAgentControlJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame('1.5.3', $job->fresh()->reported_version);
        $this->assertNotNull($job->fresh()->upgrade_verified_at);
    }

    /**
     * Reproduces the exact production release-orchestration deadlock:
     * Deployment #177 shipped Hub 5d8041e (canonical Agent 1.5.3), but
     * production's persistent .env still held
     * DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=1.5.2 — so
     * config('deployment.latest_local_agent_version') kept reporting
     * 1.5.2 even though the Hub's own shipped Agent was 1.5.3. Under the
     * old design, queueUpgrade() used exactly that config value both as
     * the "is this Agent already current" gate and as expected_version —
     * so the stale override made the Hub refuse to queue ANY upgrade at
     * all ("This Local Agent is already at the latest version."),
     * permanently trapping the installed 1.5.2 Agent below the version
     * min_local_agent_version_for_assigned_artifact requires, with no
     * path to build the very Hub release (27e8c78) that would have fixed
     * the stale config in the first place. Eligibility and
     * expected_version now come from the exact-commit upgrade target's
     * own shipped version instead, so this stale config can never again
     * block the bootstrap upgrade off of an old Agent.
     */
    public function test_stale_latest_version_config_cannot_block_the_bootstrap_upgrade(): void
    {
        $this->createUpgradeAuthority();
        config(['deployment.latest_local_agent_version' => '1.5.2']);
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.2',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);

        $job = app(LocalAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');

        $this->assertSame(LocalAgentControlJob::ACTION_UPGRADE, $job->action);
        $this->assertSame('1.5.3', $job->expected_version, 'expected_version must come from the upgrade target itself, not the stale latest_local_agent_version override.');
    }

    public function test_1_5_1_agent_requires_the_one_time_exact_commit_bootstrap(): void
    {
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.1',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('SERVER BOOTSTRAP REQUIRED');
        app(LocalAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
    }

    public function test_exact_commit_upgrade_rejects_same_version_from_the_wrong_commit(): void
    {
        config(['deployment.latest_local_agent_version' => '1.5.3']);
        $target = $this->createUpgradeAuthority();
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.2',
            'capabilities' => ['linux_self_upgrade' => true],
        ]);
        $service = app(LocalAgentControlJobService::class);
        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $service->claimNext($agent);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->reportUpgradeStarted($job->fresh(), 'validated', str_repeat('f', 40), str_repeat('b', 40), 'deployment-system');
    }

    public function test_remote_upgrade_rejects_an_agent_without_bootstrap_capability(): void
    {
        config(['deployment.latest_local_agent_version' => '1.4.4']);
        $agent = LocalAgent::factory()->approved()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.4.3',
            'capabilities' => ['linux_self_upgrade' => false],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(LocalAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
    }

    /** @return array{commit:string} */
    private function createUpgradeAuthority(): array
    {
        $commit = str_repeat('a', 40);
        $project = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'master']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'master',
            'finished_at' => now(),
        ]);

        return ['commit' => $commit];
    }
}
