<?php

namespace Tests\Feature\Releases;

use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\Artifact;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Release;
use App\Services\GitHub\GitHubAppClient;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseGitFactsResolver;
use App\Services\Releases\ReleaseOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause coverage for the bootstrap deadlock: every push already
 * created a Release, but nothing ever recorded WHY it wasn't ready — only
 * an explicit signed POST or admin backfill ever populated real metadata.
 * This locks in that a push now automatically records the FACTS it can
 * honestly know (never evidence, never release_ready) the moment the
 * webhook is processed, using infrastructure that's always already
 * deployed.
 */
class WebhookFactualMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '4444444444444444444444444444444444444444';
    private const HEAD = '5555555555555555555555555555555555555555';

    private const CHANGED_FILES = [
        'service/app/Services/Releases/ReleaseGitFactsResolver.php',
        'service/app/Services/Releases/ReleaseOrchestrator.php',
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

    private function dispatch(Project $project, string $commitHash): void
    {
        (new ProcessGitHubWebhookPush($project, $commitHash, 'main', 'octocat'))->handle(
            app(ReleaseOrchestrator::class),
            app(ReleaseGitFactsResolver::class),
            app(AuthoritativeBuildAgentResolver::class),
            app(PublishJobService::class),
        );
    }

    // 1. pushed commit automatically obtains the factual metadata required
    // 3. exact changed-files/base SHA are authoritative
    public function test_push_automatically_records_factual_metadata_without_marking_ready(): void
    {
        $project = $this->githubProject();

        $this->dispatch($project, self::HEAD);

        $release = Release::sole();
        $this->assertSame(self::BASE, $release->metadata['base_sha']);
        $this->assertSame(self::HEAD, $release->metadata['commit_sha']);
        $this->assertSame(self::CHANGED_FILES, $release->metadata['changed_files']);
        $this->assertSame('main', $release->metadata['branch']);
        $this->assertFalse($release->metadata['release_ready'], 'A push alone must never mark a release ready — it supplies facts, not evidence.');
        $this->assertSame([], $release->metadata['test_results'], 'No test evidence exists yet — it must not be fabricated.');
    }

    // 2. retries are idempotent
    public function test_duplicate_webhook_processing_does_not_duplicate_or_change_the_release(): void
    {
        $project = $this->githubProject();

        $this->dispatch($project, self::HEAD);
        $firstMetadata = Release::sole()->metadata;

        $this->dispatch($project, self::HEAD);

        $this->assertDatabaseCount('releases', 1);
        $this->assertSame($firstMetadata, Release::sole()->metadata);
    }

    // 4. migrations are detected
    public function test_migration_files_are_automatically_detected_from_the_push(): void
    {
        $project = $this->githubProject(changedFiles: [
            'service/app/Services/Releases/ReleaseOrchestrator.php',
            'service/database/migrations/2026_01_01_000000_add_thing.php',
        ]);

        $this->dispatch($project, self::HEAD);

        $release = Release::sole();
        $this->assertSame(['service/database/migrations/2026_01_01_000000_add_thing.php'], $release->metadata['migration_files']);
    }

    // 5. missing test evidence does NOT magically become passed
    // 7. Release remains NOT READY when required evidence is absent
    public function test_release_readiness_reports_not_ready_after_facts_alone(): void
    {
        $project = $this->githubProject();
        $this->dispatch($project, self::HEAD);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $release = Release::sole()->fresh();
        $readiness = app(\App\Services\Releases\ReleaseReadiness::class)->for(self::HEAD, $release, Artifact::sole());

        $this->assertFalse($readiness['deployable']);
        $this->assertSame('NOT READY', $readiness['code']);
    }

    // 6. Artifact success updates only evidence it actually proves
    public function test_artifact_upload_never_flips_release_ready_by_itself(): void
    {
        $project = $this->githubProject();
        $this->dispatch($project, self::HEAD);

        $releaseId = Release::sole()->id;
        app(ReleaseOrchestrator::class)->syncArtifactState(Release::find($releaseId));
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        app(ReleaseOrchestrator::class)->syncArtifactState(Release::find($releaseId));

        $release = Release::find($releaseId);
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->status, 'Artifact bytes alone cannot advance the release to READY without trusted evidence.');
        $this->assertFalse($release->metadata['release_ready'], 'It must never fabricate readiness metadata that no evidence supports.');
    }

    // 8. Release becomes READY only when every required trusted condition is satisfied
    public function test_webhook_facts_plus_admin_backfill_evidence_reaches_ready(): void
    {
        $project = $this->githubProject();
        $this->dispatch($project, self::HEAD);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        // The backfill service re-derives the same facts (mocked identically
        // above) and layers genuine operator-confirmed evidence on top.
        $this->actingAs($admin)->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();

        $release = Release::sole();
        $this->assertTrue($release->metadata['release_ready']);
        $this->assertSame(self::CHANGED_FILES, $release->metadata['changed_files']);
    }

    // 9. deployment-system can bootstrap a release-management change without circular dependency
    public function test_factual_metadata_recording_has_no_dependency_on_the_pushed_commits_own_code(): void
    {
        // The resolver/orchestrator that record facts are constructed purely
        // from already-existing infrastructure (GitHubAppClient, Release
        // model, ReleaseMetadataValidator) — nothing about this call depends
        // on what the commit itself changed, so it works identically
        // whether or not the commit being processed is deployment-system's
        // own release-machinery code.
        $project = $this->githubProject(changedFiles: [
            'service/app/Http/Controllers/Dashboard/ReleaseBackfillController.php',
            'service/app/Services/Releases/ReleaseBackfillService.php',
        ]);

        $this->dispatch($project, self::HEAD);

        $this->assertDatabaseHas('releases', ['project_id' => $project->id, 'commit_hash' => self::HEAD]);
        $this->assertSame(
            ['service/app/Http/Controllers/Dashboard/ReleaseBackfillController.php', 'service/app/Services/Releases/ReleaseBackfillService.php'],
            Release::sole()->metadata['changed_files'],
        );
    }

    // 10. no Deployment is created merely by metadata generation
    public function test_webhook_facts_never_create_a_deployment(): void
    {
        $project = $this->githubProject();

        $this->dispatch($project, self::HEAD);

        $this->assertDatabaseCount('deployments', 0);
    }

    // 11. existing valid signed metadata is preserved correctly
    public function test_a_webhook_redelivery_never_downgrades_already_ready_metadata(): void
    {
        $project = $this->githubProject();
        $this->dispatch($project, self::HEAD);
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id, 'commit_hash' => self::HEAD, 'branch' => 'main', 'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('projects.release-backfill.store', $project), [
            'confirm' => '1',
            'confirmed_checks' => ['tests_passed', 'lint_passed'],
        ])->assertRedirect();
        $readyMetadata = Release::sole()->metadata;
        $this->assertTrue($readyMetadata['release_ready']);

        // A duplicate/redelivered webhook for the exact same commit must be
        // a pure no-op against already-ready metadata.
        $this->dispatch($project, self::HEAD);

        $this->assertSame($readyMetadata, Release::sole()->metadata);
    }

    // 15. other projects continue using the same generic mechanism
    public function test_the_mechanism_is_project_agnostic(): void
    {
        $project = $this->githubProject();
        $project->update(['project_key' => 'customer-site', 'name' => 'customer-site']);

        $this->dispatch($project, self::HEAD);

        $release = Release::sole();
        $this->assertSame('customer-site', $release->metadata['project_key']);
        $this->assertSame(self::BASE, $release->metadata['base_sha']);
    }

    public function test_github_unavailable_leaves_the_release_without_factual_metadata_rather_than_failing_the_job(): void
    {
        $account = GitHubAccount::factory()->create();
        $project = Project::factory()->create(['github_account_id' => $account->id, 'branch' => 'main']);
        $this->mock(GitHubAppClient::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturnFalse();
        });

        $this->dispatch($project, self::HEAD);

        $release = Release::sole();
        $this->assertDatabaseCount('releases', 1);
        $this->assertNull($release->metadata['base_sha'] ?? null);
    }
}
