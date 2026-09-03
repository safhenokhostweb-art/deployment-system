<?php

namespace Tests\Feature\Dashboard;

use App\Models\AgentJob;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeployLatestVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('artifacts');
    }

    public function test_ready_exact_commit_evidence_displays_passed_without_creating_a_deployment(): void
    {
        [$user, $project, $release] = $this->reviewFixture([
            'release_ready' => true,
            'test_results' => [
                ['name' => 'source_provenance', 'status' => 'passed'],
                ['name' => 'php_syntax', 'status' => 'passed'],
                ['name' => 'build_pipeline', 'status' => 'passed'],
                ['name' => 'artifact_manifest', 'status' => 'passed'],
            ],
            'build_result' => [
                'required' => true,
                'status' => 'passed',
                'contract' => 'hub-exact-commit-evidence-v1',
            ],
            'evidence_digest' => str_repeat('d', 64),
        ], Release::STATUS_READY);

        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $project->authoritative_build_local_agent_id,
            'release_id' => $release->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'reviewed_head_sha' => self::SHA,
            'reviewed_branch' => 'main',
            'evidence_verified_at' => now(),
            'evidence_digest' => str_repeat('d', 64),
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'publish_job_id' => $job->id,
            'commit_hash' => self::SHA,
            'branch' => 'main',
            'semantic_status' => 'verified',
            'semantic_verified_at' => now(),
        ]);

        $this->assertDatabaseCount('deployments', 0);

        $response = $this->actingAs($user)->get(route('projects.deploy-latest.show', $project));

        $response->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSeeInOrder(['Build verification', 'Passed'])
            ->assertDontSee('Tests')
            ->assertDontSee('Build verification</dt><dd>Failed', false)
            ->assertSee('Create deployment candidate');
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_failed_required_build_evidence_displays_failed_and_is_not_deployable(): void
    {
        [$user, $project] = $this->reviewFixture([
            'release_ready' => false,
            'risk_flags' => ['TEST_FAILURE'],
            'build_result' => [
                'required' => true,
                'status' => 'failed',
                'contract' => 'hub-exact-commit-evidence-v1',
            ],
        ], Release::STATUS_FAILED);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => self::SHA,
            'branch' => 'main',
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('TEST FAILURE')
            ->assertSeeInOrder(['Build verification', 'Failed'])
            ->assertDontSee('Create deployment candidate');
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_non_required_and_missing_build_evidence_do_not_display_false_failure(): void
    {
        [$user, $project, $release] = $this->reviewFixture([
            'release_ready' => false,
            'build_result' => ['required' => false, 'status' => 'pending'],
        ], Release::STATUS_AWAITING_ARTIFACT);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSeeInOrder(['Build verification', 'Not required'])
            ->assertDontSee('Build verification</dt><dd>Failed', false);

        $metadata = $release->metadata;
        unset($metadata['build_result']);
        $release->update(['metadata' => $metadata]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSeeInOrder(['Build verification', 'Not available'])
            ->assertDontSee('Build verification</dt><dd>Failed', false);
        $this->assertDatabaseCount('deployments', 0);
    }

    // --------------------------------------------------------------
    // Root-cause regression suite (deploy-latest stale-commit bug):
    // PublishController::latestRemoteArtifact() must resolve the CURRENT
    // Release (ReleaseRecoveryEligibility::currentReleaseFor() — the
    // newest Release row for project+branch) FIRST, then its exact
    // Artifact, and must never consult GitHub HEAD or a Local Agent's
    // self-reported remote_tracking_sha as a selector, never fall back
    // to an older ready Release/Artifact, and never let a release-less
    // historical Deployment/Artifact override a modern current Release.
    // --------------------------------------------------------------

    public function test_stale_agent_reported_sha_older_than_current_release_does_not_affect_resolution(): void
    {
        $staleSha = str_repeat('9', 40);
        $currentSha = str_repeat('1', 40);
        [$user, $project] = $this->projectWithMapping($staleSha);

        $release = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee($currentSha)
            ->assertDontSee($staleSha);
    }

    public function test_current_release_wins_even_with_github_unconfigured_and_a_stale_reported_sha(): void
    {
        $staleSha = str_repeat('9', 40);
        $currentSha = str_repeat('1', 40);
        [$user, $project] = $this->projectWithMapping($staleSha);
        // No GitHubAccount is attached to $project at all here — the
        // exact "GitHub unavailable/unconfigured" condition under which
        // the old resolver silently fell back to the agent's stale
        // remote_tracking_sha and surfaced an old Release/Artifact.
        $this->assertNull($project->githubAccount);

        $release = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee($currentSha);
    }

    public function test_older_available_artifact_for_a_superseded_release_is_never_selected(): void
    {
        $olderSha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $olderRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $olderSha]
        ));
        $olderArtifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $olderRelease->id,
            'commit_hash' => $olderSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $currentRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));
        $currentArtifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $currentRelease->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('Artifact</dt><dd>#'.$currentArtifact->id.' (', false)
            ->assertDontSee('Artifact</dt><dd>#'.$olderArtifact->id.' (', false);
    }

    public function test_older_successful_deployment_does_not_override_current_release(): void
    {
        $olderSha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $olderArtifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $olderSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'branch' => 'main',
            'commit_hash' => $olderSha,
            'status' => Deployment::STATUS_SUCCEEDED,
            'artifact_id' => $olderArtifact->id,
        ]);

        $currentRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $currentRelease->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee($currentSha)
            ->assertDontSee($olderSha);
    }

    public function test_current_release_awaiting_artifact_fails_closed_even_when_an_older_release_is_ready(): void
    {
        $olderSha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $olderRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $olderSha]
        ));
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
            'commit_hash' => $currentSha,
            'status' => Release::STATUS_AWAITING_ARTIFACT,
            'metadata' => ['release_ready' => false],
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertDontSee('Create deployment candidate')
            ->assertDontSee($olderSha);

        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertStatus(409);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_current_release_failed_fails_closed_even_when_an_older_release_is_ready(): void
    {
        $olderSha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $olderRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $olderSha]
        ));
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
            'commit_hash' => $currentSha,
            'status' => Release::STATUS_FAILED,
            'metadata' => ['release_ready' => false],
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertDontSee('Create deployment candidate')
            ->assertDontSee($olderSha);

        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertStatus(409);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_current_release_ready_without_its_exact_artifact_fails_closed_with_no_older_artifact_fallback(): void
    {
        $olderSha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $olderRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $olderSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $olderRelease->id,
            'commit_hash' => $olderSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        // Current Release is ready, but no Artifact was ever built for
        // its exact commit.
        Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('NOT READY')
            ->assertDontSee('Create deployment candidate')
            ->assertDontSee($olderSha);

        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertStatus(409);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_releases_on_other_branches_never_participate(): void
    {
        $mainSha = str_repeat('2', 40);
        $developSha = str_repeat('3', 40);
        [$user, $project] = $this->projectWithMapping($mainSha);

        $mainRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $mainSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $mainRelease->id,
            'commit_hash' => $mainSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        // Created AFTER (higher id than) the main-branch Release above —
        // a naive "highest id overall" query would wrongly pick this one.
        Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'develop', 'commit_hash' => $developSha]
        ));

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee($mainSha)
            ->assertDontSee($developSha);
    }

    public function test_release_less_historical_deployment_and_artifact_never_override_current_release(): void
    {
        $legacySha = str_repeat('1', 40);
        $currentSha = str_repeat('2', 40);
        [$user, $project] = $this->projectWithMapping($currentSha);

        $legacyArtifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => null,
            'commit_hash' => $legacySha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'branch' => 'main',
            'commit_hash' => $legacySha,
            'status' => Deployment::STATUS_SUCCEEDED,
            'release_id' => null,
            'artifact_id' => $legacyArtifact->id,
        ]);

        $currentRelease = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $currentSha]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $currentRelease->id,
            'commit_hash' => $currentSha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee($currentSha)
            ->assertDontSee($legacySha);
    }

    public function test_candidate_creation_persists_exact_reviewed_release_artifact_and_commit_and_remains_pending_approval(): void
    {
        [$user, $project, $release, $artifact] = $this->readyReleaseWithEvidence();

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()->assertSee('READY TO DEPLOY');
        $this->assertDatabaseCount('deployments', 0);

        $response = $this->actingAs($user)->post(route('projects.deploy-latest', $project), [
            'reviewed_release_id' => $release->id,
        ]);
        $response->assertRedirect();

        $deployment = Deployment::sole();
        $this->assertSame($release->id, $deployment->release_id);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame(self::SHA, $deployment->commit_hash);
        $this->assertSame('main', $deployment->branch);

        // NO CONFIRM -> NO DEPLOYMENT: a candidate for a modern
        // (non-legacy-fallback) Release stops at pending_approval with
        // nothing claimable yet, until DeploymentDispatcher::approve() is
        // called explicitly.
        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertNull($deployment->started_at);
        $this->assertNull($deployment->approved_at);
        $this->assertNull($deployment->queued_at);
        $this->assertSame(0, $deployment->tasks()->count());
        $this->assertSame(0, AgentJob::count());
    }

    public function test_post_fails_closed_when_the_reviewed_release_is_superseded_by_a_not_yet_ready_release_before_confirm(): void
    {
        [$user, $project, $releaseA, ] = $this->readyReleaseWithEvidence();

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()->assertSee('READY TO DEPLOY');

        // A newer push lands before the operator confirms; it has no
        // Artifact yet, exactly like the production incident's timeline.
        Release::factory()->create([
            'project_id' => $project->id,
            'branch' => 'main',
            'commit_hash' => str_repeat('b', 40),
            'status' => Release::STATUS_AWAITING_ARTIFACT,
            'metadata' => ['release_ready' => false],
        ]);

        // The POST still carries the release the operator actually
        // reviewed (A) — it must not create a candidate for it (stale)
        // and must fail closed rather than resolve anything else.
        $this->actingAs($user)->post(route('projects.deploy-latest', $project), [
            'reviewed_release_id' => $releaseA->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_post_fails_closed_when_a_newer_ready_release_was_never_reviewed(): void
    {
        [$user, $project, $releaseA, ] = $this->readyReleaseWithEvidence();

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()->assertSee('READY TO DEPLOY');

        $shaB = str_repeat('b', 40);
        $releaseB = Release::factory()->create(array_replace(
            $this->legacyReadyReleaseAttributes($user),
            ['project_id' => $project->id, 'branch' => 'main', 'commit_hash' => $shaB]
        ));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $releaseB->id,
            'commit_hash' => $shaB,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        // Release B is now current AND fully ready, but the operator
        // only ever reviewed A — confirming must not silently swap to B
        // (what was reviewed is what gets created, or nothing does).
        $this->actingAs($user)->post(route('projects.deploy-latest', $project), [
            'reviewed_release_id' => $releaseA->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('deployments', 0);
    }

    /** @return array{User, Project, LocalProjectMapping} */
    private function projectWithMapping(string $reportedSha): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'project_key' => 'deploy-latest-current-release',
            'repository' => 'acme/deploy-latest-current-release',
            'branch' => 'main',
            'requires_complete_artifact' => true,
        ]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'source_workspace_path' => 'C:\\workspaces\\deploy-latest-current-release',
            'git_branch' => 'main',
            'local_head_sha' => $reportedSha,
            'remote_tracking_sha' => $reportedSha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        return [$user, $project->fresh(), $mapping];
    }

    /** A Release ready via the legacy-fallback escape hatch — reaches READY TO DEPLOY without a full evidence chain, for tests only about selection (not confirmation-boundary) semantics. */
    private function legacyReadyReleaseAttributes(User $user): array
    {
        return [
            'status' => Release::STATUS_READY,
            'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
            'source' => 'legacy',
            'legacy_fallback_eligible_at' => now(),
            'legacy_fallback_reason' => 'test fixture',
            'legacy_fallback_eligible_by' => $user->email,
            'metadata' => ['release_ready' => true],
        ];
    }

    /** @return array{User, Project, Release, Artifact} */
    private function readyReleaseWithEvidence(): array
    {
        [$user, $project, $release] = $this->reviewFixture([
            'release_ready' => true,
            'test_results' => [
                ['name' => 'source_provenance', 'status' => 'passed'],
                ['name' => 'php_syntax', 'status' => 'passed'],
                ['name' => 'build_pipeline', 'status' => 'passed'],
                ['name' => 'artifact_manifest', 'status' => 'passed'],
            ],
            'build_result' => [
                'required' => true,
                'status' => 'passed',
                'contract' => 'hub-exact-commit-evidence-v1',
            ],
            'evidence_digest' => str_repeat('d', 64),
        ], Release::STATUS_READY);

        $job = PublishJob::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $project->authoritative_build_local_agent_id,
            'release_id' => $release->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'reviewed_head_sha' => self::SHA,
            'reviewed_branch' => 'main',
            'evidence_verified_at' => now(),
            'evidence_digest' => str_repeat('d', 64),
        ]);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'publish_job_id' => $job->id,
            'commit_hash' => self::SHA,
            'branch' => 'main',
            'semantic_status' => 'verified',
            'semantic_verified_at' => now(),
        ]);

        return [$user, $project, $release, $artifact];
    }

    /** @return array{User, Project, Release} */
    private function reviewFixture(array $metadataOverrides, string $releaseStatus): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'project_key' => 'deploy-latest-verification',
            'repository' => 'acme/deploy-latest-verification',
            'branch' => 'main',
            'requires_complete_artifact' => true,
        ]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'source_workspace_path' => 'C:\\workspaces\\deploy-latest-verification',
            'git_branch' => 'main',
            'local_head_sha' => self::SHA,
            'remote_tracking_sha' => self::SHA,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        $metadata = array_replace([
            'summary' => 'Production-shaped exact-commit release',
            'changed_files' => ['service/app/Example.php'],
            'migration_files' => [],
            'test_results' => [],
            'build_result' => ['required' => true, 'status' => 'pending'],
            'health_contract' => ['required' => true],
            'risk_flags' => [],
            'release_ready' => false,
        ], $metadataOverrides);
        $release = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => self::SHA,
            'branch' => 'main',
            'status' => $releaseStatus,
            'source' => 'webhook',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
            'metadata' => $metadata,
        ]);

        return [$user, $project->fresh(), $release];
    }
}
