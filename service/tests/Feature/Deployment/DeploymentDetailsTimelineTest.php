<?php

namespace Tests\Feature\Deployment;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\Deployment\AgentTaskService;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix (deployment details observability): a fully successful
 * multi-target (task-based) deployment reached finished_at with started_at
 * and deployed_at still null — the new-domain claim path never stamped the
 * deployment-level timestamps the legacy path has always set — so the
 * details page showed contradictory "started: — / duration: —" and
 * "verified deployed: not verified" for a deployment that verifiably ran and
 * passed every health check. These tests pin the full lifecycle, the
 * details-page rendering, and backward compatibility for legacy rows.
 */
class DeploymentDetailsTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): DeploymentDispatcher
    {
        return app(DeploymentDispatcher::class);
    }

    private function taskService(): AgentTaskService
    {
        return app(AgentTaskService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * The real production shape of the reported deployment: webhook-created,
     * human-approved, queued with one claimable task on the agent's server.
     *
     * @return array{agent: Agent, project: Project, deployment: Deployment}
     */
    private function makeApprovedTaskDeployment(): array
    {
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $agent->server_id,
            'name' => 'web-01',
        ]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('a', 40), $project->branch, 'octocat');
        $this->dispatcher()->approve($deployment, 'admin@example.test');

        return ['agent' => $agent, 'project' => $project, 'deployment' => $deployment->fresh()];
    }

    // CASE 1 — pending_approval and still unapproved: no approval, queue or
    // start fields exist yet, so nothing can be displayed prematurely.
    public function test_case1_pending_approval_has_no_approval_queue_or_start_fields(): void
    {
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('a', 40), $project->branch, 'octocat');

        $this->assertSame(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertCount(0, $deployment->tasks);
        $this->assertNull($deployment->approved_by);
        $this->assertNull($deployment->approved_at);
        $this->assertNull($deployment->queued_at);
        $this->assertNull($deployment->started_at);
        $this->assertNull($deployment->finished_at);
        $this->assertNull($deployment->durationSeconds());
    }

    // CASE 2 — approved by admin but not yet executed: queued with an
    // approval record and queued_at, but no start/finish yet.
    public function test_case2_approved_but_not_started(): void
    {
        $deployment = $this->makeApprovedTaskDeployment()['deployment'];

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertCount(1, $deployment->tasks);
        $this->assertSame('admin@example.test', $deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);
        $this->assertNotNull($deployment->queued_at);
        $this->assertNull($deployment->started_at);
        $this->assertNull($deployment->finished_at);
        $this->assertNull($deployment->durationSeconds());
    }

    // CASE 3 — queued via auto_deploy: queued_at present, not started.
    public function test_case3_auto_deploy_deployment_is_queued_with_queued_at(): void
    {
        $project = Project::factory()->create(['auto_deploy' => true]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id]);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('b', 40), $project->branch, 'octocat');

        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNotNull($deployment->queued_at);
        $this->assertSame('auto_deploy', $deployment->approved_by);
        $this->assertNull($deployment->started_at);
        $this->assertNull($deployment->finished_at);
    }

    // CASE 4 — actually started: the first task claim stamps deployment
    // started_at exactly once; a later claim of another target of the same
    // rollout (or a retry attempt) must never rewrite it.
    public function test_case4_first_claim_stamps_started_at_exactly_once(): void
    {
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $agent->server_id, 'name' => 'web-01']);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $agent->server_id, 'name' => 'web-02']);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('c', 40), $project->branch, 'octocat');
        $this->dispatcher()->approve($deployment, 'admin@example.test');
        $deployment = $deployment->fresh();

        $this->assertNull($deployment->started_at);

        $first = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($first);

        $startedAt = $deployment->fresh()->started_at;
        $this->assertNotNull($startedAt);
        $this->assertNull($deployment->fresh()->finished_at);

        $second = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($second);
        $this->assertSame(
            $startedAt->format('Y-m-d H:i:s'),
            $deployment->fresh()->started_at->format('Y-m-d H:i:s'),
            'a later claim of another target must never rewrite started_at'
        );
    }

    // CASE 5 — succeeded: started_at, finished_at, deployed_at and a
    // displayable duration all present for a fully successful rollout.
    public function test_case5_succeeded_deployment_has_full_timeline_and_duration(): void
    {
        $ctx = $this->makeApprovedTaskDeployment();

        $job = $this->taskService()->claimNextTask($ctx['agent']);
        $this->assertNotNull($job);
        $agentJob = AgentJob::where('public_id', $job['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($agentJob, AgentJob::STATUS_SUCCEEDED);

        $fresh = $ctx['deployment']->fresh();
        $this->assertSame(Deployment::STATUS_SUCCEEDED, $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNotNull($fresh->deployed_at, 'an all-tasks-succeeded deployment is genuinely verified deployed');
        $this->assertNotNull($fresh->durationSeconds());
        $this->assertGreaterThanOrEqual(0, $fresh->durationSeconds());
        $this->assertNotNull($fresh->approved_by);
    }

    // CASE 6 — failed after start: started_at and finished_at correct, and
    // a failed deployment is never "verified deployed".
    public function test_case6_failed_after_start_has_correct_timestamps(): void
    {
        $ctx = $this->makeApprovedTaskDeployment();

        $job = $this->taskService()->claimNextTask($ctx['agent']);
        $this->assertNotNull($job);
        $agentJob = AgentJob::where('public_id', $job['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($agentJob, AgentJob::STATUS_FAILED);

        $fresh = $ctx['deployment']->fresh();
        $this->assertSame(Deployment::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->deployed_at);
    }

    // CASE 7 — the details page for an approved, successful multi-target
    // deployment without an HTTP policy shows command success separately
    // from the honest "HTTP not verified" state.
    public function test_case7_successful_approved_deployment_without_http_check_is_not_health_verified(): void
    {
        $ctx = $this->makeApprovedTaskDeployment();

        $job = $this->taskService()->claimNextTask($ctx['agent']);
        $this->assertNotNull($job);
        $agentJob = AgentJob::where('public_id', $job['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($agentJob, AgentJob::STATUS_SUCCEEDED);

        $deployment = $ctx['deployment']->fresh();

        $response = $this->actingAs($this->admin())->get(route('deployments.show', $deployment));

        $response->assertOk();
        $response->assertSee('admin@example.test', false);
        $response->assertSee(__('ui.deployments.verified_deployed_no'));
        $response->assertDontSee(__('ui.deployments.verified_deployed_yes'));
    }

    // CASE 8 — a legacy deployment with started_at = null renders without
    // exception and falls back honestly ("—" for started/duration).
    public function test_case8_legacy_deployment_with_null_started_at_renders_with_honest_fallback(): void
    {
        $project = Project::factory()->create();
        $old = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'started_at' => null,
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('deployments.show', $old));

        $response->assertOk();
        $response->assertSeeInOrder([
            __('ui.deployments.started'),
            '—',
            __('ui.deployments.finished'),
        ]);
    }

    // CASE 9 — partial multi-target success: one target succeeded, one
    // failed → partial_success with finished_at, but deployed_at stays
    // null. A rollout that did NOT reach every target is never
    // "verified deployed".
    public function test_case9_partial_multi_target_success_never_verifies_deployed(): void
    {
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $agent->server_id, 'name' => 'web-01']);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $agent->server_id, 'name' => 'web-02']);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('d', 40), $project->branch, 'octocat');
        $this->dispatcher()->approve($deployment, 'admin@example.test');

        // Target 1 succeeds; aggregate goes queued → running (target 2 queued).
        $first = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($first);
        $firstJob = AgentJob::where('public_id', $first['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($firstJob, AgentJob::STATUS_SUCCEEDED);

        // Target 2 fails; aggregate becomes partial_success.
        $second = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($second);
        $secondJob = AgentJob::where('public_id', $second['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($secondJob, AgentJob::STATUS_FAILED);

        $fresh = $deployment->fresh();
        $this->assertSame(Deployment::STATUS_PARTIAL_SUCCESS, $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->deployed_at, 'a partially successful rollout is never verified deployed');
    }

    // CASE 10 — a retry attempt (same task requeued, brand-new AgentJob)
    // never overwrites the original started_at: the whereNull guard is
    // re-evaluated and the first stamp is preserved exactly like a second
    // target's claim is.
    public function test_case10_retry_attempt_never_overwrites_started_at(): void
    {
        config(['deployment.task_max_attempts' => 3, 'deployment.task_retry_backoff_seconds' => [0, 0]]);
        $agent = Agent::factory()->approved()->create();
        $project = Project::factory()->create(['auto_deploy' => false]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        DeploymentTarget::factory()->create(['environment_id' => $environment->id, 'server_id' => $agent->server_id, 'name' => 'web-01']);

        $deployment = $this->dispatcher()->createFromWebhook($project, str_repeat('e', 40), $project->branch, 'octocat');
        $this->dispatcher()->approve($deployment, 'admin@example.test');
        $deployment = $deployment->fresh();

        // Attempt 1 fails → retry scheduled (max_attempts = 3).
        $first = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($first);
        $firstJob = AgentJob::where('public_id', $first['job']['id'])->firstOrFail();
        $this->taskService()->updateStatus($firstJob, AgentJob::STATUS_FAILED);

        $startedAt = $deployment->fresh()->started_at;
        $this->assertNotNull($startedAt);

        // Attempt 2 — same task requeued, outside the backoff window.
        $deployment->tasks()->first()->update(['next_retry_at' => now()->subSecond()]);
        $second = $this->taskService()->claimNextTask($agent);
        $this->assertNotNull($second);
        $this->assertSame(2, $deployment->tasks()->first()->fresh()->agentJobs()->count());

        $this->assertSame(
            $startedAt->format('Y-m-d H:i:s'),
            $deployment->fresh()->started_at->format('Y-m-d H:i:s'),
            'a retry attempt must never rewrite the original started_at'
        );
    }
}
