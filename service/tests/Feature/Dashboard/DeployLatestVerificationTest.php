<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artifact;
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
