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
            // A build job must have been auto-queued so a retry can succeed.
            $this->assertDatabaseCount('server_agent_package_build_jobs', 1);
        }
    }

    public function test_queue_upgrade_creates_a_queued_job_with_verified_target(): void
    {
        $target = $this->createUpgradeAuthorityWithPackage();
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.0']);

        $job = app(ServerAgentControlJobService::class)->queueUpgrade($agent, 'admin@example.test');

        $this->assertSame(ServerAgentControlJob::ACTION_UPGRADE, $job->action);
        $this->assertSame(ServerAgentControlJob::STATUS_QUEUED, $job->status);
        $this->assertSame('1.4.1', $job->target_version);
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
        $agent = Agent::factory()->approved()->create(['agent_version' => '1.4.1']);

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

        $this->assertSame(1, $service->verifyUpgradeHeartbeat($agent, '1.4.1'));
        $this->assertSame(ServerAgentControlJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame('1.4.1', $job->fresh()->reported_version);
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

    /** @return array{project:Project,commit:string} */
    private function createUpgradeAuthority(): array
    {
        $commit = str_repeat('a', 40);
        $project = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $builder = LocalAgent::factory()->productionBuild()->create();
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
