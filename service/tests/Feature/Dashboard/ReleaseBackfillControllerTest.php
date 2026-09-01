<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artifact;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseBackfillControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '2222222222222222222222222222222222222222';
    private const HEAD = '3333333333333333333333333333333333333333';

    private const CHANGED_FILES = [
        'service/app/Services/Publish/ArtifactCommitSelector.php',
        'service/app/Services/Publish/AuthoritativeBuildAgentResolver.php',
        'service/tests/Feature/Dashboard/SmartArtifactCommitSelectionTest.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function githubProject(array $changedFiles = self::CHANGED_FILES, ?string $baseSha = self::BASE): Project
    {
        $account = GitHubAccount::factory()->create();
        $project = Project::factory()->create(['github_account_id' => $account->id, 'branch' => 'main']);

        $this->mock(GitHubAppClient::class, function ($mock) use ($baseSha, $changedFiles): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('createInstallationToken')->andReturn(['token' => 'tok', 'expires_at' => now()->addHour()]);
            $mock->shouldReceive('getBranchHead')->andReturn(self::HEAD);
            $mock->shouldReceive('getCommitParent')->andReturn($baseSha);
            if ($baseSha !== null) {
                $mock->shouldReceive('compareCommits')->andReturn($changedFiles);
            }
        });

        return $project;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    // 1. unauthorized user cannot backfill metadata
    public function test_non_admin_cannot_view_or_submit_backfill(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);

        $this->actingAs($developer)->get(route('projects.release-backfill.show', $project))->assertForbidden();
        $this->actingAs($developer)->post(route('projects.release-backfill.store', $project), ['confirm' => '1'])->assertForbidden();
        $this->assertDatabaseCount('releases', 0);
    }

    // 2. secret never rendered to browser
    public function test_webhook_secret_never_appears_in_the_backfill_page(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('projects.release-backfill.show', $project));

        $response->assertOk();
        $response->assertDontSee($project->github_webhook_secret);
    }

    // 3. existing commit + exact Artifact can enter backfill flow
    public function test_admin_sees_derived_commit_base_and_artifact_and_can_submit(): void
    {
        $project = $this->githubProject();
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('projects.release-backfill.show', $project));

        $response->assertOk()->assertSee(self::HEAD)->assertSee(self::BASE)->assertSee('#'.$artifact->id);
        foreach (self::CHANGED_FILES as $file) {
            $response->assertSee($file);
        }
    }

    // 4. wrong Artifact/commit pairing rejected
    public function test_artifact_for_a_different_commit_is_not_treated_as_available(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => str_repeat('9', 40), 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('projects.release-backfill.show', $project));

        $response->assertOk()->assertSee('No physically valid Artifact exists yet for the exact remote commit.');
        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('releases', 0);
    }

    // 5. invalid base SHA rejected
    public function test_commit_with_no_parent_blocks_submission(): void
    {
        $project = $this->githubProject(baseSha: null);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('projects.release-backfill.show', $project));
        $response->assertOk()->assertSee('base commit to diff against');

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('releases', 0);
    }

    // 6. arbitrary changed-file fabrication rejected where authoritative Git evidence exists
    public function test_submitted_changed_files_are_ignored_in_favor_of_the_github_derived_list(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
            // Not a real field the form exposes, but even if a request forged one:
            'changed_files' => ['fabricated/evil.php'],
        ])->assertRedirect();

        $release = Release::sole();
        $this->assertSame(self::CHANGED_FILES, $release->metadata['changed_files']);
        $this->assertNotContains('fabricated/evil.php', $release->metadata['changed_files']);
    }

    // 7. migration file detection correct
    public function test_migration_files_are_detected_and_required_check_appears(): void
    {
        $project = $this->githubProject(changedFiles: [
            'service/app/Services/Publish/ArtifactCommitSelector.php',
            'service/database/migrations/2026_01_01_000000_add_thing.php',
        ]);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('projects.release-backfill.show', $project));
        $response->assertOk()->assertSee('migration file(s)')->assertSee('migrations_reviewed');

        // Confirming tests/lint but NOT migrations_reviewed must not reach release_ready.
        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();

        $release = Release::sole();
        $this->assertFalse($release->metadata['release_ready']);
        $this->assertContains('MIGRATION_REVIEW_REQUIRED', $release->metadata['risk_flags']);
        $this->assertSame(['service/database/migrations/2026_01_01_000000_add_thing.php'], $release->metadata['migration_files']);
    }

    // 8. missing required test evidence keeps NOT READY
    public function test_no_confirmed_checks_keeps_release_not_ready(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
        ])->assertRedirect();

        $release = Release::sole();
        $this->assertFalse($release->metadata['release_ready']);
        $this->assertSame(Release::STATUS_FAILED, $release->status);
    }

    // 9. valid reviewed evidence can become READY (+ 13. Deploy Latest reflects it)
    public function test_all_confirmed_checks_make_the_release_ready_and_deploy_latest_ready_to_deploy(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $before = $this->actingAs($this->admin())->get(route('projects.deploy-latest.show', $project));
        $before->assertOk()->assertSee('NOT READY');

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect(route('projects.deploy-latest.show', $project));

        $release = Release::sole();
        $this->assertTrue($release->metadata['release_ready']);
        $this->assertSame(Release::STATUS_READY, $release->status);

        $after = $this->actingAs($this->admin())->get(route('projects.deploy-latest.show', $project));
        $after->assertOk()->assertSee('READY TO DEPLOY')->assertSee('Create deployment candidate');
    }

    // 10. metadata recording creates no Deployment
    public function test_recording_metadata_never_creates_a_deployment(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();

        $this->assertDatabaseCount('deployments', 0);
    }

    // 11. audit event recorded
    public function test_audit_log_entry_is_recorded(): void
    {
        $project = $this->githubProject();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'release.metadata_backfilled',
            'actor_identifier' => $admin->email,
        ]);
    }

    // Persistent workspace / artifact content are never touched by this flow.
    public function test_artifact_row_is_only_linked_never_mutated(): void
    {
        $project = $this->githubProject();
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $originalChecksum = $artifact->checksum_sha256;
        $originalSize = $artifact->size_bytes;

        $this->actingAs($this->admin())->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();

        $artifact->refresh();
        $this->assertSame($originalChecksum, $artifact->checksum_sha256);
        $this->assertSame($originalSize, $artifact->size_bytes);
        $this->assertSame(Release::sole()->id, $artifact->release_id);
    }
}
