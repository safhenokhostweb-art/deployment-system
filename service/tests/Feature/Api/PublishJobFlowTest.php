<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 5: the PublishJob lifecycle over the signed Local Agent channel —
 * mirrors OperationJobFlowTest's structure.
 */
class PublishJobFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);
    }

    private function headersFor(LocalAgent $agent, string $method, string $path, string $body = ''): array
    {
        return $this->localAgentHeaders($method, $path, $body, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);
    }

    private function mappedProject(LocalAgent $agent): Project
    {
        $project = Project::factory()->create();
        $agent->update([
            'role' => LocalAgent::ROLE_PRODUCTION_BUILD,
            'last_seen_at' => now(),
            'capabilities' => ['git' => true, 'php' => '8.4', 'laravel_release_manifest' => true],
        ]);
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'source_workspace_path' => '/opt/deployment-agent/workspaces/project',
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('e', 40),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        return $project;
    }

    public function test_agent_claims_a_queued_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $response = $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk();

        $response->assertJsonPath('job.id', $job->public_id);
        $response->assertJsonPath('job.action', PublishJob::ACTION_STATUS_CHECK);
        $response->assertJsonPath('job.project_key', $project->project_key);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_agent_reports_changed_files_for_a_status_check_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['changed_files' => ['app/Foo.php', 'README.md'], 'head_sha' => str_repeat('a', 40), 'branch' => 'main', 'working_tree_clean' => false];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_AWAITING_APPROVAL]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_AWAITING_APPROVAL, $job->status);
        $this->assertSame(['app/Foo.php', 'README.md'], $job->changed_files);
        $this->assertSame(2, $job->files_changed_count);
    }

    public function test_no_source_change_publish_exposes_the_reviewed_head_to_the_agent(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $project->localProjectMappings()->first()->update(['local_head_sha' => str_repeat('c', 40), 'remote_tracking_sha' => str_repeat('c', 40), 'working_tree_dirty' => false]);
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, [], [], str_repeat('c', 40), 'main', true);
        $service->approve($job, null, 'dev@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk()
            ->assertJsonPath('job.action', PublishJob::ACTION_PUBLISH)
            ->assertJsonPath('job.no_source_change', true)
            ->assertJsonPath('job.expected_head_sha', str_repeat('c', 40))
            ->assertJsonPath('job.expected_branch', 'main');
    }

    public function test_artifact_build_job_exposes_only_artifact_commit_details_to_the_agent(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queueArtifactBuild($agent, $project, str_repeat('e', 40), 'main', 'dev@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk()
            ->assertJsonPath('job.id', $job->public_id)
            ->assertJsonPath('job.action', PublishJob::ACTION_BUILD_ARTIFACT)
            ->assertJsonPath('job.project_key', $project->project_key)
            ->assertJsonPath('job.commit_message', null)
            ->assertJsonPath('job.no_source_change', false)
            ->assertJsonPath('job.expected_head_sha', null)
            ->assertJsonPath('job.artifact_commit_hash', str_repeat('e', 40))
            ->assertJsonPath('job.artifact_branch', 'main');
    }

    public function test_artifact_build_result_must_match_requested_commit(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queueArtifactBuild($agent, $project, str_repeat('e', 40), 'main', 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['status' => 'succeeded', 'commit_hash' => str_repeat('f', 40)];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";

        $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertStatus(422);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    public function test_customer_site_build_contract_is_v2_and_contains_only_allowlisted_pins(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.5.0']);
        $project = $this->mappedProject($agent);
        $project->update(['project_key' => 'customer-site']);
        $dependencyProject = Project::factory()->create(['project_key' => 'saas-platform', 'repository' => 'owner/saas-platform']);
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $dependencyProject->id, 'local_path' => '/opt/workspaces/saas-platform', 'source_workspace_path' => '/opt/workspaces/saas-platform', 'git_branch' => 'main']);
        app(PublishJobService::class)->queueArtifactBuild($agent, $project, '1bf0d8913948f928e90c452d8896a71922ac0398', 'main', 'dev@example.test');

        $headers = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $response = $this->get('/api/local-agent/publish-jobs/next', $headers)->assertOk();
        $response->assertJsonPath('job.build_contract_version', 2)
            ->assertJsonPath('job.dependency_contract.version', 2)
            ->assertJsonPath('job.dependency_contract.required', true)
            ->assertJsonPath('job.dependency_contract.dependencies.4.package_name', 'saas-platform/core-utils')
            ->assertJsonPath('job.dependency_contract.dependencies.4.commit_sha', 'd9997e892564b2b8f16e7f67129b1e629f592122');
        $dependency = $response->json('job.dependency_contract.dependencies.4');
        $this->assertArrayNotHasKey('repository_url', $dependency);

        $syncHeaders = $this->headersFor($agent, 'GET', '/api/local-agent/projects');
        $sync = $this->get('/api/local-agent/projects', $syncHeaders)->assertOk();
        $consumer = collect($sync->json('projects'))->firstWhere('project_key', 'customer-site');
        $this->assertTrue($consumer['dependency_pinning_required']);
        $this->assertCount(5, $consumer['dependency_allowlist']);
        $this->assertArrayNotHasKey('commit_sha', $consumer['dependency_allowlist'][0]);
    }

    public function test_customer_site_dependency_policy_fails_closed_on_old_local_agent_only(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.4.7']);
        $project = $this->mappedProject($agent);
        $project->update(['project_key' => 'customer-site']);
        $job = app(PublishJobService::class)->queueArtifactBuild($agent, $project, str_repeat('e', 40), 'main', 'dev@example.test');
        $this->get('/api/local-agent/publish-jobs/next', $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next'))->assertStatus(409);
        $this->assertSame(PublishJob::STATUS_FAILED, $job->fresh()->status);
    }

    public function test_new_hub_with_policy_off_keeps_local_agent_1_4_7_compatible(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
        $agent = LocalAgent::factory()->approved()->create(['agent_version' => '1.4.7']);
        $project = $this->mappedProject($agent);
        $project->update(['project_key' => 'customer-site']);
        app(PublishJobService::class)->queueArtifactBuild($agent, $project, str_repeat('e', 40), 'main', 'dev@example.test');

        $response = $this->get('/api/local-agent/publish-jobs/next', $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next'));
        $response->assertOk()->assertJsonPath('job.build_contract_version', 2);
        $this->assertSame([], $response->json('job.dependency_contract'));
    }

    public function test_status_check_rejects_no_source_change_evidence_for_the_wrong_mapping_branch(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);
        $body = ['changed_files' => [], 'head_sha' => str_repeat('a', 40), 'branch' => 'wrong', 'working_tree_clean' => true];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";

        $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertStatus(422);
    }

    public function test_no_source_change_result_must_equal_the_reviewed_head(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $project->localProjectMappings()->first()->update(['local_head_sha' => str_repeat('c', 40), 'remote_tracking_sha' => str_repeat('c', 40), 'working_tree_dirty' => false]);
        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, [], [], str_repeat('c', 40), 'main', true);
        $service->approve($job, null, 'dev@example.test');
        $service->claimNext($agent);
        $body = ['status' => 'succeeded', 'commit_hash' => str_repeat('d', 40)];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";

        $this->postJson($path, $body, $this->headersFor($agent, 'POST', $path, json_encode($body)))->assertStatus(422);
        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }

    /**
     * Phase 5.5: the agent reports blocked_files alongside changed_files
     * (agent.php's status_check branch now sends both) — this is the
     * over-the-wire contract for the Publish page's "Blocked Files" list.
     */
    public function test_agent_reports_blocked_files_alongside_changed_files(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['changed_files' => ['app/Foo.php'], 'blocked_files' => ['.env', 'credentials.json'], 'head_sha' => str_repeat('a', 40), 'branch' => 'main', 'working_tree_clean' => false];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk();

        $job->refresh();
        $this->assertSame(['app/Foo.php'], $job->changed_files);
        $this->assertSame(['.env', 'credentials.json'], $job->blocked_files);
        $this->assertSame(1, $job->files_changed_count);
    }

    public function test_agent_can_report_failure_for_a_status_check_job(): void
    {
        // Regression: a status_check job that fails on the agent side
        // (e.g. `git status` itself errors) must go through the same
        // failure path as any other job, not be forced through the
        // success-only reportStatusCheck() validation.
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $body = ['status' => 'failed', 'error_message' => 'not a git repository'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($body));

        $this->postJson($path, $body, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_FAILED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_FAILED, $job->status);
        $this->assertSame('not a git repository', $job->error_message);
    }

    public function test_agent_claims_an_approved_publish_job_and_reports_success(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $project->localProjectMappings()->first()->update(['local_head_sha' => str_repeat('a', 40), 'working_tree_dirty' => true]);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);
        app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');

        $nextHeaders = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $next = $this->get('/api/local-agent/publish-jobs/next', $nextHeaders)->assertOk();
        $next->assertJsonPath('job.action', PublishJob::ACTION_PUBLISH);
        $next->assertJsonPath('job.commit_message', 'Fix the widget');

        $reportBody = ['status' => 'succeeded', 'commit_hash' => str_repeat('a', 40), 'output' => 'main -> main'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $reportHeaders = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $reportHeaders)->assertOk()->assertJson(['status' => PublishJob::STATUS_SUCCEEDED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(str_repeat('a', 40), $job->result_commit_hash);
    }

    public function test_agent_claims_an_approved_rollback_job_and_reports_success(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_ROLLBACK, 'dev@example.test');
        app(PublishJobService::class)->approveRollback($job, 'dev@example.test');

        $nextHeaders = $this->headersFor($agent, 'GET', '/api/local-agent/publish-jobs/next');
        $this->get('/api/local-agent/publish-jobs/next', $nextHeaders)
            ->assertOk()
            ->assertJsonPath('job.action', PublishJob::ACTION_ROLLBACK)
            ->assertJsonPath('job.commit_message', null);

        $reportBody = ['status' => 'succeeded', 'commit_hash' => str_repeat('b', 40)];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $reportHeaders = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $reportHeaders)->assertOk()->assertJson(['status' => PublishJob::STATUS_SUCCEEDED]);
    }

    public function test_agent_reports_a_failed_publish(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = $this->mappedProject($agent);
        $project->localProjectMappings()->first()->update(['local_head_sha' => str_repeat('a', 40), 'working_tree_dirty' => true]);
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->reportStatusCheck($job, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);
        app(PublishJobService::class)->approve($job, 'Fix the widget', 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $reportBody = ['status' => 'failed', 'error_message' => 'git push rejected (non-fast-forward)'];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->headersFor($agent, 'POST', $path, json_encode($reportBody));

        $this->postJson($path, $reportBody, $headers)->assertOk()->assertJson(['status' => PublishJob::STATUS_FAILED]);

        $job->refresh();
        $this->assertSame(PublishJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('non-fast-forward', $job->error_message);
    }

    public function test_agent_cannot_report_on_another_agents_publish_job(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $job = app(PublishJobService::class)->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        app(PublishJobService::class)->claimNext($agent);

        $otherAgent = LocalAgent::factory()->approved()->create();

        $body = ['changed_files' => []];
        $path = "/api/local-agent/publish-jobs/{$job->public_id}/report";
        $headers = $this->localAgentHeaders('POST', $path, json_encode($body), $otherAgent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson($path, $body, $headers)->assertStatus(403);
    }

    public function test_heartbeat_updates_hostname_and_os(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['hostname' => 'DEV-09', 'operating_system' => 'linux'];
        $headers = $this->headersFor($agent, 'POST', '/api/local-agent/heartbeat', json_encode($body));

        $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk()->assertJson(['status' => 'ok']);

        $agent->refresh();
        $this->assertSame('DEV-09', $agent->hostname);
        $this->assertSame('linux', $agent->operating_system);
        $this->assertNotNull($agent->last_seen_at);
    }
}
