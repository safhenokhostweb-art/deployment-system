<?php

namespace Tests\Unit\Services\Publish;

use App\Models\Artifact;
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

    public function test_resolves_the_only_current_ready_release_with_an_available_artifact(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $commit = str_repeat('a', 40);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'main']);
        Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $release->id, 'commit_hash' => $commit, 'branch' => 'main']);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'main',
            'finished_at' => now(),
        ]);

        $target = app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');

        $this->assertSame($commit, $target['commit_sha']);
        $this->assertSame('main', $target['branch']);
        $this->assertSame('saas-platform', $target['repository_mapping']);
    }

    public function test_fails_closed_when_the_project_does_not_exist(): void
    {
        $this->expectException(HttpException::class);
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_project_is_inactive(): void
    {
        Project::factory()->create(['project_key' => 'saas-platform', 'is_active' => false, 'branch' => 'main']);

        $this->expectException(HttpException::class);
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_there_is_no_current_release(): void
    {
        Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);

        $this->expectException(HttpException::class);
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_current_release_is_not_ready(): void
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
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_project_tracks_a_different_branch_than_expected(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'develop']);
        $commit = str_repeat('c', 40);
        $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'develop']);
        Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $release->id, 'commit_hash' => $commit, 'branch' => 'develop']);
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
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_selects_the_current_ready_release_before_it_is_deployed(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $s1 = str_repeat('1', 40);
        $s1Release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $s1, 'branch' => 'main']);
        Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $s1Release->id, 'commit_hash' => $s1, 'branch' => 'main']);
        Deployment::factory()->create(['project_id' => $project->id, 'server_id' => $project->server_id, 'release_id' => $s1Release->id, 'status' => Deployment::STATUS_SUCCEEDED, 'commit_hash' => $s1, 'branch' => 'main', 'finished_at' => now()]);

        $s2 = '866abe66f374ae2b2693fbd4d8255a3bfdef5dde';
        $s2Release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $s2, 'branch' => 'main']);
        $artifact = Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $s2Release->id, 'commit_hash' => $s2, 'branch' => 'main']);

        $target = app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');

        $this->assertSame($s2, $target['commit_sha']);
        $this->assertSame($s2Release->public_id, $target['release_id']);
        $this->assertSame($artifact->public_id, $target['artifact_id']);
        $this->assertSame('current_project_branch_release_v1', $target['selection_rule']);
        $this->assertDatabaseMissing('deployments', ['release_id' => $s2Release->id]);
    }

    public function test_newest_ready_release_deterministically_supersedes_older_ready_releases(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        foreach ([str_repeat('2', 40), str_repeat('3', 40)] as $commit) {
            $release = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => $commit, 'branch' => 'main']);
            Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $release->id, 'commit_hash' => $commit, 'branch' => 'main']);
        }

        $this->assertSame(str_repeat('3', 40), app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main')['commit_sha']);
    }

    public function test_fails_closed_when_a_newer_not_ready_release_supersedes_an_older_ready_release(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $older = Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('2', 40), 'branch' => 'main']);
        Artifact::factory()->create(['project_id' => $project->id, 'release_id' => $older->id, 'commit_hash' => $older->commit_hash, 'branch' => 'main']);
        Release::factory()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('3', 40), 'branch' => 'main', 'status' => Release::STATUS_AWAITING_ARTIFACT]);

        $this->expectException(HttpException::class);
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }

    public function test_fails_closed_when_the_current_ready_release_has_no_matching_available_artifact(): void
    {
        $project = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        Release::factory()->ready()->create(['project_id' => $project->id, 'commit_hash' => str_repeat('4', 40), 'branch' => 'main']);

        $this->expectException(HttpException::class);
        app(SaasDependencyTargetResolver::class)->resolve('saas-platform', 'main');
    }
}
