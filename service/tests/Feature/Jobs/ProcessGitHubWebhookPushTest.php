<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\LocalAgentControlJob;
use App\Models\Project;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessGitHubWebhookPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_creates_a_release_without_deployment(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);

        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $this->assertDatabaseHas('releases', [
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
        ]);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_retry_and_timeout_configuration_is_conservative(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', null);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    public function test_handle_automatically_queues_exact_commit_artifact_on_authoritative_agent(): void
    {
        $sha = str_repeat('d', 40);
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'source_workspace_path' => 'C:\\workspaces\\project',
            'git_branch' => 'main',
            'local_head_sha' => $sha,
            'remote_tracking_sha' => $sha,
            'reported_branch' => 'main',
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        $job = new ProcessGitHubWebhookPush($project->fresh(), $sha, 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $release = Release::sole();
        $build = PublishJob::sole();
        $this->assertSame(Release::STATUS_ARTIFACT_BUILDING, $release->status);
        $this->assertSame(PublishJob::ACTION_BUILD_ARTIFACT, $build->action);
        $this->assertSame($sha, $build->reviewed_head_sha);
        $this->assertSame($agent->id, $build->local_agent_id);
        $this->assertSame($release->id, $build->release_id);
        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * Historical-recovery regression fix, applied defensively to the
     * webhook path too: a delayed queue retry or a GitHub-redelivered
     * webhook could otherwise process an old commit's Release after a
     * newer push already superseded it for the same project+branch.
     * ReleaseRecoveryEligibility::isCurrent() — the same check the
     * scheduled sweep relies on — must block queuing a build here as well.
     */
    public function test_handle_never_queues_a_build_for_a_release_already_superseded_by_a_newer_push(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        Release::factory()->create(['project_id' => $project->id, 'branch' => 'main', 'status' => Release::STATUS_AWAITING_ARTIFACT]);

        $oldSha = str_repeat('9', 40);
        $job = new ProcessGitHubWebhookPush($project->fresh(), $oldSha, 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $this->assertDatabaseHas('releases', ['project_id' => $project->id, 'commit_hash' => $oldSha]);
        $this->assertDatabaseCount('publish_jobs', 0);
    }

    /**
     * Reproduces the exact production release-orchestration deadlock end
     * to end: an authoritative production_build Agent still on 1.5.2 (the
     * exact version installed in production) is otherwise fully healthy
     * (approved, online, capable) but below
     * min_local_agent_version_for_assigned_artifact (1.5.4) — so no
     * build_artifact PublishJob can be created for this push (Release #39
     * had none). Under the old design this was a bare silent catch: no
     * PublishJob, no failure_code, no audit trail, and no path back to
     * eligibility. Orchestration must now instead (a) leave the Release
     * awaiting_artifact with no PublishJob (never a fabricated
     * failed/blocked job), (b) auto-queue the Agent's own exact-commit
     * self-upgrade so the deadlock is not left for a human to notice, and
     * (c) record an explicit, non-silent audit trail of both facts.
     */
    public function test_handle_auto_queues_the_agent_upgrade_and_audit_logs_when_only_the_version_gate_blocks_the_build(): void
    {
        $sha = str_repeat('e', 40);
        $project = Project::factory()->create(['branch' => 'main', 'requires_complete_artifact' => true]);
        $agent = LocalAgent::factory()->productionBuild()->create([
            'operating_system' => 'Linux',
            'agent_version' => '1.5.2',
            'capabilities' => [
                'git' => true,
                'laravel_release_manifest' => true,
                'assigned_artifact_publish_job_id' => true,
                'linux_self_upgrade' => true,
            ],
        ]);
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        // The Agent's own exact-commit upgrade authority: a ready Release
        // and succeeded Deployment for the deployment-system project,
        // shipping canonical Agent 1.5.4 (the real, currently checked-out
        // agent.php — see LocalAgentVersionResolver::canonical()).
        $upgradeCommit = str_repeat('c', 40);
        $upgradeProject = Project::factory()->create(['project_key' => 'deployment-system', 'branch' => 'master']);
        $upgradeRelease = Release::factory()->ready()->create(['project_id' => $upgradeProject->id, 'commit_hash' => $upgradeCommit, 'branch' => 'master']);
        Deployment::factory()->create([
            'project_id' => $upgradeProject->id,
            'server_id' => $upgradeProject->server_id,
            'release_id' => $upgradeRelease->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $upgradeCommit,
            'branch' => 'master',
            'finished_at' => now(),
        ]);

        $job = new ProcessGitHubWebhookPush($project->fresh(), $sha, 'main', 'octocat');
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $release = Release::where('project_id', $project->id)->where('commit_hash', $sha)->sole();
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $release->status);
        $this->assertDatabaseCount('publish_jobs', 0);

        $upgradeJob = LocalAgentControlJob::where('local_agent_id', $agent->id)->where('action', LocalAgentControlJob::ACTION_UPGRADE)->sole();
        $this->assertSame(LocalAgentControlJob::STATUS_QUEUED, $upgradeJob->status);
        $this->assertSame('1.5.4', $upgradeJob->expected_version);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'release.artifact_build_blocked_by_authoritative_agent',
            'subject_type' => Release::class,
            'subject_id' => $release->id,
        ]);
        $audit = AuditLogEntry::where('action', 'release.artifact_build_blocked_by_authoritative_agent')->sole();
        $this->assertSame($upgradeJob->id, $audit->metadata['auto_queued_agent_upgrade_job_id']);
    }

    /**
     * A job serialized by pre-hardening code never had webhookDispatchToken
     * in its payload at all. SerializesModels::__unserialize() only
     * restores keys actually present in the serialized array, so without
     * ProcessGitHubWebhookPush's own __unserialize() override this typed
     * readonly property would come back permanently uninitialized -- and
     * reading it in handle() would throw "must not be accessed before
     * initialization" instead of processing the webhook, i.e. exactly the
     * queued-work silently getting lost that a real deploy of this
     * hardening phase must never cause for jobs already sitting in the
     * queue.
     */
    public function test_a_pre_hardening_serialized_job_without_a_dispatch_token_property_still_processes(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);

        // The exact shape a genuinely pre-hardening in-flight delivery is
        // left in by THIS migration's own up(): no dispatch_token ever
        // existed for it, and the migration itself already flips any such
        // unowned row to failed/replayable before the worker restarts.
        $delivery = WebhookDelivery::create([
            'delivery_id' => 'legacy-format-delivery',
            'project_id' => $project->id,
            'event' => 'push',
            'status' => WebhookDelivery::STATUS_FAILED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'failure_message' => 'legacy_dispatch_ownership_unconfirmed',
        ]);

        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat', $delivery->id, null);
        $values = $job->__serialize();
        $this->assertArrayHasKey('webhookDispatchToken', $values, 'a promoted constructor property is always included by SerializesModels regardless of value, so removing it below is a faithful simulation of a genuinely old payload');
        unset($values['webhookDispatchToken']);

        // A genuinely pre-hardening job never had this property on the
        // class at all, so it could never appear in $values -- build a
        // fresh, otherwise-uninitialized instance (mirroring exactly what
        // PHP's own unserialize() does: allocate without running the
        // constructor) and restore it the same way a queue worker would.
        $restored = (new \ReflectionClass(ProcessGitHubWebhookPush::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($values);
        $this->assertInstanceOf(ProcessGitHubWebhookPush::class, $restored);
        $this->assertNull($restored->webhookDispatchToken, 'a genuinely pre-hardening job must restore this as null, never crash on access');

        $restored->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $this->assertDatabaseHas('releases', ['project_id' => $project->id, 'commit_hash' => str_repeat('a', 40)]);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
    }

    /**
     * A hard worker death (kill -9, OOM) mid-handle() leaves the delivery
     * stuck at PROCESSING with no exception ever thrown. Laravel's own
     * queue-level retry re-invokes the exact same serialized job (same
     * token) -- this must actually resume processing, not silently no-op
     * forever.
     */
    public function test_a_retried_job_after_a_hard_worker_death_still_completes_processing(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('killed-worker-delivery', $project, 'push', str_repeat('a', 40), 'main');

        // Simulate the FIRST attempt dying mid-handle(): claimProcessing()
        // ran (status is now PROCESSING) but nothing ever completed or threw.
        $service->claimProcessing($claim['delivery']->id, $claim['token']);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSING, $claim['delivery']->fresh()->status);

        // Laravel's native queue retry hands the SAME job/token back for a
        // second attempt -- well before any lease could have expired.
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat', $claim['delivery']->id, $claim['token']);
        $job->handle(
            app(\App\Services\Releases\ReleaseOrchestrator::class),
            app(\App\Services\Releases\ReleaseGitFactsResolver::class),
            app(\App\Services\Publish\AuthoritativeBuildAgentResolver::class),
            app(\App\Services\Publish\PublishJobService::class),
        );

        $this->assertDatabaseHas('releases', ['project_id' => $project->id, 'commit_hash' => str_repeat('a', 40)]);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $claim['delivery']->fresh()->status);
    }

    public function test_permanent_failure_is_audit_logged(): void
    {
        $project = Project::factory()->create();
        $job = new ProcessGitHubWebhookPush($project, str_repeat('a', 40), 'main', 'octocat');

        $job->failed(new RuntimeException('deadlock detected'));

        $this->assertDatabaseHas('audit_log_entries', [
            'actor_type' => 'system',
            'action' => 'webhook.release_preparation_failed',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
        ]);
    }
}
