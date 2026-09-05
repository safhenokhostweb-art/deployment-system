<?php

namespace Tests\Unit\Services\Publish;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServerAgentControlJob;
use App\Models\ServerAgentPackage;
use App\Models\ServerAgentPackageBuildJob;
use App\Services\Publish\ServerAgentControlJobService;
use App\Services\Publish\ServerAgentPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mirrors LocalAgentControlJobServiceTest's shape closely (queue -> claim
 * -> report started -> verify heartbeat) but for the independent Server
 * Agent (Agent model) contract — see ServerAgentControlJobService's own
 * docblock for why this is never LocalAgentControlJobService.
 */
class ServerAgentControlJobServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_upgrade_requires_an_approved_agent(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->pendingRegistration()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
    }

    public function test_queue_upgrade_fails_closed_when_no_package_exists_yet(): void
    {
        $this->createUpgradeAuthority();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
        } finally {
            // A build job must have been auto-queued (the default
            // production-build authoritative builder is eligible) so a
            // retry can succeed.
            $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
        }
    }

    // ------------------------------------------------------------------
    // Root-cause fix (commit 9f7adfd shipped the Server Agent
    // package-build poller without a Local Agent version bump, and
    // queueBuildIfMissing() had no eligibility check at all): the
    // authoritative-builder eligibility gate.
    // ------------------------------------------------------------------

    public function test_queue_upgrade_refuses_to_queue_a_package_build_job_for_a_stale_builder_version(): void
    {
        $this->createUpgradeAuthority(['agent_version' => '1.5.4']); // below the new minimum, capability present
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
            $this->fail('Expected queueUpgrade() to fail closed for a stale builder version.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('does not support Server Agent package builds', $e->getMessage());
        }
        $this->assertDatabaseCount('server_agent_package_build_jobs', 0);
    }

    public function test_queue_upgrade_refuses_to_queue_a_package_build_job_for_a_builder_missing_the_capability(): void
    {
        $minimum = (string) config('deployment.min_local_agent_version_for_server_agent_package_build');
        $this->createUpgradeAuthority([
            'agent_version' => $minimum,
            'capabilities' => ['git' => true, 'laravel_release_manifest' => true, 'assigned_artifact_publish_job_id' => true],
        ]);
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
            $this->fail('Expected queueUpgrade() to fail closed for a builder missing the capability.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('does not support Server Agent package builds', $e->getMessage());
        }
        $this->assertDatabaseCount('server_agent_package_build_jobs', 0);
    }

    public function test_queue_upgrade_queues_a_package_build_job_for_a_builder_exactly_at_the_minimum(): void
    {
        $minimum = (string) config('deployment.min_local_agent_version_for_server_agent_package_build');
        $authority = $this->createUpgradeAuthority(['agent_version' => $minimum]);
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
            $this->fail('Expected queueUpgrade() to abort with the "retry shortly" message once a build has been queued.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('a build has been queued', $e->getMessage());
        }

        $job = ServerAgentPackageBuildJob::sole();
        $this->assertSame($authority['commit'], $job->commit_hash);
        $this->assertSame($minimum, $job->builder_agent_version);
    }

    public function test_queue_upgrade_creates_a_queued_job_with_verified_target(): void
    {
        $target = $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        $job = app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');

        $this->assertSame(ServerAgentControlJob::ACTION_UPGRADE, $job->action);
        $this->assertSame(ServerAgentControlJob::STATUS_QUEUED, $job->status);
        $this->assertSame('1.4.2', $job->target_version);
        $this->assertSame($target['commit'], $job->target_commit_sha);
        $this->assertSame($target['package']->id, $job->server_agent_package_id);
        $this->assertSame($target['package']->checksum_sha256, $job->expected_package_hash);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'server_agent.upgrade_requested',
            'subject_type' => ServerAgentControlJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_queue_upgrade_rejects_an_agent_already_at_the_latest_version(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.2']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
    }

    public function test_queue_upgrade_rejects_a_duplicate_active_upgrade(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);
        $service->queueUpgrade($agent, 'admin@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->queueUpgrade($agent, 'admin@example.test');
    }

    public function test_claim_next_returns_the_oldest_queued_job_for_that_agent_only(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $otherAgent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);

        $service->queueUpgrade($otherAgent, 'admin@example.test');
        $mine = $service->queueUpgrade($agent, 'admin@example.test');

        $claimed = $service->claimNext($agent);

        $this->assertSame($mine->id, $claimed->id);
        $this->assertSame(ServerAgentControlJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_claim_next_returns_null_when_nothing_is_queued(): void
    {
        $agent = Agent::factory()->approved()->create();

        $this->assertNull(app(ServerAgentControlJobService::class)->claimNext($agent));
    }

    // ------------------------------------------------------------------
    // Root-cause fix (Server Agent 1.4.1 bootstrap follow-up — Control
    // Job #1 targeted 1.4.1 but was still QUEUED after the Agent was
    // manually bootstrapped straight to 1.4.1): claimNext() must never
    // hand out a queued job whose target is already satisfied.
    // ------------------------------------------------------------------

    public function test_claim_next_supersedes_a_queued_job_whose_target_the_agent_already_satisfies(): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.1']);
        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.1',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
        ]);

        $claimed = app(ServerAgentControlJobService::class)->claimNext($agent);

        $this->assertNull($claimed, 'A same-version queued job must never be returned to the Server Agent.');
        $job->refresh();
        $this->assertSame(ServerAgentControlJob::STATUS_SUPERSEDED, $job->status);
        $this->assertStringContainsString('1.4.1', (string) $job->error_message);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'server_agent.upgrade_superseded',
            'subject_type' => ServerAgentControlJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_claim_next_supersedes_a_queued_job_whose_target_is_below_the_agents_current_version(): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.5.0']);
        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.1',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
        ]);

        $this->assertNull(app(ServerAgentControlJobService::class)->claimNext($agent));
        $this->assertSame(ServerAgentControlJob::STATUS_SUPERSEDED, $job->fresh()->status);
    }

    public function test_claim_next_uses_real_semantic_version_comparison_not_lexical_comparison(): void
    {
        // A lexical/string comparison would put "1.4.9" ahead of "1.4.10"
        // (since '9' > '1' as characters) and wrongly treat this job as
        // already satisfied. version_compare() must be used instead.
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.9']);
        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.10',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
        ]);

        $claimed = app(ServerAgentControlJobService::class)->claimNext($agent);

        $this->assertNotNull($claimed, '1.4.9 does not satisfy target 1.4.10 under real semver comparison; the job must still be claimable.');
        $this->assertSame($job->id, $claimed->id);
        $this->assertSame(ServerAgentControlJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_claim_next_still_claims_a_genuinely_newer_version_job_normally(): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.1',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
        ]);

        $claimed = app(ServerAgentControlJobService::class)->claimNext($agent);

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
        $this->assertSame(ServerAgentControlJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_claim_next_skips_a_superseded_candidate_and_returns_the_next_eligible_queued_job(): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.1']);
        $stale = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.1',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
            'created_at' => now()->subMinutes(5),
        ]);
        $eligible = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => ServerAgentControlJob::STATUS_QUEUED,
            'target_version' => '1.4.2',
            'target_commit_sha' => str_repeat('b', 40),
            'repository_mapping' => 'deployment-system',
            'created_at' => now(),
        ]);

        $claimed = app(ServerAgentControlJobService::class)->claimNext($agent);

        $this->assertSame($eligible->id, $claimed->id);
        $this->assertSame(ServerAgentControlJob::STATUS_RUNNING, $claimed->fresh()->status);
        $this->assertSame(ServerAgentControlJob::STATUS_SUPERSEDED, $stale->fresh()->status);
    }

    #[DataProvider('provideNonQueuedStatuses')]
    public function test_claim_next_never_touches_a_non_queued_job_of_any_status(string $status): void
    {
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.1']);
        $job = ServerAgentControlJob::create([
            'agent_id' => $agent->id,
            'action' => ServerAgentControlJob::ACTION_UPGRADE,
            'status' => $status,
            'target_version' => '1.4.1',
            'target_commit_sha' => str_repeat('a', 40),
            'repository_mapping' => 'deployment-system',
        ]);

        app(ServerAgentControlJobService::class)->claimNext($agent);

        $this->assertSame($status, $job->fresh()->status, "claimNext() must never transition a job that was already {$status}.");
    }

    /** @return array<string, array{0: string}> */
    public static function provideNonQueuedStatuses(): array
    {
        return [
            'running' => [ServerAgentControlJob::STATUS_RUNNING],
            'awaiting_heartbeat' => [ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT],
            'succeeded' => [ServerAgentControlJob::STATUS_SUCCEEDED],
            'failed' => [ServerAgentControlJob::STATUS_FAILED],
        ];
    }

    public function test_report_upgrade_started_rejects_a_wrong_commit(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);
        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $service->claimNext($agent);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->reportUpgradeStarted($job->fresh(), 'validated', str_repeat('9', 40), str_repeat('a', 64));
    }

    public function test_report_upgrade_started_rejects_a_wrong_package_hash(): void
    {
        $target = $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);
        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $service->claimNext($agent);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->reportUpgradeStarted($job->fresh(), 'validated', $target['commit'], str_repeat('b', 64));
    }

    public function test_full_upgrade_lifecycle_from_queue_to_verified_heartbeat(): void
    {
        $target = $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);

        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $service->claimNext($agent);
        $started = $service->reportUpgradeStarted($job->fresh(), 'validated', $target['commit'], $target['package']->checksum_sha256);

        $this->assertSame(ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT, $started->status);

        $this->assertSame(0, $service->verifyUpgradeHeartbeat($agent, '1.4.0'));
        $this->assertSame(ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT, $job->fresh()->status);

        $this->assertSame(1, $service->verifyUpgradeHeartbeat($agent, '1.4.2'));
        $this->assertSame(ServerAgentControlJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame('1.4.2', $job->fresh()->reported_version);
        $this->assertNotNull($job->fresh()->upgrade_verified_at);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'server_agent.upgrade_verified_by_heartbeat',
            'subject_type' => ServerAgentControlJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_report_failure_marks_the_job_failed_and_audit_logs_it(): void
    {
        $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);
        $service = app(ServerAgentControlJobService::class);
        $job = $service->queueUpgrade($agent, 'admin@example.test');
        $service->claimNext($agent);

        $updated = $service->reportFailure($job->fresh(), 'restart verification timed out');

        $this->assertSame(ServerAgentControlJob::STATUS_FAILED, $updated->status);
        $this->assertSame('restart verification timed out', $updated->error_message);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'server_agent.upgrade_failed']);
    }

    // ------------------------------------------------------------------
    // ServerAgentPackageService / build-job lifecycle
    // ------------------------------------------------------------------

    public function test_package_build_job_queue_is_idempotent_for_the_same_commit(): void
    {
        $commit = str_repeat('c', 40);
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);

        $first = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);
        $second = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
    }

    // ------------------------------------------------------------------
    // Root-cause fix (Production job #1, "Class SafeFilesystem not
    // found"): once a build job is FAILED, queueBuildIfMissing() must
    // reuse that SAME row rather than attempt a second create() for the
    // same (commit_hash, repository_mapping) — that key is uniquely
    // constrained, so a naive second create() throws an uncaught
    // QueryException/500.
    // ------------------------------------------------------------------

    public function test_a_failed_build_job_is_reused_not_recreated_on_retry(): void
    {
        $commit = str_repeat('1', 40);
        $originalBuilder = LocalAgent::factory()->productionBuild()->create(['agent_version' => '1.5.5']);
        $service = app(ServerAgentPackageService::class);
        $job = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $originalBuilder);
        $job->update(['status' => ServerAgentPackageBuildJob::STATUS_FAILED, 'error_message' => 'Class "SafeFilesystem" not found']);

        $newBuilder = LocalAgent::factory()->productionBuild()->create(['agent_version' => '1.5.6']);
        $retried = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $newBuilder);

        $this->assertSame($job->id, $retried->id, 'A failed job must be reused on the same row, never recreated.');
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_QUEUED, $retried->status);
        $this->assertNull($retried->error_message, 'A retried job must have its stale failure message cleared.');
        $this->assertSame($newBuilder->id, $retried->local_agent_id, 'A retried job must be reassigned to the current authoritative builder.');
        $this->assertSame('1.5.6', $retried->builder_agent_version, 'A retried job must record the current builder version, not the one that failed.');
        $this->assertSame($commit, $retried->commit_hash);
        $this->assertSame('deployment-system', $retried->repository_mapping);
        $this->assertSame('1.4.1', $retried->agent_version, 'The target Server Agent version must survive a retry untouched.');
        $this->assertSame('main', $retried->branch);
    }

    public function test_a_queued_build_job_remains_idempotently_reused_not_duplicated(): void
    {
        $commit = str_repeat('2', 40);
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $job = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);

        $again = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);

        $this->assertSame($job->id, $again->id);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_QUEUED, $again->status);
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
    }

    public function test_a_running_build_job_remains_idempotently_reused_not_duplicated(): void
    {
        $commit = str_repeat('3', 40);
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $job = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);
        $service->claimNextBuildJob($builder);

        $again = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);

        $this->assertSame($job->id, $again->id);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_RUNNING, $again->status, 'A running job must not be reset to queued by a concurrent retry.');
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
    }

    public function test_retrying_a_failed_build_job_through_queue_upgrade_still_requires_an_eligible_builder(): void
    {
        ['project' => $project, 'commit' => $commit] = $this->createUpgradeAuthority();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        // First attempt: the default authoritative builder is eligible,
        // so a build job gets queued (queueUpgrade() still fails closed
        // with 409 pending the package, exactly like
        // test_queue_upgrade_fails_closed_when_no_package_exists_yet).
        // The queued job then fails in the field (mirrors Production job
        // #1).
        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            // Expected — no package exists yet.
        }
        $job = ServerAgentPackageBuildJob::sole();
        $job->update(['status' => ServerAgentPackageBuildJob::STATUS_FAILED, 'error_message' => 'Class "SafeFilesystem" not found']);

        // The project's authoritative builder now regresses below the
        // minimum before anyone retries.
        $project->authoritativeBuildAgent->update(['agent_version' => '1.5.4']);

        try {
            app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');
            $this->fail('Expected the retry to fail closed for a builder that regressed below the eligibility minimum.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('does not support Server Agent package builds', $e->getMessage());
        }

        // The eligibility gate must reject the retry BEFORE touching the
        // failed row at all — it must remain exactly as it failed.
        $job->refresh();
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_FAILED, $job->status);
        $this->assertSame('Class "SafeFilesystem" not found', $job->error_message);
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
    }

    public function test_concurrent_retries_of_the_same_failed_build_job_resolve_to_one_row_without_a_unique_constraint_error(): void
    {
        $commit = str_repeat('5', 40);
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $job = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);
        $job->update(['status' => ServerAgentPackageBuildJob::STATUS_FAILED]);

        $first = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);
        $second = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);

        $this->assertSame($job->id, $first->id);
        $this->assertSame($job->id, $second->id);
        $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_QUEUED, $second->status);
    }

    public function test_claim_next_build_job_only_claims_for_the_requesting_local_agent(): void
    {
        $builder = LocalAgent::factory()->productionBuild()->create();
        $otherBuilder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $service->queueBuildIfMissing(str_repeat('d', 40), 'main', '1.4.1', 'deployment-system', $otherBuilder);
        $mine = $service->queueBuildIfMissing(str_repeat('e', 40), 'main', '1.4.1', 'deployment-system', $builder);

        $claimed = $service->claimNextBuildJob($builder);

        $this->assertSame($mine->id, $claimed->id);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_RUNNING, $claimed->status);
    }

    public function test_queue_build_if_missing_persists_the_builders_point_in_time_version(): void
    {
        $builder = LocalAgent::factory()->productionBuild()->create(['agent_version' => '1.5.5']);
        $service = app(ServerAgentPackageService::class);

        $job = $service->queueBuildIfMissing(str_repeat('9', 40), 'main', '1.4.1', 'deployment-system', $builder);

        $this->assertSame('1.5.5', $job->builder_agent_version);
        // Never overloaded with the TARGET Server Agent version.
        $this->assertSame('1.4.1', $job->agent_version);
    }

    /**
     * Root-cause fix compatibility proof: Production ServerAgentPackageBuildJob
     * #1 was queued before builder_agent_version existed and must remain
     * fully claimable — claimNextBuildJob() must never require this
     * column to be non-null.
     */
    public function test_a_legacy_build_job_with_a_null_builder_version_remains_claimable(): void
    {
        $builder = LocalAgent::factory()->productionBuild()->create();
        $legacyJob = ServerAgentPackageBuildJob::create([
            'local_agent_id' => $builder->id,
            'commit_hash' => str_repeat('8', 40),
            'branch' => 'master',
            'agent_version' => '1.4.1',
            'repository_mapping' => 'deployment-system',
            'status' => ServerAgentPackageBuildJob::STATUS_QUEUED,
            'builder_agent_version' => null,
        ]);

        $claimed = app(ServerAgentPackageService::class)->claimNextBuildJob($builder);

        $this->assertSame($legacyJob->id, $claimed->id);
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_RUNNING, $claimed->status);
        $this->assertNull($claimed->builder_agent_version, 'Claiming a legacy row must not retroactively fabricate a builder_agent_version it never recorded.');
    }

    public function test_record_package_uploaded_rejects_a_commit_mismatch(): void
    {
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $job = $service->queueBuildIfMissing(str_repeat('f', 40), 'main', '1.4.1', 'deployment-system', $builder);
        $service->claimNextBuildJob($builder);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->recordPackageUploaded($job->fresh(), [
            'commit_hash' => str_repeat('9', 40),
            'repository_mapping' => 'deployment-system',
            'agent_version' => '1.4.1',
            'tree_hash' => str_repeat('1', 40),
            'checksum_sha256' => str_repeat('a', 64),
            'size_bytes' => 100,
            'disk_path' => 'server-agent/x.zip',
        ]);
    }

    public function test_record_package_uploaded_creates_an_available_package_and_marks_the_job_succeeded(): void
    {
        $builder = LocalAgent::factory()->productionBuild()->create();
        $service = app(ServerAgentPackageService::class);
        $commit = str_repeat('7', 40);
        $job = $service->queueBuildIfMissing($commit, 'main', '1.4.1', 'deployment-system', $builder);
        $service->claimNextBuildJob($builder);

        $package = $service->recordPackageUploaded($job->fresh(), [
            'commit_hash' => $commit,
            'repository_mapping' => 'deployment-system',
            'agent_version' => '1.4.1',
            'tree_hash' => str_repeat('2', 40),
            'checksum_sha256' => str_repeat('b', 64),
            'size_bytes' => 1234,
            'disk_path' => 'server-agent/deployment-system/x.zip',
        ]);

        $this->assertTrue($package->isAvailable());
        $this->assertSame(ServerAgentPackageBuildJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame($package->id, $job->fresh()->server_agent_package_id);
    }

    /**
     * @param array<string, mixed> $builderOverrides applied on top of the
     *     default (eligible) productionBuild() state — used to construct
     *     a deliberately stale/incapable builder for the eligibility-gate
     *     tests above.
     * @return array{project:Project,commit:string}
     */
    private function createUpgradeAuthority(array $builderOverrides = []): array
    {
        $commit = str_repeat('a', 40);
        $project = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $builder = LocalAgent::factory()->productionBuild()->create($builderOverrides);
        $project->update(['authoritative_build_local_agent_id' => $builder->id]);
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

        return ['project' => $project, 'commit' => $commit];
    }

    /** @return array{project:Project,commit:string,package:ServerAgentPackage} */
    private function createUpgradeAuthorityWithPackage(): array
    {
        $authority = $this->createUpgradeAuthority();

        $package = ServerAgentPackage::create([
            'commit_hash' => $authority['commit'],
            'agent_version' => '1.4.1',
            'repository_mapping' => 'deployment-system',
            'tree_hash' => str_repeat('3', 40),
            'checksum_sha256' => str_repeat('c', 64),
            'size_bytes' => 4321,
            'disk_path' => 'server-agent/deployment-system/' . $authority['commit'] . '.zip',
            'status' => ServerAgentPackage::STATUS_AVAILABLE,
        ]);

        return [...$authority, 'package' => $package];
    }
}
