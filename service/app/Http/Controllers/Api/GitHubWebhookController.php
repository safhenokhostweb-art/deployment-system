<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Services\Security\GitHubWebhookVerifier;
use App\Services\Webhooks\WebhookDeliveryOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubWebhookVerifier $verifier,
        private readonly WebhookDeliveryOwnershipService $deliveries,
    ) {}

    public function handle(Request $request, string $projectKey)
    {
        $project = Project::where('project_key', $projectKey)->where('is_active', true)->first();
        if (! $project) {
            return response()->json(['message' => 'Unknown project'], 404);
        }

        $event = $request->header('X-GitHub-Event');
        if (! $this->verifier->verify($request->getContent(), $request->header('X-Hub-Signature-256'), $project->github_webhook_secret)) {
            AuditLogEntry::record(AuditLogEntry::ACTOR_GITHUB, $projectKey, 'webhook.rejected_invalid_signature', Project::class, $project->id, ipAddress: $request->ip());

            return response()->json(['message' => 'Invalid signature'], 401);
        }
        AuditLogEntry::record(AuditLogEntry::ACTOR_GITHUB, $projectKey, 'webhook.received', Project::class, $project->id, ['event' => $event], $request->ip());
        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }
        if ($event !== 'push') {
            return response()->json(['message' => "Ignored event: {$event}"]);
        }

        $payload = $request->json()->all();
        $ref = $payload['ref'] ?? null;
        $commitHash = strtolower((string) ($payload['after'] ?? ''));
        $pusher = $payload['pusher']['name'] ?? $payload['sender']['login'] ?? null;
        if (! is_string($ref) || preg_match('/^[0-9a-f]{40}$/', $commitHash) !== 1) {
            return response()->json(['message' => 'Malformed push payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (preg_match('/^0+$/', $commitHash)) {
            return response()->json(['message' => 'Ignored branch deletion']);
        }
        $branch = str_replace('refs/heads/', '', $ref);
        if ($branch !== $project->branch) {
            AuditLogEntry::record(AuditLogEntry::ACTOR_GITHUB, $projectKey, 'webhook.ignored_branch_mismatch', Project::class, $project->id, ['pushed_branch' => $branch, 'expected_branch' => $project->branch]);

            return response()->json(['message' => "Ignored push to {$branch}, project tracks {$project->branch}"]);
        }

        // GitHub always sends X-GitHub-Delivery in practice, but a missing
        // or blank header must never fall back to dispatching untracked --
        // a malformed, spoofed, or network-retried request without it would
        // otherwise bypass ownership/dedupe entirely, queuing an unbounded
        // number of duplicate jobs for the exact same push. Synthesize a
        // deterministic identity from the verified payload itself instead,
        // so an exact retry of the same body still claims the SAME delivery
        // row and goes through the identical dedupe/ownership path.
        $deliveryId = $request->header('X-GitHub-Delivery');
        if (! is_string($deliveryId) || $deliveryId === '') {
            $deliveryId = 'synthetic:' . hash('sha256', $project->id . '|' . $event . '|' . $request->getContent());
        }

        $claim = $this->deliveries->acceptAndClaim($deliveryId, $project, (string) $event, $commitHash, $branch);
        $delivery = $claim['delivery'];
        $dispatchToken = $claim['token'];
        if (! $claim['should_dispatch']) {
            AuditLogEntry::record(AuditLogEntry::ACTOR_GITHUB, $projectKey, 'webhook.duplicate_delivery_rejected', Project::class, $project->id, ['event' => $event, 'delivery_id' => $deliveryId], $request->ip());

            return response()->json(['message' => 'Duplicate delivery, already processed']);
        }

        try {
            ProcessGitHubWebhookPush::dispatch($project, $commitHash, $branch, $pusher, $delivery->id, $dispatchToken);
        } catch (\Throwable $e) {
            if (is_string($dispatchToken)) {
                $this->deliveries->markFailed($delivery->id, $dispatchToken, $e->getMessage());
            }
            throw $e;
        }

        return response()->json(['message' => 'Push accepted, release readiness is being prepared'], Response::HTTP_ACCEPTED);
    }

}
