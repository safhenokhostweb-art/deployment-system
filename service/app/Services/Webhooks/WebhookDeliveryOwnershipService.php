<?php

namespace App\Services\Webhooks;

use App\Jobs\ProcessGitHubWebhookPush;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\WebhookDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WebhookDeliveryOwnershipService
{
    public const DISPATCH_LEASE_SECONDS = 900;

    public const PROCESSING_LEASE_SECONDS = 120;

    /**
     * Ceiling on how many times reclaimExpiredProcessing() will requeue the
     * SAME delivery. Each reclaim hands a brand new Laravel job its own
     * fresh $tries budget, so nothing at the queue level bounds a delivery
     * that keeps killing every worker that picks it up (a poison-pill
     * payload) -- this is that bound, at the ownership/delivery level.
     */
    public const MAX_RECLAIM_ATTEMPTS = 5;

    public static function markLegacyReceiptsReplayable(): int
    {
        return DB::table('webhook_deliveries')->whereNull('dispatch_token')->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'processed_at' => null,
            'failed_at' => now(),
            'failure_message' => 'legacy_dispatch_ownership_unconfirmed',
        ]);
    }

    /** @return array{delivery:WebhookDelivery, token:?string, should_dispatch:bool} */
    public function acceptAndClaim(string $deliveryId, Project $project, string $event, string $commitHash, string $branch): array
    {
        try {
            return DB::transaction(function () use ($deliveryId, $project, $event, $commitHash, $branch): array {
                $delivery = WebhookDelivery::query()->where('delivery_id', $deliveryId)->lockForUpdate()->first();
                if ($delivery === null) {
                    $delivery = WebhookDelivery::create([
                        'delivery_id' => $deliveryId,
                        'project_id' => $project->id,
                        'event' => $event,
                        'status' => WebhookDelivery::STATUS_ACCEPTED,
                        'commit_hash' => $commitHash,
                        'branch' => $branch,
                    ]);
                }

                return $this->claimLockedDelivery($delivery, $project, $commitHash, $branch);
            });
        } catch (QueryException $e) {
            // A concurrent INSERT may win the unique delivery_id race.
            // Re-enter through the same locked state transition; never
            // interpret the constraint error itself as dispatch ownership.
            // SQLite reports the competing writer as "database is locked"
            // rather than a unique violation; MySQL may likewise surface a
            // transient deadlock. Brief bounded retries let the winner commit.
            for ($attempt = 0; $attempt < 10; $attempt++) {
                usleep(50_000);
                try {
                    $delivery = WebhookDelivery::query()->where('delivery_id', $deliveryId)->first();
                    if ($delivery !== null) {
                        return DB::transaction(function () use ($deliveryId, $project, $commitHash, $branch): array {
                            $locked = WebhookDelivery::query()->where('delivery_id', $deliveryId)->lockForUpdate()->firstOrFail();

                            return $this->claimLockedDelivery($locked, $project, $commitHash, $branch);
                        });
                    }
                } catch (QueryException) {
                    // The winning transaction still owns the write lock.
                }
            }
            throw $e;
        }
    }

    public function claimProcessing(int $deliveryId, ?string $token): ?string
    {
        return DB::transaction(function () use ($deliveryId, $token): ?string {
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || $delivery->status === WebhookDelivery::STATUS_PROCESSED) {
                return null;
            }

            // Compatibility for a genuinely queued pre-ownership job. The
            // migration makes its unowned receipt failed/replayable; whichever
            // arrives first (old job or redelivery) acquires the only token.
            if ($token === null && $delivery->dispatch_token === null && $delivery->status === WebhookDelivery::STATUS_FAILED) {
                $token = (string) Str::uuid();
                $delivery->forceFill([
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'dispatch_token' => $token,
                    'dispatch_claimed_at' => now(),
                    'dispatch_lease_expires_at' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
                ])->save();
            }

            // A hard worker death (kill -9, OOM, host crash) mid-handle()
            // leaves the row stuck at PROCESSING forever with no exception
            // ever thrown -- no catch block runs, so nothing transitions it
            // back to PENDING. Laravel's own queue-level recovery (a
            // database/redis "retry_after" reservation timeout) then
            // re-invokes the EXACT SAME serialized job/token, expecting to
            // actually attempt the work again. Requiring PENDING here would
            // make that native retry a permanent silent no-op -- the
            // matching token is already the only proof of ownership that
            // matters, so accept resumption from PROCESSING too.
            if (! is_string($token)
                || ! hash_equals((string) $delivery->dispatch_token, $token)
                || ! in_array($delivery->status, [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_PROCESSING], true)) {
                return null;
            }

            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_PROCESSING,
                'processing_at' => now(),
                'processing_lease_expires_at' => now()->addSeconds(self::PROCESSING_LEASE_SECONDS),
                'failure_message' => null,
            ])->save();

            return $token;
        });
    }

    public function releaseForQueueRetry(int $deliveryId, string $token): void
    {
        WebhookDelivery::query()
            ->whereKey($deliveryId)
            ->where('dispatch_token', $token)
            ->where('status', WebhookDelivery::STATUS_PROCESSING)
            ->update([
                'status' => WebhookDelivery::STATUS_PENDING,
                'processing_at' => null,
                'processing_lease_expires_at' => null,
                'dispatch_lease_expires_at' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
            ]);
    }

    public function markProcessed(int $deliveryId, string $token): void
    {
        WebhookDelivery::query()
            ->whereKey($deliveryId)
            ->where('dispatch_token', $token)
            ->where('status', WebhookDelivery::STATUS_PROCESSING)
            ->update([
                'status' => WebhookDelivery::STATUS_PROCESSED,
                'processed_at' => now(),
                'dispatch_lease_expires_at' => null,
                'processing_lease_expires_at' => null,
                'failed_at' => null,
                'failure_message' => null,
            ]);
    }

    public function markFailed(int $deliveryId, string $token, string $message): void
    {
        WebhookDelivery::query()
            ->whereKey($deliveryId)
            ->where('dispatch_token', $token)
            ->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_PROCESSING])
            ->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'dispatch_lease_expires_at' => null,
                'processing_lease_expires_at' => null,
                'failed_at' => now(),
                'failure_message' => mb_substr($message, 0, 2000),
            ]);
    }

    /**
     * Deterministic recovery for a delivery stuck at PROCESSING past its
     * lease with no exception ever thrown to release it: a hard-killed
     * worker (kill -9, OOM, host crash) never runs a catch/finally block,
     * so nothing transitions the row back to PENDING on its own. This must
     * never depend on GitHub redelivering the same event, or on the
     * underlying queue driver's own retry_after cycle -- both are real
     * recovery paths (claimProcessing() already accepts a matching-token
     * resume for the latter), but neither is guaranteed to occur, and this
     * sweep is what makes recovery unconditional. Reuses the SAME durable
     * identity already recorded on the row (never re-derived from a
     * request), issues a genuinely new dispatch token, and is bounded by
     * reclaim_count so a systemically poisoned payload fails closed
     * instead of being requeued forever.
     *
     * Idempotent and concurrency-safe by the same pattern as
     * StalePublishJobRecoveryService: each candidate is re-fetched and
     * locked inside its own transaction before acting, so a concurrent
     * sweep run, a native queue retry landing at the same moment, or a
     * fresh GitHub redelivery can never all act on the same row.
     *
     * @return array{checked:int, reclaimed:int, exhausted:int}
     */
    public function reclaimExpiredProcessing(bool $dryRun = false): array
    {
        $result = ['checked' => 0, 'reclaimed' => 0, 'exhausted' => 0];

        $stale = WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_PROCESSING)
            ->where('processing_lease_expires_at', '<', now())
            ->get();

        foreach ($stale as $candidate) {
            $result['checked']++;

            $outcome = $this->reclaimOneWithContentionRetry((int) $candidate->id, $dryRun);
            if ($outcome['classification'] === null) {
                continue;
            }

            $result[$outcome['classification']]++;

            // Dispatched AFTER the transaction commits: the ownership
            // state transition is the atomic, safety-critical part, and
            // must never be entangled with (or rolled back by) the queue
            // driver's own write, especially under the sync driver, which
            // would otherwise run the entire recovered job nested inside
            // this sweep's own transaction.
            if ($outcome['classification'] === 'reclaimed' && isset($outcome['delivery'], $outcome['token'])) {
                /** @var WebhookDelivery $delivery */
                $delivery = $outcome['delivery'];
                $project = Project::find($delivery->project_id);
                if ($project !== null && is_string($delivery->commit_hash) && is_string($delivery->branch)) {
                    ProcessGitHubWebhookPush::dispatch($project, $delivery->commit_hash, $delivery->branch, null, $delivery->id, $outcome['token']);
                }
            }
        }

        return $result;
    }

    /**
     * Same defensive contention retry as acceptAndClaim(): a genuinely
     * competing sweep/redelivery/native-queue-retry racing for the SAME
     * row is already handled correctly by the re-fetch-and-lock re-check
     * inside the transaction (the loser simply finds nothing left to do)
     * -- but SQLite in particular can surface that race as a raw
     * "database is locked" QueryException on the losing writer rather
     * than transparently serializing it, even with busy_timeout
     * configured. Briefly retrying re-reads the row fresh each time, so a
     * retry after a transient failure correctly lands on "already handled"
     * rather than risking a second effective reclaim.
     *
     * @return array{classification: ?string, delivery?: WebhookDelivery, token?: string}
     */
    private function reclaimOneWithContentionRetry(int $deliveryId, bool $dryRun): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return DB::transaction(function () use ($deliveryId, $dryRun): array {
                    $delivery = WebhookDelivery::query()->lockForUpdate()->find($deliveryId);

                    if ($delivery === null
                        || $delivery->status !== WebhookDelivery::STATUS_PROCESSING
                        || $delivery->processing_lease_expires_at === null
                        || $delivery->processing_lease_expires_at->isFuture()) {
                        // Already recovered by a concurrent sweep, a native
                        // queue retry, or a fresh GitHub redelivery.
                        return ['classification' => null];
                    }

                    if ($delivery->reclaim_count >= self::MAX_RECLAIM_ATTEMPTS) {
                        if ($dryRun) {
                            return ['classification' => 'exhausted'];
                        }

                        $delivery->forceFill([
                            'status' => WebhookDelivery::STATUS_FAILED,
                            'dispatch_lease_expires_at' => null,
                            'processing_lease_expires_at' => null,
                            'failed_at' => now(),
                            'failure_message' => 'Exceeded the maximum of ' . self::MAX_RECLAIM_ATTEMPTS . ' automatic reclaim attempts after repeated worker failure.',
                        ])->save();

                        AuditLogEntry::record(
                            actorType: AuditLogEntry::ACTOR_SYSTEM,
                            actorIdentifier: null,
                            action: 'webhook_delivery.reclaim_exhausted',
                            subjectType: WebhookDelivery::class,
                            subjectId: $delivery->id,
                            metadata: ['delivery_id' => $delivery->delivery_id, 'reclaim_count' => $delivery->reclaim_count],
                        );

                        return ['classification' => 'exhausted'];
                    }

                    if ($dryRun) {
                        return ['classification' => 'reclaimed'];
                    }

                    $token = (string) Str::uuid();
                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_PENDING,
                        'dispatch_token' => $token,
                        'dispatch_claimed_at' => now(),
                        'dispatch_lease_expires_at' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
                        'processing_at' => null,
                        'processing_lease_expires_at' => null,
                        'reclaim_count' => $delivery->reclaim_count + 1,
                        'failure_message' => null,
                    ])->save();

                    AuditLogEntry::record(
                        actorType: AuditLogEntry::ACTOR_SYSTEM,
                        actorIdentifier: null,
                        action: 'webhook_delivery.reclaimed_after_expired_lease',
                        subjectType: WebhookDelivery::class,
                        subjectId: $delivery->id,
                        metadata: ['delivery_id' => $delivery->delivery_id, 'reclaim_count' => $delivery->reclaim_count],
                    );

                    return ['classification' => 'reclaimed', 'delivery' => $delivery, 'token' => $token];
                });
            } catch (QueryException $e) {
                if ($attempt === 9) {
                    throw $e;
                }
                usleep(50_000);
            }
        }

        return ['classification' => null];
    }

    /** @return array{delivery:WebhookDelivery, token:?string, should_dispatch:bool} */
    private function claimLockedDelivery(WebhookDelivery $delivery, Project $project, string $commitHash, string $branch): array
    {
        abort_unless($delivery->project_id === $project->id
            && ($delivery->commit_hash === null || $delivery->commit_hash === $commitHash)
            && ($delivery->branch === null || $delivery->branch === $branch), 409, 'Webhook delivery identity conflicts with the original accepted payload.');

        if ($delivery->status === WebhookDelivery::STATUS_PROCESSED) {
            return ['delivery' => $delivery, 'token' => null, 'should_dispatch' => false];
        }

        $activePending = $delivery->status === WebhookDelivery::STATUS_PENDING
            && $delivery->dispatch_lease_expires_at?->isFuture();
        $activeProcessing = $delivery->status === WebhookDelivery::STATUS_PROCESSING
            && $delivery->processing_lease_expires_at?->isFuture();
        if ($activePending || $activeProcessing) {
            return ['delivery' => $delivery, 'token' => null, 'should_dispatch' => false];
        }

        $token = (string) Str::uuid();
        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'dispatch_token' => $token,
            'dispatch_claimed_at' => now(),
            'dispatch_lease_expires_at' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
            'dispatched_at' => now(),
            'processing_at' => null,
            'processing_lease_expires_at' => null,
            'processed_at' => null,
            'failed_at' => null,
            'failure_message' => null,
        ])->save();

        return ['delivery' => $delivery->refresh(), 'token' => $token, 'should_dispatch' => true];
    }
}
