<?php

namespace Tests\Feature\Api;

use App\Models\Deployment;
use App\Models\Project;
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

    public function test_same_delivery_id_creates_exactly_one_deployment(): void
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
        $this->assertSame(1, Deployment::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'webhook.duplicate_delivery_rejected']);
    }

    public function test_different_delivery_ids_create_separate_deployments(): void
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

        $this->assertSame(2, Deployment::where('project_id', $project->id)->count());
    }

    public function test_missing_delivery_header_still_processes_normally(): void
    {
        // No X-GitHub-Delivery header at all — matches every pre-existing
        // GitHubWebhookTest request shape, so behavior must be identical.
        $project = Project::factory()->autoDeploy()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)];
        $body = json_encode($payload);

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
        ])->assertAccepted();

        $this->assertSame(1, Deployment::where('project_id', $project->id)->count());
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }
}
