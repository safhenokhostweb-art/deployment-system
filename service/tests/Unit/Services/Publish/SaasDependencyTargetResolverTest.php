<?php

namespace Tests\Unit\Services\Publish;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Services\Publish\SaasDependencyTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256): mirrors
 * ServerAgentUpgradeTargetResolver's own fail-closed reasoning, applied to
 * an arbitrary upstream project instead of deployment-system's own Server
 * Agent runtime. Deliberately never touches origin/main HEAD, a mutable
 * persistent workspace, or a GitHub API — every case here is pure
 * Hub-owned Deployment/Release state.
 */
class SaasDependencyTargetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_commit_of_the_latest_successful_deployment_backed_by_a_ready_release(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $commit = str_repeat('a', 40);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'main']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'main',
            'finished_at' => now(),
        ]);

        $target = (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');

        $this->assertSame($commit, $target['commit_sha']);
        $this->assertSame('main', $target['branch']);
        $this->assertSame('saas-platform', $target['repository_mapping']);
    }

    public function test_fails_closed_when_the_project_does_not_exist(): void
    {
        $this->expectException(HttpException::class);
        (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_project_is_inactive(): void
    {
        Project::factory()->create(['project_key' => 'saas-platform', 'is_active' => false, 'branch' => 'main']);

        $this->expectException(HttpException::class);
        (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_there_is_no_successful_deployment(): void
    {
        Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);

        $this->expectException(HttpException::class);
        (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_successful_deployment_is_not_backed_by_a_ready_release(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $commit = str_repeat('b', 40);
        $release = Release::factory()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'main', 'status' => Release::STATUS_AWAITING_ARTIFACT]);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'main',
            'finished_at' => now(),
        ]);

        $this->expectException(HttpException::class);
        (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_deployment_is_on_a_different_branch_than_expected(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'develop']);
        $commit = str_repeat('c', 40);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'develop']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'develop',
            'finished_at' => now(),
        ]);

        $this->expectException(HttpException::class);
        (new SaasDependencyTargetResolver())->resolve('saas-platform', 'main');
    }
}
