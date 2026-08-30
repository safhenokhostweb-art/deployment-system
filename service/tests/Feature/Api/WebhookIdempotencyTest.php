<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Release;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2: webhook idempotency via X-GitHub-Delivery.
 */
class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function test_same_delivery_id_creates_exactly_one_release(): void
    {
        $project = Project::factory()->autoDeploy()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('a', 40), 'pusher' => ['name' => 'octocat']];
        $body = json_encode($payload);
        $headers = [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'delivery-uuid-123',
            'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
        ];

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)->assertAccepted();
        // GitHub redelivering the identical event (same delivery id).
        $response = $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers);

        $response->assertOk();
        $this->assertSame(1, Release::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'webhook.duplicate_delivery_rejected']);
    }

    public function test_different_delivery_ids_create_separate_releases(): void
    {
        $project = Project::factory()->autoDeploy()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        foreach (['delivery-1', 'delivery-2'] as $i => $deliveryId) {
            $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat((string) ($i + 1), 40)];
            $body = json_encode($payload);
            $headers = [
                'X-GitHub-Event' => 'push',
                'X-GitHub-Delivery' => $deliveryId,
                'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
            ];

            $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)->assertAccepted();
        }

        $this->assertSame(2, Release::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('deployments', 0);
    }

    /**
     * Root-cause fix (Release/Webhook hardening): a missing X-GitHub-Delivery
     * header must NEVER bypass ownership/dedupe by skipping tracking
     * entirely -- a network-retried or crafted request without the header
     * would otherwise queue an unbounded number of duplicate jobs for the
     * exact same push. A deterministic identity is synthesized from the
     * verified payload instead, so the push still processes normally AND
     * an exact retry of the same body is recognized as the same delivery.
     */
    public function test_missing_delivery_header_is_still_tracked_and_deduplicated(): void
    {
        $project = Project::factory()->autoDeploy()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)];
        $body = json_encode($payload);
        $headers = ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $this->sign($body, 'topsecret')];

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)->assertAccepted();

        $this->assertSame(1, Release::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('webhook_deliveries', 1);

        // An exact retry of the identical body, still without the header,
        // must be recognized as the SAME delivery -- never a second Release.
        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)
            ->assertOk()
            ->assertJson(['message' => 'Duplicate delivery, already processed']);
        $this->assertSame(1, Release::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_failed_delivery_receipt_is_replayable_and_redelivery_remains_idempotent(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);
        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)];
        $body = json_encode($payload);
        $headers = [
            'X-GitHub-Event' => 'push', 'X-GitHub-Delivery' => 'delivery-replayable',
            'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
        ];

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)->assertAccepted();
        WebhookDelivery::where('delivery_id', 'delivery-replayable')->update([
            'status' => WebhookDelivery::STATUS_FAILED, 'failed_at' => now(), 'failure_message' => 'simulated enqueue failure',
        ]);

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, $headers)->assertAccepted();
        $this->assertDatabaseHas('webhook_deliveries', ['delivery_id' => 'delivery-replayable', 'status' => WebhookDelivery::STATUS_PROCESSED]);
        $this->assertSame(1, Release::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('deployments', 0);
    }
}
