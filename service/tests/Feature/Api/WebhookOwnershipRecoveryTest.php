<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Release;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryOwnershipService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookOwnershipRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_processing_is_not_replayed_but_stale_processing_is_reclaimed(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $first = $service->acceptAndClaim('lease-delivery', $project, 'push', str_repeat('a', 40), 'main');
        $this->assertTrue($first['should_dispatch']);
        $this->assertSame($first['token'], $service->claimProcessing($first['delivery']->id, $first['token']));

        $active = $service->acceptAndClaim('lease-delivery', $project, 'push', str_repeat('a', 40), 'main');
        $this->assertFalse($active['should_dispatch']);

        $this->travel(WebhookDeliveryOwnershipService::PROCESSING_LEASE_SECONDS + 1)->seconds();
        $recovered = $service->acceptAndClaim('lease-delivery', $project, 'push', str_repeat('a', 40), 'main');
        $this->assertTrue($recovered['should_dispatch']);
        $this->assertNotSame($first['token'], $recovered['token']);
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $recovered['delivery']->status);
    }

    public function test_processed_is_idempotent_and_failed_is_replayable(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('terminal-delivery', $project, 'push', str_repeat('b', 40), 'main');
        $token = $service->claimProcessing($claim['delivery']->id, $claim['token']);
        $service->markProcessed($claim['delivery']->id, $token);

        $processed = $service->acceptAndClaim('terminal-delivery', $project, 'push', str_repeat('b', 40), 'main');
        $this->assertFalse($processed['should_dispatch']);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $processed['delivery']->status);

        $failedClaim = $service->acceptAndClaim('failed-delivery', $project, 'push', str_repeat('c', 40), 'main');
        $service->markFailed($failedClaim['delivery']->id, $failedClaim['token'], 'enqueue failed');
        $replay = $service->acceptAndClaim('failed-delivery', $project, 'push', str_repeat('c', 40), 'main');
        $this->assertTrue($replay['should_dispatch']);
        $this->assertNotSame($failedClaim['token'], $replay['token']);
    }

    public function test_legacy_unowned_receipt_backfill_is_conservatively_replayable(): void
    {
        $project = Project::factory()->create();
        WebhookDelivery::create([
            'delivery_id' => 'legacy-unowned',
            'project_id' => $project->id,
            'event' => 'push',
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'commit_hash' => str_repeat('d', 40),
            'branch' => 'main',
            'processed_at' => now(),
        ]);

        $this->assertSame(1, WebhookDeliveryOwnershipService::markLegacyReceiptsReplayable());
        $receipt = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $receipt->status);
        $this->assertNull($receipt->processed_at);
        $this->assertSame('legacy_dispatch_ownership_unconfirmed', $receipt->failure_message);

        $claim = app(WebhookDeliveryOwnershipService::class)->acceptAndClaim(
            'legacy-unowned', $project, 'push', str_repeat('d', 40), 'main'
        );
        $this->assertTrue($claim['should_dispatch']);
    }

    public function test_simultaneous_duplicate_claims_produce_one_dispatch_owner(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'webhook-concurrency-');
        $connection = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ];
        config(['database.connections.webhook_concurrency' => $connection]);
        Schema::connection('webhook_concurrency')->create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('event')->nullable();
            $table->string('status')->default('accepted');
            $table->string('commit_hash', 40)->nullable();
            $table->string('branch')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->timestamp('dispatch_lease_expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });

        $startAt = microtime(true) + 1.0;
        $task = static function () use ($connection, $startAt): array {
            config([
                'database.default' => 'webhook_concurrency',
                'database.connections.webhook_concurrency' => $connection,
            ]);
            DB::purge('webhook_concurrency');
            DB::connection('webhook_concurrency')->statement('PRAGMA busy_timeout = 5000');
            while (microtime(true) < $startAt) {
                usleep(1000);
            }
            $project = new Project;
            $project->forceFill(['id' => 1]);
            try {
                $claim = app(WebhookDeliveryOwnershipService::class)->acceptAndClaim(
                    'simultaneous-delivery', $project, 'push', str_repeat('e', 40), 'main'
                );

                return ['owned' => $claim['should_dispatch'], 'token' => $claim['token'], 'error' => null];
            } catch (\Throwable $e) {
                return ['owned' => false, 'token' => null, 'error' => $e::class.': '.$e->getMessage()];
            }
        };

        try {
            $results = Concurrency::driver('process')->run([$task, $task], 20);
            $this->assertSame([], array_values(array_filter(array_column($results, 'error'))));
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['owned'])));
            $this->assertSame(1, count(array_unique(array_filter(array_column($results, 'token')))));
        } finally {
            DB::purge('webhook_concurrency');
            @unlink($database);
            @unlink($database.'-shm');
            @unlink($database.'-wal');
        }
    }

    /**
     * Root-cause fix: a hard worker death (kill -9, OOM, host crash)
     * mid-handle() leaves the row stuck at PROCESSING with no exception
     * ever thrown to release it. This must recover deterministically --
     * never depending on GitHub redelivering the same event or on the
     * underlying queue driver's own retry_after cycle ever firing again.
     */
    public function test_expired_processing_with_no_queue_retry_is_reclaimed(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('stuck-delivery', $project, 'push', str_repeat('a', 40), 'main');
        $service->claimProcessing($claim['delivery']->id, $claim['token']);

        // No exception, no failed(), no queue retry -- exactly a hard kill.
        $this->travel(WebhookDeliveryOwnershipService::PROCESSING_LEASE_SECONDS + 1)->seconds();

        $result = $service->reclaimExpiredProcessing();

        $this->assertSame(['checked' => 1, 'reclaimed' => 1, 'exhausted' => 0], $result);
        $delivery = $claim['delivery']->fresh();
        // The test queue driver is sync, so by the time reclaimExpiredProcessing()
        // returns the requeued job has already run to completion inline --
        // PENDING is never observable here. dispatch_token is untouched by
        // markProcessed(), so it still proves a genuinely new token was
        // issued (never the dead one reused) regardless of queue timing.
        $this->assertNotSame($claim['token'], $delivery->dispatch_token, 'a genuinely new dispatch token must be issued, never the dead one reused');
        $this->assertSame(1, $delivery->reclaim_count);
        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'webhook_delivery.reclaimed_after_expired_lease',
            'subject_id' => $delivery->id,
        ]);

        // The requeued dispatch must actually complete the original work.
        $this->assertDatabaseHas('releases', ['project_id' => $project->id, 'commit_hash' => str_repeat('a', 40)]);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
    }

    public function test_unexpired_processing_is_left_untouched(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('fresh-delivery', $project, 'push', str_repeat('b', 40), 'main');
        $service->claimProcessing($claim['delivery']->id, $claim['token']);

        // Still well within PROCESSING_LEASE_SECONDS -- a worker may
        // legitimately still be running it.
        $result = $service->reclaimExpiredProcessing();

        $this->assertSame(['checked' => 0, 'reclaimed' => 0, 'exhausted' => 0], $result);
        $delivery = $claim['delivery']->fresh();
        $this->assertSame(WebhookDelivery::STATUS_PROCESSING, $delivery->status);
        $this->assertSame($claim['token'], $delivery->dispatch_token);
        $this->assertSame(0, $delivery->reclaim_count);
    }

    public function test_processed_delivery_is_left_untouched(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('done-delivery', $project, 'push', str_repeat('c', 40), 'main');
        $token = $service->claimProcessing($claim['delivery']->id, $claim['token']);
        $service->markProcessed($claim['delivery']->id, $token);

        // Force the lease field to look "expired" even though the delivery
        // already reached its real terminal state -- the sweep must key off
        // status, never merely a stale lease timestamp.
        $claim['delivery']->forceFill(['processing_lease_expires_at' => now()->subMinute()])->save();

        $result = $service->reclaimExpiredProcessing();

        $this->assertSame(['checked' => 0, 'reclaimed' => 0, 'exhausted' => 0], $result);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $claim['delivery']->fresh()->status);
    }

    /**
     * Real OS-process concurrency, matching
     * test_simultaneous_duplicate_claims_produce_one_dispatch_owner's own
     * rigor: two independent processes sweep the SAME expired row at
     * (as close as practically possible to) the same instant. Exactly one
     * may reclaim it -- the other must find it already moved off
     * PROCESSING by the time it acquires the row lock.
     */
    public function test_concurrent_recovery_attempts_produce_exactly_one_reclaim(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'webhook-reclaim-concurrency-');
        $connection = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ];
        config(['database.connections.webhook_reclaim_concurrency' => $connection]);
        Schema::connection('webhook_reclaim_concurrency')->create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('event')->nullable();
            $table->string('status')->default('accepted');
            $table->string('commit_hash', 40)->nullable();
            $table->string('branch')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->timestamp('dispatch_lease_expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->unsignedInteger('reclaim_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });
        // Empty on purpose: reclaimExpiredProcessing() looks the project up
        // by id and skips dispatch entirely when it's not found, so a
        // schema-only table lets the ownership-transition half (the actual
        // safety-critical, concurrency-sensitive part) be proven in
        // isolation without needing the full Release/PublishJob schema
        // replicated into this standalone database.
        Schema::connection('webhook_reclaim_concurrency')->create('projects', function (Blueprint $table): void {
            $table->id();
        });
        Schema::connection('webhook_reclaim_concurrency')->create('audit_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type');
            $table->string('actor_identifier')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        DB::connection('webhook_reclaim_concurrency')->table('webhook_deliveries')->insert([
            'delivery_id' => 'racing-reclaim-delivery',
            'project_id' => null,
            'event' => 'push',
            'status' => WebhookDelivery::STATUS_PROCESSING,
            'commit_hash' => str_repeat('d', 40),
            'branch' => 'main',
            'dispatch_token' => (string) \Illuminate\Support\Str::uuid(),
            'processing_at' => now()->subMinutes(5),
            'processing_lease_expires_at' => now()->subMinute(),
            'reclaim_count' => 0,
        ]);

        $startAt = microtime(true) + 1.0;
        $task = static function () use ($connection, $startAt): array {
            config([
                'database.default' => 'webhook_reclaim_concurrency',
                'database.connections.webhook_reclaim_concurrency' => $connection,
            ]);
            DB::purge('webhook_reclaim_concurrency');
            DB::connection('webhook_reclaim_concurrency')->statement('PRAGMA busy_timeout = 5000');
            while (microtime(true) < $startAt) {
                usleep(1000);
            }
            try {
                $result = app(WebhookDeliveryOwnershipService::class)->reclaimExpiredProcessing();

                return ['result' => $result, 'error' => null];
            } catch (\Throwable $e) {
                return ['result' => null, 'error' => $e::class.': '.$e->getMessage()];
            }
        };

        try {
            $results = Concurrency::driver('process')->run([$task, $task], 20);
            $this->assertSame([], array_values(array_filter(array_column($results, 'error'))));
            $totalReclaimed = array_sum(array_column(array_column($results, 'result'), 'reclaimed'));
            $this->assertSame(1, $totalReclaimed, 'exactly one of the two concurrent sweeps may reclaim the same row');

            $final = DB::connection('webhook_reclaim_concurrency')->table('webhook_deliveries')->where('delivery_id', 'racing-reclaim-delivery')->first();
            $this->assertSame(WebhookDelivery::STATUS_PENDING, $final->status);
            $this->assertSame(1, (int) $final->reclaim_count, 'a duplicate reclaim would show up here as 2');
        } finally {
            DB::purge('webhook_reclaim_concurrency');
            @unlink($database);
            @unlink($database.'-shm');
            @unlink($database.'-wal');
        }
    }

    public function test_recovered_delivery_still_deduplicates_a_repeated_github_delivery(): void
    {
        $project = Project::factory()->create();
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('redelivered-after-reclaim', $project, 'push', str_repeat('e', 40), 'main');
        $service->claimProcessing($claim['delivery']->id, $claim['token']);
        $this->travel(WebhookDeliveryOwnershipService::PROCESSING_LEASE_SECONDS + 1)->seconds();
        $service->reclaimExpiredProcessing();

        // GitHub redelivers the exact same event while the reclaimed job is
        // (in this test) already processed -- must still be recognized as
        // the same delivery, never processed a second time.
        $redelivery = $service->acceptAndClaim('redelivered-after-reclaim', $project, 'push', str_repeat('e', 40), 'main');

        $this->assertFalse($redelivery['should_dispatch']);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $redelivery['delivery']->status);
        $this->assertSame(1, Release::where('project_id', $project->id)->count(), 'the reclaim + the redelivery must never both create work');
    }

    public function test_recovery_never_creates_a_deployment_or_bypasses_confirm(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $service = app(WebhookDeliveryOwnershipService::class);
        $claim = $service->acceptAndClaim('no-deploy-on-reclaim', $project, 'push', str_repeat('f', 40), 'main');
        $service->claimProcessing($claim['delivery']->id, $claim['token']);
        $this->travel(WebhookDeliveryOwnershipService::PROCESSING_LEASE_SECONDS + 1)->seconds();

        $service->reclaimExpiredProcessing();

        $this->assertDatabaseCount('deployments', 0);
        $release = Release::where('project_id', $project->id)->sole();
        $this->assertNotSame(Release::STATUS_READY, $release->status, 'a bare recovered webhook push alone must never reach ready, let alone deploy');
    }
}
