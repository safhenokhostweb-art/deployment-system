<?php

namespace Tests\Feature\Releases;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderNeutralReleaseContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('artifacts');
    }

    public function test_project_metadata_is_authenticated_and_records_machine_readable_readiness(): void
    {
        [, $project, $sha] = $this->projectFixture();
        $metadata = $this->metadata($project, $sha);

        $this->postMetadata($project, $metadata)->assertAccepted();

        $release = Release::sole();
        $this->assertSame($sha, $release->commit_hash);
        $this->assertSame($metadata['changed_files'], $release->metadata['changed_files']);
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->status);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_release_metadata_mismatch_fails_closed(): void
    {
        [, $project, $sha] = $this->projectFixture();
        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();
        $changed = $this->metadata($project, $sha);
        $changed['base_sha'] = str_repeat('9', 40);

        $this->postMetadata($project, $changed)->assertStatus(409);
        $this->assertSame(str_repeat('b', 40), Release::sole()->metadata['base_sha']);
    }

    public function test_review_page_needs_no_sha_or_artifact_input_and_confirmation_is_mandatory(): void
    {
        [$user, $project, $sha] = $this->projectFixture();
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()
            ->assertSee('READY TO DEPLOY')
            ->assertSee('Review changes')
            ->assertSee('Create deployment candidate')
            ->assertDontSee('name="commit_hash"', false)
            ->assertDontSee('name="artifact_id"', false);
        $this->assertDatabaseCount('deployments', 0);

        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertRedirect();
        $deployment = Deployment::sole();
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame(Release::sole()->id, $deployment->release_id);
    }

    public function test_exact_older_artifact_does_not_make_latest_release_ready(): void
    {
        [$user, $project, $sha] = $this->projectFixture();
        Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
        ]);
        $this->postMetadata($project, $this->metadata($project, $sha))->assertAccepted();

        $this->actingAs($user)->get(route('projects.deploy-latest.show', $project))
            ->assertOk()->assertSee('NOT READY');
        $this->actingAs($user)->post(route('projects.deploy-latest', $project))->assertStatus(409);
        $this->assertDatabaseCount('deployments', 0);
    }

    /** @return array{User, Project, string} */
    private function projectFixture(): array
    {
        $sha = str_repeat('c', 40);
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'project_key' => 'release-fixture',
            'repository' => 'acme/release-fixture',
            'branch' => 'main',
            'requires_complete_artifact' => true,
            'github_webhook_secret' => 'fixture-secret',
        ]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'source_workspace_path' => 'C:\\workspaces\\release-fixture',
            'git_branch' => 'main',
            'local_head_sha' => $sha,
            'remote_tracking_sha' => $sha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);
        return [$user, $project->fresh(), $sha];
    }

    private function metadata(Project $project, string $sha): array
    {
        return [
            'metadata_version' => 1,
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'base_sha' => str_repeat('b', 40),
            'branch' => 'main',
            'summary' => 'Provider-neutral fixture release',
            'changed_files' => ['src/Feature.php'],
            'migration_files' => [],
            'test_results' => [['name' => 'suite', 'exit_code' => 0]],
            'build_result' => ['required' => true, 'status' => 'pending'],
            'health_contract' => ['required' => true, 'endpoint' => '/health'],
            'risk_flags' => [],
            'created_timestamp' => now()->toIso8601String(),
            'release_ready' => true,
        ];
    }

    private function postMetadata(Project $project, array $metadata)
    {
        $raw = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'fixture-secret');
        return $this->call('POST', route('projects.releases.store', $project->project_key), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEPLOYMENT_SIGNATURE_256' => $signature,
        ], $raw);
    }
}
