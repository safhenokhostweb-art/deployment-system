<?php

namespace Tests\Feature\Api;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function test_valid_push_to_tracked_branch_creates_pending_deployment(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => str_repeat('a', 40),
            'pusher' => ['name' => 'octocat'],
        ];
        $body = json_encode($payload);

        $response = $this->postJson(
            "/api/webhooks/github/{$project->project_key}",
            $payload,
            [
                'X-GitHub-Event' => 'push',
                'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
            ]
        );

        $response->assertAccepted();
        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);
    }

    public function test_auto_deploy_project_skips_approval(): void
    {
        $project = Project::factory()->autoDeploy()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)];
        $body = json_encode($payload);

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
        ])->assertAccepted();

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'status' => Deployment::STATUS_QUEUED,
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $project = Project::factory()->create(['github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)];

        $response = $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $project = Project::factory()->create(['github_webhook_secret' => 'topsecret']);

        $response = $this->postJson("/api/webhooks/github/{$project->project_key}", [
            'ref' => 'refs/heads/main',
            'after' => str_repeat('d', 40),
        ], ['X-GitHub-Event' => 'push']);

        $response->assertStatus(401);
    }

    public function test_push_to_untracked_branch_is_ignored(): void
    {
        $project = Project::factory()->create(['branch' => 'main', 'github_webhook_secret' => 'topsecret']);

        $payload = ['ref' => 'refs/heads/feature-x', 'after' => str_repeat('e', 40)];
        $body = json_encode($payload);

        $this->postJson("/api/webhooks/github/{$project->project_key}", $payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $this->sign($body, 'topsecret'),
        ])->assertOk();

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_unknown_project_key_returns_404(): void
    {
        $this->postJson('/api/webhooks/github/does-not-exist', ['ref' => 'refs/heads/main', 'after' => str_repeat('f', 40)], [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ])->assertStatus(404);
    }
}
