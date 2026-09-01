<?php

namespace Tests\Feature\Releases;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ArtifactBuildReleaseLinkageTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_commit_build_atomically_resolves_release_and_reuses_job_without_deployment_side_effects(): void
    {
        [$project, $agent] = $this->buildContext('deployment-system');
        $sha = str_repeat('a', 40);
        $service = app(PublishJobService::class);

        $first = $service->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test');
        $replay = $service->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test');

        $release = Release::sole();
        $this->assertSame($first->id, $replay->id);
        $this->assertSame($release->id, $first->release_id);
        $this->assertSame($project->id, $release->project_id);
        $this->assertSame($sha, $release->commit_hash);
        $this->assertSame('main', $release->branch);
        $this->assertSame(Release::READINESS_POLICY_EXACT_COMMIT, $release->readiness_policy);
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $this->assertSame($release->public_id, $first->evidence_contract['release_id']);
        $this->assertDatabaseCount('releases', 1);
        $this->assertDatabaseCount('publish_jobs', 1);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('deployment_tasks', 0);
        $this->assertDatabaseCount('agent_jobs', 0);
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'deployment.approved']);
    }

    public function test_existing_matching_release_is_reused_without_duplicate_ambiguity(): void
    {
        [$project, $agent] = $this->buildContext('deployment-system');
        $sha = str_repeat('b', 40);
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        $job = app(PublishJobService::class)->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test');

        $this->assertSame($release->id, $job->release_id);
        $this->assertDatabaseCount('releases', 1);
        $this->assertDatabaseCount('publish_jobs', 1);
    }

    public function test_wrong_project_commit_or_branch_release_linkage_fails_closed(): void
    {
        [$project, $agent] = $this->buildContext('deployment-system');
        $other = Project::factory()->create(['branch' => 'main']);
        $sha = str_repeat('c', 40);
        $mismatches = [
            Release::factory()->create(['project_id' => $other->id, 'commit_hash' => $sha, 'branch' => 'main']),
            Release::factory()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('d', 40), 'branch' => 'main']),
        ];

        foreach ($mismatches as $release) {
            try {
                app(PublishJobService::class)->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test', $release);
                $this->fail('A mismatched Release unexpectedly authorized an Artifact build.');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        $branchMismatchSha = str_repeat('e', 40);
        Release::factory()->create(['project_id' => $project->id, 'commit_hash' => $branchMismatchSha, 'branch' => 'release']);
        try {
            app(PublishJobService::class)->queueArtifactBuild($agent, $project, $branchMismatchSha, 'main', 'operator@example.test');
            $this->fail('An existing Release on the wrong branch unexpectedly authorized an Artifact build.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertDatabaseCount('publish_jobs', 0);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_trusted_queue_context_repairs_an_unlinked_active_build_idempotently(): void
    {
        [$project, $agent] = $this->buildContext('deployment-system');
        $sha = str_repeat('f', 40);
        $orphan = PublishJob::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'release_id' => null,
            'active_build_key' => "artifact:{$project->id}:{$sha}",
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_APPROVED,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
            'evidence_contract' => null,
        ]);

        $resolved = app(PublishJobService::class)->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test');

        $this->assertSame($orphan->id, $resolved->id);
        $this->assertNotNull($resolved->release_id);
        $this->assertIsArray($resolved->evidence_contract);
        $this->assertDatabaseCount('releases', 1);
        $this->assertDatabaseCount('publish_jobs', 1);
    }

    public function test_customer_site_and_saas_platform_use_the_same_modern_release_linkage_contract(): void
    {
        foreach (['customer-site', 'saas-platform'] as $index => $projectKey) {
            [$project, $agent] = $this->buildContext($projectKey);
            $sha = str_repeat((string) ($index + 1), 40);

            $job = app(PublishJobService::class)->queueArtifactBuild($agent, $project, $sha, 'main', 'operator@example.test');
            $release = $job->release;

            $this->assertNotNull($release, $projectKey);
            $this->assertSame($project->id, $release->project_id, $projectKey);
            $this->assertSame($sha, $release->commit_hash, $projectKey);
            $this->assertSame('main', $release->branch, $projectKey);
            $this->assertSame($release->public_id, $job->evidence_contract['release_id'], $projectKey);
        }

        $this->assertDatabaseCount('releases', 2);
        $this->assertDatabaseCount('publish_jobs', 2);
        $this->assertDatabaseCount('deployments', 0);
    }

    /** @return array{Project, LocalAgent} */
    private function buildContext(string $projectKey): array
    {
        $project = Project::factory()->create([
            'project_key' => $projectKey,
            'branch' => 'main',
            'requires_complete_artifact' => true,
        ]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        $agent->update(['last_seen_at' => now()]);
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'source_workspace_path' => "C:\\workspaces\\{$projectKey}",
            'git_branch' => 'main',
            'local_head_sha' => str_repeat('9', 40),
            'remote_tracking_sha' => str_repeat('9', 40),
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        return [$project->fresh(), $agent->fresh()];
    }
}
