<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\Security\GitHubWebhookVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubWebhookVerifier $verifier,
    ) {
    }

    /**
     * One webhook URL per project (the project_key is part of the URL and
     * configured in that GitHub repo's webhook settings), each with its own
     * secret. This avoids ambiguity when multiple Projects track the same
     * repository (e.g. a staging and a production Project both deploying
     * from main to different servers).
     */
    public function handle(Request $request, string $projectKey)
    {
        $project = Project::where('project_key', $projectKey)->where('is_active', true)->first();

        if (!$project) {
            return response()->json(['message' => 'Unknown project'], 404);
        }

        $rawBody = $request->getContent();
        $signatureHeader = $request->header('X-Hub-Signature-256');

        if (!$this->verifier->verify($rawBody, $signatureHeader, $project->github_webhook_secret)) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_GITHUB,
                actorIdentifier: $projectKey,
                action: 'webhook.rejected_invalid_signature',
                subjectType: Project::class,
                subjectId: $project->id,
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->header('X-GitHub-Event');
        $deliveryId = $request->header('X-GitHub-Delivery');

        // Task 2: GitHub redelivers a webhook (e.g. after a timeout or a
        // non-2xx response) using the *same* X-GitHub-Delivery value.
        // Recording it here, before anything else, makes a redelivery a
        // cheap no-op instead of a second Deployment. A caller that
        // doesn't send this header (only ever a test, in practice — real
        // GitHub always sends it) skips this check entirely, so nothing
        // about existing behavior changes for it.
        if ($deliveryId !== null && !$this->recordDelivery($deliveryId, $project, $event)) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_GITHUB,
                actorIdentifier: $projectKey,
                action: 'webhook.duplicate_delivery_rejected',
                subjectType: Project::class,
                subjectId: $project->id,
                metadata: ['event' => $event, 'delivery_id' => $deliveryId],
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Duplicate delivery, already processed']);
        }

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_GITHUB,
            actorIdentifier: $projectKey,
            action: 'webhook.received',
            subjectType: Project::class,
            subjectId: $project->id,
            metadata: ['event' => $event],
            ipAddress: $request->ip(),
        );

        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        if ($event !== 'push') {
            return response()->json(['message' => "Ignored event: {$event}"]);
        }

        $payload = $request->json()->all();
        $ref = $payload['ref'] ?? null; // refs/heads/<branch>
        $commitHash = $payload['after'] ?? null;
        $pusher = $payload['pusher']['name'] ?? $payload['sender']['login'] ?? null;

        if (!$ref || !$commitHash) {
            return response()->json(['message' => 'Malformed push payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Tag deletion pushes 'after' as all-zero SHA; nothing to deploy.
        if (preg_match('/^0+$/', $commitHash)) {
            return response()->json(['message' => 'Ignored branch deletion']);
        }

        $branch = str_replace('refs/heads/', '', $ref);

        if ($branch !== $project->branch) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_GITHUB,
                actorIdentifier: $projectKey,
                action: 'webhook.ignored_branch_mismatch',
                subjectType: Project::class,
                subjectId: $project->id,
                metadata: ['pushed_branch' => $branch, 'expected_branch' => $project->branch],
            );

            return response()->json(['message' => "Ignored push to {$branch}, project tracks {$project->branch}"]);
        }

        // Queued (not called inline) so a transient DB issue is retried
        // automatically instead of silently dropping the push, and the
        // webhook response time doesn't depend on DB load. In the `sync`
        // queue driver (used in tests) this still runs immediately.
        ProcessGitHubWebhookPush::dispatch($project, $commitHash, $branch, $pusher);

        return response()->json([
            'message' => 'Push accepted, release readiness is being prepared',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @return bool true if this is the first time delivery_id has been
     *              seen (safe to proceed), false if it's a duplicate.
     */
    private function recordDelivery(string $deliveryId, Project $project, ?string $event): bool
    {
        try {
            WebhookDelivery::create([
                'delivery_id' => $deliveryId,
                'project_id' => $project->id,
                'event' => $event,
            ]);

            return true;
        } catch (QueryException) {
            // Unique(delivery_id) violation: already recorded.
            return false;
        }
    }
}
