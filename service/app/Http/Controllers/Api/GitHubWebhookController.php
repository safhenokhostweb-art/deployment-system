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
use Illuminate\Support\Facades\DB;

class GitHubWebhookController extends Controller
{
    public function __construct(private readonly GitHubWebhookVerifier $verifier) {}

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

        $delivery = null;
        $deliveryId = $request->header('X-GitHub-Delivery');
        if (is_string($deliveryId) && $deliveryId !== '') {
            [$delivery, $shouldDispatch] = $this->acceptDelivery($deliveryId, $project, (string) $event, $commitHash, $branch);
            if (! $shouldDispatch) {
                AuditLogEntry::record(AuditLogEntry::ACTOR_GITHUB, $projectKey, 'webhook.duplicate_delivery_rejected', Project::class, $project->id, ['event' => $event, 'delivery_id' => $deliveryId], $request->ip());

                return response()->json(['message' => 'Duplicate delivery, already processed']);
            }
        }

        try {
            ProcessGitHubWebhookPush::dispatch($project, $commitHash, $branch, $pusher, $delivery?->id);
            if ($delivery !== null) {
                WebhookDelivery::whereKey($delivery->id)->whereIn('status', [WebhookDelivery::STATUS_ACCEPTED, WebhookDelivery::STATUS_FAILED])
                    ->update(['status' => WebhookDelivery::STATUS_PENDING, 'dispatched_at' => now(), 'failed_at' => null, 'failure_message' => null]);
            }
        } catch (\Throwable $e) {
            if ($delivery !== null) {
                $delivery->update(['status' => WebhookDelivery::STATUS_FAILED, 'failed_at' => now(), 'failure_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
            throw $e;
        }

        return response()->json(['message' => 'Push accepted, release readiness is being prepared'], Response::HTTP_ACCEPTED);
    }

    /** @return array{0: WebhookDelivery, 1: bool} */
    private function acceptDelivery(string $deliveryId, Project $project, string $event, string $commitHash, string $branch): array
    {
        try {
            return DB::transaction(function () use ($deliveryId, $project, $event, $commitHash, $branch): array {
                $delivery = WebhookDelivery::query()->where('delivery_id', $deliveryId)->lockForUpdate()->first();
                if ($delivery !== null) {
                    abort_unless($delivery->project_id === $project->id
                        && ($delivery->commit_hash === null || $delivery->commit_hash === $commitHash)
                        && ($delivery->branch === null || $delivery->branch === $branch), 409, 'Webhook delivery identity conflicts with the original accepted payload.');

                    return [$delivery, $delivery->status === WebhookDelivery::STATUS_FAILED
                        || ($delivery->status === WebhookDelivery::STATUS_ACCEPTED && $delivery->dispatched_at === null)];
                }

                return [WebhookDelivery::create([
                    'delivery_id' => $deliveryId, 'project_id' => $project->id, 'event' => $event,
                    'status' => WebhookDelivery::STATUS_ACCEPTED, 'commit_hash' => $commitHash, 'branch' => $branch,
                ]), true];
            });
        } catch (QueryException $e) {
            $delivery = WebhookDelivery::where('delivery_id', $deliveryId)->first();
            if ($delivery !== null && $delivery->project_id === $project->id && $delivery->commit_hash === $commitHash && $delivery->branch === $branch) {
                return [$delivery, false];
            }
            throw $e;
        }
    }
}
