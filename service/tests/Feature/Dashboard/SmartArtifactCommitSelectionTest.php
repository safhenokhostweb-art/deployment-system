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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmartArtifactCommitSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('artifacts');
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

    public function test_latest_mode_ignores_submitted_sha_and_targets_reported_remote_head(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $mapping->update(['remote_tracking_sha' => str_repeat('c', 40)]);

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('b', 40),
        ])->assertRedirect();

        $this->assertSame(str_repeat('c', 40), PublishJob::sole()->reviewed_head_sha);
    }

    public function test_remote_main_newer_than_workspace_without_exact_artifact_enables_build(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $remote = str_repeat('b', 40);
        $mapping->update(['remote_tracking_sha' => $remote]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk()->assertSee($remote);
        $this->assertSelectorTargets($response->getContent(), $remote);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertDeployLatestDisabled($response->getContent());
        $this->assertDatabaseCount('publish_jobs', 0);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_valid_artifact_for_older_commit_does_not_shadow_new_remote_head(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $remote = str_repeat('b', 40);
        $mapping->update(['remote_tracking_sha' => $remote]);
        $olderArtifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Deployment::STATUS_SUCCEEDED,
            'artifact_id' => $olderArtifact->id,
        ]);
        PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $mapping->local_agent_id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'reviewed_head_sha' => str_repeat('a', 40),
            'result_commit_hash' => str_repeat('a', 40),
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $this->assertSelectorTargets($response->getContent(), $remote);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertExistingArtifactIndicatorHidden($response->getContent());
        $this->assertDatabaseCount('deployments', 1);
        $this->assertDatabaseCount('publish_jobs', 1);
    }

    public function test_missing_physical_file_for_exact_artifact_keeps_build_enabled(): void
    {
        [$user, $project] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertDeployLatestDisabled($response->getContent());
        $this->assertExistingArtifactIndicatorHidden($response->getContent());
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

        $response = $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(str_repeat('b', 40))
            ->assertSee(__('ui.publish.github_workspace_mismatch'));

        $this->assertSelectorTargets($response->getContent(), str_repeat('b', 40));
        $this->assertBuildButtonEnabled($response->getContent());
    }

    public function test_existing_available_artifact_creates_a_new_deployment_with_the_artifact_and_no_build_job(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('c', 40));
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('c', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertSee(__('ui.publish.artifact_already_exists'))
            ->assertSee(__('ui.publish.use_existing_artifact'));

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $this->assertBuildButtonDisabled($response->getContent());
        $this->assertDeployLatestEnabled($response->getContent());

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee(str_repeat('c', 40))
            ->assertSee('#'.$artifact->id);
        $this->assertDatabaseCount('deployments', 0);

        $response = $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => str_repeat('c', 40),
            'artifact_action' => 'use_existing',
        ]);

        $response->assertRedirect(route('projects.deploy-latest.show', $project));
        $this->assertDatabaseCount('deployments', 0);
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
        $artifact = Artifact::factory()->physicallyAvailable()->create([
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

        $this->assertSame(Deployment::STATUS_FAILED, $historical->fresh()->status);
        $this->assertNull($historical->fresh()->artifact_id);
        $this->assertDatabaseCount('deployments', 1);
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

    public function test_customer_site_and_saas_platform_share_remote_head_selection_logic(): void
    {
        foreach (['customer-site', 'saas-platform'] as $projectKey) {
            [, $project, $mapping] = $this->verifiedProject($projectKey, str_repeat('a', 40));
            $mapping->update(['remote_tracking_sha' => str_repeat('b', 40)]);

            $option = app(\App\Services\Publish\ArtifactCommitSelector::class)->options($project->fresh()->load('localProjectMappings.localAgent'))[0];

            $this->assertSame(str_repeat('b', 40), $option['remote_sha']);
            $this->assertTrue($option['is_build_ready']);
            $this->assertNull($option['existing_artifact_id']);
        }
    }

    public function test_deploy_latest_rejects_older_artifact_and_get_is_side_effect_free(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $mapping->update(['remote_tracking_sha' => str_repeat('b', 40)]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee(str_repeat('b', 40))
            ->assertSee('NOT READY');

        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('publish_jobs', 0);

        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertStatus(409);
        $this->assertDatabaseCount('deployments', 0);
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

    public function test_production_like_mapping_is_never_presented_as_a_verified_source(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('6', 40));
        $mapping->update([
            'local_path' => '/var/www/customer-site',
            'source_workspace_path' => '/var/www/customer-site',
            'production_path' => '/var/www/customer-site',
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $selector = $this->artifactSelectorHtml($response->getContent());

        $this->assertStringNotContainsString('/var/www/customer-site', $selector);
        $this->assertStringContainsString('production-like', $selector);
    }

    public function test_saas_platform_can_deploy_an_existing_artifact_without_rebuilding_it(): void
    {
        $this->assertExistingArtifactDeploymentFor('saas-platform', str_repeat('4', 40), '/var/www/saas-platform');
    }

    public function test_required_project_shows_roles_but_no_ambiguous_agent_selector(): void
    {
        [$user, $project] = $this->verifiedProject('customer-site', str_repeat('5', 40));
        $development = LocalAgent::factory()->approved()->create([
            'name' => 'DESKTOP-DEVELOPMENT',
            'last_seen_at' => now(),
        ]);
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $development->id,
            'project_id' => $project->id,
            'local_path' => 'C:\\workspaces\\customer-site',
            'source_workspace_path' => 'C:\\workspaces\\customer-site',
            'git_branch' => 'main',
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk()
            ->assertSee('Authoritative Build Agent')
            ->assertSee(LocalAgent::ROLE_PRODUCTION_BUILD)
            ->assertSee(LocalAgent::ROLE_DEVELOPMENT)
            ->assertDontSee('<select name="local_project_mapping_id"', false);
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
        $agent = LocalAgent::factory()->productionBuild()->create();
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
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        return [$user, $project, $mapping];
    }

    private function artifactSelectorHtml(string $html): string
    {
        $start = strpos($html, 'id="artifact-commit-selector"');
        $end = strpos($html, '</form>', $start);

        return substr($html, $start, $end - $start);
    }

    private function assertSelectorTargets(string $html, string $sha): void
    {
        $this->assertStringContainsString('id="commit-hash" value="'.$sha.'"', $html);
    }

    private function assertExistingArtifactIndicatorHidden(string $html): void
    {
        $this->assertMatchesRegularExpression('/<p[^>]*id="artifact-existing"[^>]*style="[^"]*display:none[^"]*"/', $html);
    }

    private function assertBuildButtonEnabled(string $html): void
    {
        $this->assertButtonDisabledState($html, 'artifact-build-button', false);
    }

    private function assertBuildButtonDisabled(string $html): void
    {
        $this->assertButtonDisabledState($html, 'artifact-build-button', true);
    }

    private function assertDeployLatestEnabled(string $html): void
    {
        $this->assertMatchesRegularExpression('/<a[^>]*id="artifact-deploy-latest"[^>]*>/', $html);
    }

    private function assertDeployLatestDisabled(string $html): void
    {
        // Readiness review remains available while an Artifact is missing;
        // only the confirmation action on that page is disabled.
        $this->assertMatchesRegularExpression('/<a[^>]*id="artifact-deploy-latest"[^>]*>/', $html);
    }

    private function assertButtonDisabledState(string $html, string $id, bool $disabled): void
    {
        $matched = preg_match('/<button[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $html, $matches);
        $this->assertSame(1, $matched, "Button {$id} was not rendered.");
        $disabled
            ? $this->assertStringContainsString('disabled', $matches[0])
            : $this->assertStringNotContainsString('disabled', $matches[0]);
    }

    private function assertExistingArtifactDeploymentFor(string $projectKey, string $sha, string $productionPath): void
    {
        [$user, $project, $mapping] = $this->verifiedProject($projectKey, $sha, $productionPath);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
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

        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('publish_jobs', 0);
    }
}
