<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessGitHubWebhookPush;
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
