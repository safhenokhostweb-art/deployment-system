<?php

namespace Tests\Feature\Deployment;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use App\Models\Environment;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\User;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Publish\PublishJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Root-cause fix (no-source-change publish lifecycle gap): a publish that
 * succeeded with no source changes never pushes, so the GitHub push
 * webhook — the only deployment-creation path for a normal publish — never
 * fires. PublishJobService bridges exactly this case into the Deployment
 * domain via DeploymentDispatcher::createFromPublish() (trigger `publish`,
 * same auto_deploy/artifact semantics as a push) and correlates the one
 * Deployment it creates on publish_jobs.deployment_id, so the Publish page
 * shows the deployment belonging to THIS publish attempt instead of the
 * latest unrelated historical deployment sharing the same SHA.
 *
 * The push-based webhook path is deliberately untouched and remains
 * authoritative whenever a real push occurred.
 */
class PublishNoSourceChangeDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function mappedAgentAndProject(): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);

        return [$agent, $project];
    }

    /**
     * The no-source-change bridge dispatches through
     * DeploymentDispatcher::createFromPublish(), which under the new
     * Release contract requires an explicit, auditable LEGACY_FALLBACK
     * grant (auto_deploy alone no longer suffices) -- these tests are
     * about bridge/correlation/artifact-attachment mechanics, not
     * authorization, so each dispatch is given one for its exact
     * project/commit/branch. firstOrCreate() so a test that also dispatches
     * a separate webhook deployment for the same commit can call this
     * itself first without creating a duplicate row.
     */
    private function grantLegacyFallback(Project $project, string $commitHash, string $branch = 'main'): Release
    {
        return Release::query()->firstOrCreate(
            ['project_id' => $project->id, 'commit_hash' => $commitHash],
            [
                'branch' => $branch,
                'source' => 'admin_backfill',
                'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
                'legacy_fallback_eligible_at' => now(),
                'legacy_fallback_reason' => 'test_fixture_explicit_legacy_grant',
                'legacy_fallback_eligible_by' => 'test-harness',
            ],
        );
    }

    /**
     * Runs the full no-source-change publish lifecycle to success: clean
     * status_check on the verified existing commit -> approved -> claimed ->
     * reported succeeded (no push). The matching AVAILABLE artifact is
     * created first (the bridge requires it) unless $withArtifact is false.
     * Returns the fresh, correlated job.
     */
    private function succeedNoSourceChangePublish(Project $project, LocalAgent $agent, string $commit, bool $withArtifact = true): PublishJob
    {
        $project->localProjectMappings()->first()->update([
            'local_head_sha' => $commit,
            'remote_tracking_sha' => $commit,
            'working_tree_dirty' => false,
        ]);
        $this->grantLegacyFallback($project, $commit);

        if ($withArtifact) {
            Artifact::factory()->physicallyAvailable()->create([
                'project_id' => $project->id,
                'commit_hash' => $commit,
                'status' => Artifact::STATUS_AVAILABLE,
            ]);
        }

        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, [], [], $commit, 'main', true);
        $service->approve($job, null, 'dev@example.test');
        $service->claimNext($agent);
        $service->reportResult($job->fresh(), $commit, 'No source changes: publishing verified existing commit without git commit or push.');

        return $job->fresh();
    }

    // ------------------------------------------------------------------
    // CASE 1: a normal push-based publish stays webhook-driven — the
    // bridge never fires for it.
    // ------------------------------------------------------------------

    public function test_a_push_based_publish_never_bridges_into_deployment_creation(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->localProjectMappings()->first()->update(['local_head_sha' => str_repeat('a', 40), 'working_tree_dirty' => true]);

        $service = app(PublishJobService::class);
        $job = $service->queue($agent, $project, PublishJob::ACTION_STATUS_CHECK, 'dev@example.test');
        $service->reportStatusCheck($job, ['app/Foo.php'], [], str_repeat('a', 40), 'main', false);
        $service->approve($job, 'Fix the widget', 'dev@example.test');
        $service->claimNext($agent);
        $service->reportResult($job->fresh(), str_repeat('b', 40), 'main -> main'); // agent pushed a NEW commit

        $fresh = $job->fresh();
        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $fresh->status);
        $this->assertNull($fresh->deployment_id, 'A push-based publish must not bridge — the webhook is authoritative.');
        $this->assertSame(0, Deployment::where('project_id', $project->id)->count());
    }

    // ------------------------------------------------------------------
    // CASE 2: no-source-change publish + matching uploaded artifact ->
    // exactly one new Deployment, artifact attached, correlation recorded.
    // ------------------------------------------------------------------

    public function test_no_source_change_publish_creates_one_deployment_and_attaches_the_uploaded_artifact(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $commit = str_repeat('a', 40);
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $commit,
            'status' => Artifact::STATUS_AVAILABLE,
        ]);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit, false);

        $this->assertNotNull($job->deployment_id);
        $deployment = $job->deployment;
        $this->assertSame(Deployment::TRIGGER_PUBLISH, $deployment->trigger);
        $this->assertSame($project->id, $deployment->project_id);
        $this->assertSame($commit, $deployment->commit_hash);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame($artifact->id, $deployment->artifact_id);
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status, 'auto_deploy project auto-approves exactly like the webhook path.');
        $this->assertSame(1, Deployment::where('project_id', $project->id)->count());

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.deployment_bridged',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment.created_from_publish',
            'subject_type' => Deployment::class,
            'subject_id' => $deployment->id,
        ]);
    }

    // ------------------------------------------------------------------
    // CASE 3: an old failed deployment for the same commit is never reused
    // or pointed at by the new publish.
    // ------------------------------------------------------------------

    public function test_a_new_no_source_change_publish_does_not_reuse_an_old_failed_deployment_for_the_same_commit(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $commit = str_repeat('a', 40);
        $oldFailed = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Deployment::STATUS_FAILED,
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'finished_at' => now()->subDay(),
        ]);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);

        $this->assertNotSame($oldFailed->id, $job->deployment_id, 'The new publish must get a NEW deployment attempt, not the old failed row.');
        $this->assertSame($job->deployment_id, $job->deployment->id);
        $this->assertSame(2, Deployment::where('project_id', $project->id)->count());
        $this->assertSame(Deployment::STATUS_FAILED, $oldFailed->fresh()->status, 'The old failed row is untouched history.');
    }

    // ------------------------------------------------------------------
    // CASE 4: repeated success reports can never create duplicate
    // deployments.
    // ------------------------------------------------------------------

    public function test_a_repeated_success_report_does_not_create_a_duplicate_deployment(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $commit = str_repeat('a', 40);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);
        $this->assertSame(1, Deployment::where('project_id', $project->id)->count());

        // The agent double-reports success for the same job — terminal guard
        // makes this a late-callback no-op.
        app(PublishJobService::class)->reportResult($job, $commit, 'duplicate success report');

        $this->assertSame(1, Deployment::where('project_id', $project->id)->count());
        $this->assertSame($job->deployment_id, $job->fresh()->deployment_id);
    }

    // ------------------------------------------------------------------
    // CASE 5: a webhook that fires for the same commit is a separate,
    // legitimate event — the publish still creates exactly ONE deployment
    // of its own.
    // ------------------------------------------------------------------

    public function test_a_webhook_deployment_for_the_same_commit_is_separate_and_does_not_duplicate_the_publish_bridge(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $commit = str_repeat('a', 40);

        // A webhook deployment exists (or arrives) for the same commit.
        $this->grantLegacyFallback($project, $commit);
        $webhookDeployment = app(DeploymentDispatcher::class)->createFromWebhook($project, $commit, 'main', 'dev@example.test');

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);

        $this->assertNotSame($webhookDeployment->id, $job->deployment_id);
        $this->assertSame(2, Deployment::where('project_id', $project->id)->count(), 'One webhook deployment + exactly one publish-bridge deployment.');
        $this->assertSame($job->deployment_id, $job->deployment->id);
    }

    // ------------------------------------------------------------------
    // CASE 6: no matching AVAILABLE artifact at the bridge boundary — the
    // bridge must NOT create an artifact-less deployment (that would send
    // the server agent down the legacy git-fetch path this artifact system
    // exists to eliminate). It skips deterministically, keeps the publish
    // succeeded, and records publish_job.deployment_bridge_failed.
    // ------------------------------------------------------------------

    public function test_no_source_change_bridge_skips_deterministically_when_no_artifact_is_available(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $commit = str_repeat('a', 40);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit, false);

        $fresh = $job->fresh();
        $this->assertSame(PublishJob::STATUS_SUCCEEDED, $fresh->status, 'The publish itself still succeeded.');
        $this->assertNull($fresh->deployment_id, 'No artifact => no artifact-less bridge deployment (no git fallback).');
        $this->assertSame(0, Deployment::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.deployment_bridge_failed',
            'subject_type' => PublishJob::class,
            'subject_id' => $job->id,
        ]);
    }

    // ------------------------------------------------------------------
    // CASE 8 (bridge-failure UI): when the bridge could not create a
    // deployment for a no-source-change publish, the page must NOT surface
    // an unrelated old failed deployment for the same commit.
    // ------------------------------------------------------------------

    public function test_publish_show_page_does_not_surface_an_old_failed_deployment_when_the_bridge_skipped(): void
    {
        $user = User::factory()->create();
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $commit = str_repeat('a', 40);
        $oldFailed = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Deployment::STATUS_FAILED,
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'finished_at' => now()->subDay(),
        ]);

        // No artifact -> the bridge skips and deployment_id stays null.
        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit, false);
        $this->assertNull($job->fresh()->deployment_id);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertDontSee(route('deployments.show', $oldFailed));
    }

    // ------------------------------------------------------------------
    // CASE 7: legacy and multi-target projects both flow through the same
    // dispatcher semantics as the webhook path.
    // ------------------------------------------------------------------

    public function test_no_source_change_bridge_respects_the_auto_deploy_approval_semantics(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject(); // auto_deploy = false
        $commit = str_repeat('c', 40);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $job->deployment->status, 'auto_deploy=false projects require human deployment approval, exactly like the webhook path.');
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'deployment.created_from_publish_pending_approval',
            'subject_type' => Deployment::class,
            'subject_id' => $job->deployment_id,
        ]);
    }

    public function test_no_source_change_bridge_supports_multi_target_projects(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->count(2)->create(['environment_id' => $environment->id]);
        $commit = str_repeat('a', 40);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);

        $deployment = $job->deployment;
        $this->assertSame(Deployment::TRIGGER_PUBLISH, $deployment->trigger);
        $this->assertCount(2, $deployment->tasks);
        $this->assertTrue($deployment->tasks->every(fn (DeploymentTask $task) => $task->status === DeploymentTask::STATUS_QUEUED));
    }

    // ------------------------------------------------------------------
    // CASE 8: the Publish page shows the deployment belonging to THIS
    // publish attempt, never an unrelated historical deployment that
    // happens to share the same SHA.
    // ------------------------------------------------------------------

    public function test_publish_show_page_links_the_correlated_deployment_not_an_old_failed_one_for_the_same_commit(): void
    {
        $user = User::factory()->create();
        [$agent, $project] = $this->mappedAgentAndProject();
        $project->update(['auto_deploy' => true]);
        $commit = str_repeat('a', 40);
        $oldFailed = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Deployment::STATUS_FAILED,
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'finished_at' => now()->subDay(),
        ]);

        $job = $this->succeedNoSourceChangePublish($project, $agent, $commit);

        $this->actingAs($user)->get(route('projects.publish.show', [$project, $job]))
            ->assertOk()
            ->assertSee(route('deployments.show', $job->deployment))
            ->assertDontSee(route('deployments.show', $oldFailed));
    }
}
