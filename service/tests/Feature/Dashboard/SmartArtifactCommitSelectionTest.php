<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartArtifactCommitSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_latest_verified_workspace_commit_is_selected_and_displayed(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(__('ui.publish.latest_verified_commit'))
            ->assertSee('/opt/deployment-agent/workspaces/customer-site')
            ->assertSee(str_repeat('a', 40))
            ->assertSee(__('ui.publish.workspace_clean'));

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('a', 40),
        ])->assertRedirect();

        $this->assertSame(str_repeat('a', 40), PublishJob::sole()->reviewed_head_sha);
    }

    public function test_stale_latest_sha_is_rejected(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('b', 40),
        ])->assertStatus(422)
            ->assertSee('The selected commit is not the current verified source workspace commit.');

        $this->assertDatabaseCount('publish_jobs', 0);
    }

    public function test_github_branch_head_is_compared_with_workspace_head(): void
    {
        [$user, $project] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $account = GitHubAccount::factory()->create();
        $project->update(['github_account_id' => $account->id]);

        $this->mock(GitHubAppClient::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('createInstallationToken')->once()->andReturn(['token' => 'test-token', 'expires_at' => now()->addHour()]);
            $mock->shouldReceive('getBranchHead')->once()->with('test-token', 'acme', 'customer-site', 'main')->andReturn(str_repeat('b', 40));
        });

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(str_repeat('b', 40))
            ->assertSee(__('ui.publish.github_workspace_mismatch'));
    }

    public function test_existing_available_artifact_creates_a_new_deployment_with_the_artifact_and_no_build_job(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('c', 40));
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('c', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertSee(__('ui.publish.artifact_already_exists'))
            ->assertSee(__('ui.publish.use_existing_artifact'));

        $response = $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('c', 40),
            'artifact_action' => 'use_existing',
        ]);

        $deployment = Deployment::sole();
        $response->assertRedirect(route('deployments.show', $deployment))
            ->assertSessionHas('status', __('ui.publish.deployment_created_from_artifact', [
                'deployment' => $deployment->id,
                'artifact' => $artifact->id,
                'status' => Deployment::STATUS_QUEUED,
            ]));
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame(str_repeat('c', 40), $deployment->commit_hash);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    public function test_use_existing_creates_a_new_deployment_instead_of_reusing_a_historical_failure(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', str_repeat('b', 40));
        $project->update(['requires_complete_artifact' => false]);
        $historical = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'commit_hash' => str_repeat('9', 40),
            'branch' => 'main',
            'status' => Deployment::STATUS_FAILED,
            'artifact_id' => null,
            'failure_code' => Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING,
        ]);
        $project->update(['requires_complete_artifact' => true]);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('b', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('b', 40),
            'artifact_action' => 'use_existing',
        ])->assertRedirect();

        $created = Deployment::where('id', '!=', $historical->id)->sole();
        $this->assertSame($artifact->id, $created->artifact_id);
        $this->assertSame(Deployment::STATUS_FAILED, $historical->fresh()->status);
        $this->assertNull($historical->fresh()->artifact_id);
        $this->assertDatabaseCount('deployments', 2);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    public function test_required_project_cannot_use_an_artifact_that_does_not_exist(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', str_repeat('2', 40));

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('2', 40),
            'artifact_action' => 'use_existing',
        ])->assertStatus(409);

        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    public function test_advanced_mode_can_rebuild_an_old_commit(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('deployment-system', str_repeat('d', 40));

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'advanced',
            'commit_hash' => str_repeat('e', 40),
        ])->assertRedirect();

        $job = PublishJob::sole();
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $job->action);
        $this->assertSame(str_repeat('e', 40), $job->reviewed_head_sha);
    }

    public function test_customer_site_uses_its_isolated_workspace_in_commit_selector(): void
    {
        [$user, $project] = $this->verifiedProject('customer-site', str_repeat('f', 40), '/var/www/customer-site');

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $response->assertSee('/opt/deployment-agent/workspaces/customer-site');
        $this->assertStringNotContainsString('/var/www/customer-site', $this->artifactSelectorHtml($response->getContent()));
    }

    public function test_customer_site_can_deploy_an_existing_artifact_without_rebuilding_it(): void
    {
        $this->assertExistingArtifactDeploymentFor('customer-site', str_repeat('3', 40), '/var/www/customer-site');
    }

    public function test_saas_platform_uses_its_isolated_workspace_in_commit_selector(): void
    {
        [$user, $project] = $this->verifiedProject('saas-platform', str_repeat('1', 40), '/var/www/saas-platform');

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $response->assertSee('/opt/deployment-agent/workspaces/saas-platform');
        $this->assertStringNotContainsString('/var/www/saas-platform', $this->artifactSelectorHtml($response->getContent()));
    }

    public function test_saas_platform_can_deploy_an_existing_artifact_without_rebuilding_it(): void
    {
        $this->assertExistingArtifactDeploymentFor('saas-platform', str_repeat('4', 40), '/var/www/saas-platform');
    }

    /** @return array{User, Project, LocalProjectMapping} */
    private function verifiedProject(string $projectKey, string $sha, ?string $productionPath = null): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'name' => $projectKey,
            'project_key' => $projectKey,
            'repository' => "acme/{$projectKey}",
            'branch' => 'main',
            'requires_complete_artifact' => true,
        ]);
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'local_path' => $productionPath ?? "/opt/deployment-agent/workspaces/{$projectKey}",
            'source_workspace_path' => "/opt/deployment-agent/workspaces/{$projectKey}",
            'production_path' => $productionPath,
            'git_branch' => 'main',
            'local_head_sha' => $sha,
            'remote_tracking_sha' => $sha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);

        return [$user, $project, $mapping];
    }

    private function artifactSelectorHtml(string $html): string
    {
        $start = strpos($html, 'id="artifact-commit-selector"');
        $end = strpos($html, '</form>', $start);

        return substr($html, $start, $end - $start);
    }

    private function assertExistingArtifactDeploymentFor(string $projectKey, string $sha, string $productionPath): void
    {
        [$user, $project, $mapping] = $this->verifiedProject($projectKey, $sha, $productionPath);
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => $sha,
            'artifact_action' => 'use_existing',
        ])->assertRedirect();

        $this->assertSame($artifact->id, Deployment::sole()->artifact_id);
        $this->assertDatabaseCount('publish_jobs', 0);
    }
}
