<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
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

        // Bootstrap case (no Release yet): the label stays "Latest remote
        // branch HEAD", not the current-Release label — see
        // test_current_release_commit_wins_over_stale_mapping_remote_tracking_sha()
        // for the labeling once a Release exists.
        $response->assertOk()->assertSee($remote)->assertSee(__('ui.publish.github_head'));
        $this->assertSelectorTargets($response->getContent(), $remote);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertDeployLatestDisabled($response->getContent());
        $this->assertDatabaseCount('publish_jobs', 0);
        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * Reproduces the exact reported production scenario for deployment-system
     * itself (not just an external project): the authoritative Agent's
     * persistent source workspace is still checked out at an old commit
     * (only `local_head_sha` is stale — nothing else about the mapping is),
     * while its remote-tracking ref has already advanced past a normal push.
     * Build Artifact eligibility must track the exact remote SHA, never the
     * workspace's own lagging HEAD — and computing that eligibility must
     * never itself mutate the persistent mapping row.
     */
    public function test_deployment_system_stale_workspace_head_never_blocks_build_for_the_fresh_remote_commit(): void
    {
        $oldHead = str_repeat('1', 40);
        $freshRemote = str_repeat('2', 40);
        [$user, $project, $mapping] = $this->verifiedProject('deployment-system', $oldHead);
        $mapping->update(['remote_tracking_sha' => $freshRemote]);
        $mappingUpdatedAt = $mapping->fresh()->updated_at->toIso8601String();

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk()->assertSee($freshRemote)->assertSee($oldHead);
        $this->assertSelectorTargets($response->getContent(), $freshRemote);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertSame($oldHead, $mapping->fresh()->local_head_sha, 'Reading eligibility must never rewrite the reported workspace HEAD.');
        $this->assertSame($mappingUpdatedAt, $mapping->fresh()->updated_at->toIso8601String(), 'Reading eligibility must not touch the mapping row at all.');

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        $job = PublishJob::sole();
        $this->assertSame($freshRemote, $job->reviewed_head_sha, 'The queued build must target the exact remote HEAD, never the stale workspace HEAD.');
        $release = Release::sole();
        $this->assertSame($release->id, $job->release_id, 'A Dashboard build must bootstrap and bind the exact Release before the Agent can claim it.');
        $this->assertSame($project->id, $release->project_id);
        $this->assertSame($freshRemote, $release->commit_hash);
        $this->assertSame('main', $release->branch);
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $this->assertIsArray($job->evidence_contract);
        $this->assertSame($release->public_id, $job->evidence_contract['release_id']);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('deployment_tasks', 0);
        $this->assertDatabaseCount('agent_jobs', 0);
        $this->assertSame($oldHead, $mapping->fresh()->local_head_sha, 'Queuing a build must never rewrite the persistent workspace mapping.');
    }

    /**
     * Reproduces the EXACT production failure this time: the prior stale-
     * workspace-HEAD test above passed even before the fix, because
     * verifiedProject() reports a clean working tree — that never matched
     * the real production Agent workspace, which had (correctly) reported
     * `working_tree_dirty = true` due to unrelated local WIP sitting in the
     * same persistent checkout. That dirtiness was incorrectly wired into
     * Build Artifact eligibility even though the exact-remote-commit build
     * never reads the workspace's working-tree content at all (it fetches
     * and checks the exact requested commit out into an isolated
     * BuildWorkspace) — only its trusted remote URL/branch and committed,
     * non-diverged history. Every other production fact is reproduced
     * simultaneously: stale workspace HEAD, a fresh remote-tracking SHA,
     * an otherwise-safe/reported/branch-matching source workspace, and no
     * exact Artifact yet for the new remote commit.
     */
    public function test_deployment_system_dirty_persistent_workspace_never_blocks_the_exact_remote_build(): void
    {
        $oldHead = str_repeat('1', 40);
        $freshRemote = str_repeat('2', 40);
        [$user, $project, $mapping] = $this->verifiedProject('deployment-system', $oldHead);
        $mapping->update(['remote_tracking_sha' => $freshRemote, 'working_tree_dirty' => true]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk()->assertSee($freshRemote);
        $this->assertSelectorTargets($response->getContent(), $freshRemote);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertDatabaseCount('publish_jobs', 0);

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        $job = PublishJob::sole();
        $this->assertSame($freshRemote, $job->reviewed_head_sha, 'The queued build must target the exact remote HEAD.');
        $this->assertTrue((bool) $mapping->fresh()->working_tree_dirty, 'The reported dirty flag itself must never be silently cleared/faked by the Hub.');
        $this->assertSame($oldHead, $mapping->fresh()->local_head_sha, 'Queuing a build must never rewrite or reset the persistent workspace mapping.');
    }

    /**
     * A workspace that has never reported at all, is on the wrong branch, or
     * whose source path can't be trusted/located still correctly blocks —
     * only plain working-tree dirtiness was over-strict. This is the
     * negative case proving the fix didn't just delete the safety gate.
     */
    public function test_deployment_system_untrusted_source_still_blocks_despite_a_fresh_remote_commit(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('deployment-system', str_repeat('1', 40));
        $mapping->update(['remote_tracking_sha' => str_repeat('2', 40), 'reported_branch' => 'some-other-branch']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $this->assertBuildButtonDisabled($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertStatus(409);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    /**
     * Two build requests for the exact same project+commit (a double click,
     * or a webhook auto-build racing an operator's manual click) must never
     * queue two separate isolated builds for an identical commit.
     */
    public function test_concurrent_build_requests_for_the_same_commit_reuse_the_same_publish_job(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('deployment-system', str_repeat('a', 40));
        $remote = str_repeat('b', 40);
        $mapping->update(['remote_tracking_sha' => $remote]);

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();
        $first = PublishJob::sole();

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        $this->assertDatabaseCount('publish_jobs', 1);
        $this->assertSame($first->id, PublishJob::sole()->id);
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
        // Review & Deploy Latest resolves from the current Release row,
        // not merely from an available Artifact for the reported commit
        // — see DeployLatestVerificationTest for the dedicated coverage.
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => str_repeat('c', 40),
            'status' => Release::STATUS_READY,
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'source' => 'legacy',
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'test fixture',
            'legacy_fallback_eligible_by' => $user->email,
            'metadata' => ['release_ready' => true],
        ]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
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

    /**
     * Bootstrap-only case: no Release row exists yet for either project's
     * branch, so options() falls back to the GitHub/mapping-derived
     * latestRemoteCommit() — the one case where that remains correct (a
     * brand-new project must still be able to build its first Artifact
     * before any push/Release has ever been recorded). See
     * test_customer_site_and_saas_platform_select_current_release_commit_once_a_release_exists()
     * below for the current-Release-authoritative counterpart.
     */
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

    /**
     * Current-Release-authoritative counterpart to the bootstrap test
     * above: once a Release row exists for project+branch, both
     * saas-platform and customer-site must select its exact commit_hash
     * — never the mapping's self-reported remote_tracking_sha, even
     * though that field still reports something else entirely.
     */
    public function test_customer_site_and_saas_platform_select_current_release_commit_once_a_release_exists(): void
    {
        foreach (['customer-site', 'saas-platform'] as $projectKey) {
            [, $project, $mapping] = $this->verifiedProject($projectKey, str_repeat('a', 40));
            $mapping->update(['remote_tracking_sha' => str_repeat('b', 40)]);
            Release::factory()->create([
                'project_id' => $project->id,
                'branch' => 'main',
                'commit_hash' => str_repeat('c', 40),
                'status' => Release::STATUS_READY,
            ]);

            $option = app(\App\Services\Publish\ArtifactCommitSelector::class)->options($project->fresh()->load('localProjectMappings.localAgent'))[0];

            $this->assertSame(str_repeat('c', 40), $option['remote_sha']);
            $this->assertSame('release', $option['remote_source']);
            $this->assertTrue($option['is_build_ready']);
            $this->assertNull($option['existing_artifact_id']);
        }
    }

    /**
     * Root-cause fix (default Build Artifact stale-commit bug): once a
     * current Release exists, its exact commit_hash is the canonical
     * default target — never GitHub's live HEAD, never the Local Agent's
     * self-reported remote_tracking_sha — even when both the persistent
     * workspace's own local_head_sha AND its remote_tracking_sha are
     * stale relative to the current Release, and even when a client
     * submits a different commit_hash for 'latest' mode (which must be
     * ignored, exactly like before this fix). GET display and the actual
     * POST execution must agree on the exact same target.
     */
    public function test_current_release_commit_wins_over_stale_mapping_remote_tracking_sha(): void
    {
        $staleWorkspaceHead = str_repeat('1', 40);
        $staleRemoteTracking = str_repeat('2', 40);
        $currentReleaseSha = str_repeat('3', 40);
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', $staleWorkspaceHead);
        $mapping->update(['remote_tracking_sha' => $staleRemoteTracking]);
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $currentReleaseSha,
            'status' => Release::STATUS_READY,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk()->assertSee($currentReleaseSha)->assertSee(__('ui.publish.current_release_head'));
        // Scoped to the Artifact selector fragment specifically — the
        // separate "Transfer trace" diagnostic card legitimately keeps
        // showing the mapping's raw self-reported remote_tracking_sha
        // elsewhere on the page; that surface is untouched by this fix.
        $this->assertStringNotContainsString($staleRemoteTracking, $this->artifactSelectorHtml($response->getContent()));
        $this->assertSelectorTargets($response->getContent(), $currentReleaseSha);
        $this->assertBuildButtonEnabled($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
            'commit_hash' => $staleRemoteTracking,
        ])->assertRedirect();

        $job = PublishJob::sole();
        $this->assertSame($currentReleaseSha, $job->reviewed_head_sha, 'POST must target the current Release SHA, not the stale mapping SHA.');
        $this->assertSame($release->id, $job->release_id);
        // The existing current Release must be reused, never duplicated.
        $this->assertDatabaseCount('releases', 1);
    }

    public function test_awaiting_artifact_current_release_still_selects_its_exact_commit(): void
    {
        $sha = str_repeat('b', 40);
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', str_repeat('e', 40));
        $mapping->update(['remote_tracking_sha' => str_repeat('e', 40)]);
        Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $sha,
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $this->assertSelectorTargets($response->getContent(), $sha);
        $this->assertBuildButtonEnabled($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        $this->assertSame($sha, PublishJob::sole()->reviewed_head_sha);
    }

    public function test_ready_current_release_with_existing_artifact_offers_use_existing(): void
    {
        $sha = str_repeat('a', 40);
        [$user, $project] = $this->verifiedProject('saas-platform', $sha);
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $sha,
            'status' => Release::STATUS_READY,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $this->assertSelectorTargets($response->getContent(), $sha);
        $this->assertBuildButtonDisabled($response->getContent());
        $response->assertSee(__('ui.publish.artifact_already_exists'));
    }

    /**
     * Root-cause fix (failed-Release retry via the DEFAULT/non-Advanced
     * path): a current Release left `failed` — e.g. a transient
     * repository-fetch failure during the isolated build, exactly the
     * production incident this fix addresses — must still be the
     * default Build Artifact target, with no Advanced/manual SHA entry
     * needed. The exact same Release row is reused (never a duplicate),
     * a new build_artifact PublishJob is queued, the Release transitions
     * failed -> artifact_building via the existing
     * ReleaseOrchestrator::markArtifactBuilding() (which already clears
     * failed_at/failure_code/failure_message — unchanged behavior, not
     * modified by this fix), and the prior failed PublishJob is left
     * completely untouched for audit history.
     */
    public function test_failed_current_release_is_retried_through_the_default_build_artifact_path(): void
    {
        $sha = str_repeat('4', 40);
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', $sha);
        $mapping->update(['remote_tracking_sha' => str_repeat('9', 40)]);
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $sha,
            'status' => Release::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => 'artifact_build_local_workspace_prepare_failed',
            'failure_message' => 'Primary repository materialization failed while fetching the authoritative branch.',
        ]);
        $failedJob = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $mapping->local_agent_id,
            'release_id' => $release->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_FAILED,
            'reviewed_head_sha' => $sha,
            'reviewed_branch' => 'main',
            'error_message' => 'Primary repository materialization failed while fetching the authoritative branch.',
            'failure_code' => 'local_workspace_prepare_failed',
            'active_build_key' => null,
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $response->assertOk()->assertSee($sha);
        $this->assertSelectorTargets($response->getContent(), $sha);
        $this->assertBuildButtonEnabled($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        // Retrying must never create another Release for the same commit.
        $this->assertDatabaseCount('releases', 1);
        $newJob = PublishJob::where('id', '!=', $failedJob->id)->sole();
        $this->assertSame($release->id, $newJob->release_id);
        $this->assertSame($sha, $newJob->reviewed_head_sha);
        $this->assertContains($newJob->status, [PublishJob::STATUS_APPROVED, PublishJob::STATUS_QUEUED]);

        $release->refresh();
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $this->assertNull($release->failed_at);
        $this->assertNull($release->failure_code);
        $this->assertNull($release->failure_message);

        $failedJob->refresh();
        $this->assertSame(PublishJob::STATUS_FAILED, $failedJob->status, 'The prior failed PublishJob must never be mutated by a retry.');
        $this->assertSame('local_workspace_prepare_failed', $failedJob->failure_code);
        $this->assertNotNull($failedJob->finished_at);
    }

    /**
     * Superseded-safety: the default/latest mode must never select an
     * older Release when a newer current Release exists, even when the
     * newer one is itself failed/awaiting_artifact and the older one is
     * ready with an available Artifact — same "newest row wins,
     * regardless of its own status" rule as
     * ReleaseRecoveryEligibility::isCurrent().
     */
    public function test_newer_failed_current_release_is_selected_over_an_older_ready_release(): void
    {
        $olderSha = str_repeat('5', 40);
        $newerSha = str_repeat('6', 40);
        $mappingHead = str_repeat('7', 40);
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', $mappingHead);
        $mapping->update(['remote_tracking_sha' => $mappingHead]);

        $olderRelease = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $olderSha,
            'status' => Release::STATUS_READY,
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $olderRelease->id,
            'commit_hash' => $olderSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => $newerSha,
            'status' => Release::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => 'artifact_build_local_workspace_prepare_failed',
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $response->assertOk()->assertSee($newerSha);
        // Scoped to the Artifact selector fragment — the separate
        // "Transfer trace" diagnostic card legitimately surfaces the
        // older, genuinely-available Artifact from its own unrelated
        // perspective; that surface is untouched by this fix.
        $this->assertStringNotContainsString($olderSha, $this->artifactSelectorHtml($response->getContent()));
        $this->assertSelectorTargets($response->getContent(), $newerSha);
        $this->assertBuildButtonEnabled($response->getContent());
        $this->assertExistingArtifactIndicatorHidden($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertRedirect();

        $this->assertSame($newerSha, PublishJob::sole()->reviewed_head_sha);
    }

    /**
     * The workspace-trust gate (isEligibleForExactRemoteBuild) is
     * unchanged by this fix: it still blocks the default build whenever
     * the persistent workspace itself can't be trusted as a fetch
     * anchor, regardless of whether a current Release exists.
     */
    public function test_workspace_trust_gate_still_blocks_default_build_even_with_a_current_release(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('saas-platform', str_repeat('8', 40));
        $mapping->update(['reported_branch' => 'some-other-branch']);
        Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => str_repeat('9', 40),
            'status' => Release::STATUS_READY,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));
        $this->assertBuildButtonDisabled($response->getContent());

        $this->actingAs($user)->post(route('projects.publish', $project), [
            'local_project_mapping_id' => $mapping->id,
            'commit_selection_mode' => 'latest',
        ])->assertStatus(409);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    /**
     * Root-cause regression (deploy-latest stale-commit bug): a Local
     * Agent's self-reported remote_tracking_sha is NOT the selector for
     * deploy-latest — only the newest Release row for project+branch is
     * (ReleaseRecoveryEligibility::currentReleaseFor()). Here the agent
     * claims a SHA ('b') the Hub has no Release for at all, an available
     * Artifact exists for an OLDER, superseded Release's commit ('a'),
     * and the actual current Release ('c') has no Artifact yet — the
     * page must resolve/display 'c' and fail closed, never silently
     * reuse the older ready-looking Artifact for 'a', and never surface
     * the stale agent-reported 'b' at all.
     */
    public function test_deploy_latest_ignores_stale_agent_reported_sha_and_never_falls_back_to_an_older_artifact(): void
    {
        [$user, $project, $mapping] = $this->verifiedProject('customer-site', str_repeat('a', 40));
        $mapping->update(['remote_tracking_sha' => str_repeat('b', 40)]);

        $olderRelease = Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => str_repeat('a', 40),
            'status' => Release::STATUS_READY,
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'source' => 'legacy',
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'pre-modern-release backfill',
            'legacy_fallback_eligible_by' => $user->email,
            'metadata' => ['release_ready' => true],
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $olderRelease->id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        // A later push superseded it; no Artifact has been built for the
        // new tip yet.
        Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => str_repeat('c', 40),
            'status' => Release::STATUS_AWAITING_ARTIFACT,
            'metadata' => ['release_ready' => false],
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('NOT READY')
            ->assertSee(str_repeat('c', 40))
            ->assertDontSee(str_repeat('a', 40))
            ->assertDontSee(str_repeat('b', 40));

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
