<?php

namespace Tests\Unit\Services\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Deployment\TransferTraceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTraceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_correlates_pushed_artifact_and_deployed_sha(): void
    {
        $project = Project::factory()->create();
        $sha = str_repeat('a', 40);
        PublishJob::factory()->create(['project_id' => $project->id, 'action' => PublishJob::ACTION_PUBLISH, 'status' => PublishJob::STATUS_SUCCEEDED, 'result_commit_hash' => $sha, 'finished_at' => now()]);
        $artifact = Artifact::factory()->create(['project_id' => $project->id, 'commit_hash' => $sha]);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'commit_hash' => $sha, 'artifact_id' => $artifact->id, 'status' => Deployment::STATUS_SUCCEEDED, 'deployed_at' => now(), 'finished_at' => now()]);

        $trace = app(TransferTraceService::class)->forProject($project);

        $this->assertSame($sha, $trace['pushed_sha']);
        $this->assertSame($sha, $trace['artifact_sha']);
        $this->assertSame($sha, $trace['production_sha']);
        $this->assertNull($trace['pending_transfer_sha']);
        $this->assertSame([], $trace['warnings']);
    }

    public function test_it_reports_a_pending_artifact_and_deployment_failure_by_sha(): void
    {
        $project = Project::factory()->create();
        $sha = str_repeat('b', 40);
        PublishJob::factory()->create(['project_id' => $project->id, 'action' => PublishJob::ACTION_PUBLISH, 'status' => PublishJob::STATUS_SUCCEEDED, 'result_commit_hash' => $sha]);
        Artifact::factory()->create(['project_id' => $project->id, 'commit_hash' => $sha]);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'commit_hash' => $sha, 'status' => Deployment::STATUS_FAILED, 'failure_code' => Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED]);

        $trace = app(TransferTraceService::class)->forProject($project);

        $this->assertSame($sha, $trace['pending_transfer_sha']);
        $this->assertSame('deployment', $trace['failure_stage']);
        $this->assertContains('ARTIFACT_READY_BUT_NOT_DEPLOYED', $trace['warnings']);
        $this->assertContains('DEPLOYMENT_FAILED', $trace['warnings']);
        $this->assertContains('PRODUCTION_SHA_UNKNOWN', $trace['warnings']);
    }

    public function test_it_identifies_the_saas_local_only_case_from_a_local_agent_report(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform']);
        $local = '6d8c3df6c6003ea3eafeccbee20236a8a237a3ab';
        $remote = '5cb0d9088ea070fecec2af08e43458bc8e7f232d';
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => LocalAgent::factory()->approved(),
            'local_head_sha' => $local,
            'remote_tracking_sha' => $remote,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);

        $trace = app(TransferTraceService::class)->forProject($project);

        $this->assertSame('LOCAL_ONLY', $trace['state']);
        $this->assertSame($local, $trace['local_head_sha']);
        $this->assertSame($remote, $trace['remote_tracking_sha']);
        $this->assertContains('LOCAL_NOT_PUSHED', $trace['warnings']);
    }

    public function test_it_distinguishes_published_without_artifact_and_artifact_failure(): void
    {
        $project = Project::factory()->create();
        $sha = str_repeat('c', 40);
        PublishJob::factory()->create(['project_id' => $project->id, 'action' => PublishJob::ACTION_PUBLISH, 'status' => PublishJob::STATUS_SUCCEEDED, 'result_commit_hash' => $sha]);

        $trace = app(TransferTraceService::class)->forProject($project);
        $this->assertSame('PUBLISHED_NO_ARTIFACT', $trace['state']);
        $this->assertContains('PUBLISHED_NO_ARTIFACT', $trace['warnings']);

        Artifact::factory()->create(['project_id' => $project->id, 'commit_hash' => $sha, 'status' => Artifact::STATUS_FAILED]);
        $trace = app(TransferTraceService::class)->forProject($project);
        $this->assertSame('ARTIFACT_FAILED', $trace['state']);
    }

    public function test_it_distinguishes_deployment_failure_and_production_behind_remote(): void
    {
        $project = Project::factory()->create();
        $old = str_repeat('d', 40);
        $remote = str_repeat('e', 40);
        LocalProjectMapping::factory()->create(['project_id' => $project->id, 'local_agent_id' => LocalAgent::factory()->approved(), 'local_head_sha' => $remote, 'remote_tracking_sha' => $remote, 'working_tree_dirty' => false, 'reported_at' => now()]);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'commit_hash' => $old, 'status' => Deployment::STATUS_SUCCEEDED, 'deployed_at' => now()]);

        $this->assertSame('PRODUCTION_BEHIND', app(TransferTraceService::class)->forProject($project)['state']);

        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'commit_hash' => $remote, 'status' => Deployment::STATUS_FAILED]);
        $this->assertSame('DEPLOY_FAILED', app(TransferTraceService::class)->forProject($project)['state']);
    }
}
