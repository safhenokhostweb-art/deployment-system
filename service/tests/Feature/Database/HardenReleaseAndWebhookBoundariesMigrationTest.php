<?php

namespace Tests\Feature\Database;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises the REAL migration file against representative pre-hardening
 * data, not a re-implementation of its logic: down() strips exactly the
 * columns/indexes this migration itself added (every other table's schema
 * is untouched), historical-shaped rows are seeded into that reverted
 * schema exactly as they would have existed in production before this
 * migration ever ran, then up() is re-invoked and the resulting data is
 * asserted.
 */
class HardenReleaseAndWebhookBoundariesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_30_090000_harden_release_and_webhook_boundaries.php');
    }

    public function test_migration_grandfathers_explicit_legacy_sources_and_corrects_unevidenced_ready_releases(): void
    {
        $migration = $this->migration();
        $migration->down();

        $project = Project::factory()->create();

        // 1. A historically explicit legacy-contract Release, already ready
        //    before this migration ever existed.
        $contractRelease = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('1', 40),
            'branch' => 'main',
            'source' => 'project_contract',
            'status' => Release::STATUS_READY,
            'metadata' => ['release_ready' => true],
        ]);

        // 2. An ordinary webhook Release that reached "ready" under the OLD
        //    (pre-hardening) implicit-signed-fallback bug: no genuine
        //    evidence-verified build behind it at all.
        $unevidencedReady = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('2', 40),
            'branch' => 'main',
            'source' => 'webhook',
            'status' => Release::STATUS_READY,
            'metadata' => ['release_ready' => true, 'changed_files' => ['a.php']],
        ]);

        // 3. An ordinary webhook Release that reached "ready" LEGITIMATELY: a
        //    real succeeded, evidence-verified build_artifact PublishJob and
        //    a matching verified Artifact.
        $evidencedRelease = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('3', 40),
            'branch' => 'main',
            'source' => 'webhook',
            'status' => Release::STATUS_READY,
            'metadata' => ['release_ready' => true],
        ]);
        $evidenceJob = PublishJob::factory()->create([
            'project_id' => $project->id,
            'release_id' => $evidencedRelease->id,
            'action' => PublishJob::ACTION_BUILD_ARTIFACT,
            'status' => PublishJob::STATUS_SUCCEEDED,
            'evidence_verified_at' => now(),
            'reviewed_head_sha' => $evidencedRelease->commit_hash,
            'reviewed_branch' => 'main',
        ]);
        Artifact::factory()->create([
            'project_id' => $project->id,
            'release_id' => $evidencedRelease->id,
            'publish_job_id' => $evidenceJob->id,
            'commit_hash' => $evidencedRelease->commit_hash,
            'branch' => 'main',
            'status' => Artifact::STATUS_AVAILABLE,
            'semantic_status' => 'verified',
        ]);

        // 4. An ordinary, not-yet-ready webhook Release -- should simply get
        //    the exact-commit default policy and nothing else touched.
        $ordinaryRelease = Release::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('4', 40),
            'branch' => 'main',
            'source' => 'webhook',
            'status' => Release::STATUS_AWAITING_ARTIFACT,
        ]);

        // A representative historical webhook_deliveries row: a delivery
        // successfully processed long before per-dispatch ownership tokens
        // existed at all.
        $legacyDelivery = WebhookDelivery::create([
            'delivery_id' => 'historical-delivery-1',
            'project_id' => $project->id,
            'event' => 'push',
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'commit_hash' => str_repeat('5', 40),
            'branch' => 'main',
            'processed_at' => now()->subDays(30),
        ]);

        $migration->up();

        $contractRelease->refresh();
        $this->assertSame(Release::READINESS_POLICY_LEGACY_FALLBACK, $contractRelease->readiness_policy);
        $this->assertSame('historical_explicit_legacy_source', $contractRelease->legacy_fallback_reason);
        $this->assertSame('system:migration', $contractRelease->legacy_fallback_eligible_by);
        $this->assertNotNull($contractRelease->legacy_fallback_eligible_at);
        $this->assertSame(Release::STATUS_READY, $contractRelease->status, 'A grandfathered legacy-contract release keeps its ready state.');

        $unevidencedReady->refresh();
        $this->assertSame(Release::READINESS_POLICY_EXACT_COMMIT, $unevidencedReady->readiness_policy);
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $unevidencedReady->status, 'An unevidenced ready release must be corrected to awaiting_artifact.');
        $this->assertNull($unevidencedReady->ready_at);
        $this->assertFalse($unevidencedReady->metadata['release_ready']);
        $this->assertSame(['a.php'], $unevidencedReady->metadata['changed_files'], 'Unrelated metadata must survive the correction untouched.');

        $evidencedRelease->refresh();
        $this->assertSame(Release::READINESS_POLICY_EXACT_COMMIT, $evidencedRelease->readiness_policy);
        $this->assertSame(Release::STATUS_READY, $evidencedRelease->status, 'A genuinely evidence-backed ready release must survive the migration untouched.');

        $ordinaryRelease->refresh();
        $this->assertSame(Release::READINESS_POLICY_EXACT_COMMIT, $ordinaryRelease->readiness_policy);
        $this->assertSame(Release::STATUS_AWAITING_ARTIFACT, $ordinaryRelease->status);

        $legacyDelivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $legacyDelivery->status);
        $this->assertNull($legacyDelivery->processed_at);
        $this->assertNotNull($legacyDelivery->failed_at);
        $this->assertSame('legacy_dispatch_ownership_unconfirmed', $legacyDelivery->failure_message);
        $this->assertNull($legacyDelivery->dispatch_token);
    }

    public function test_migration_down_cleanly_removes_added_columns_and_indexes(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('releases', 'readiness_policy'));
        $this->assertFalse(Schema::hasColumn('releases', 'legacy_fallback_eligible_at'));
        $this->assertFalse(Schema::hasColumn('webhook_deliveries', 'dispatch_token'));
        $this->assertFalse(Schema::hasColumn('webhook_deliveries', 'processing_lease_expires_at'));

        // Re-apply so a following test in the same run never inherits a
        // half-migrated schema (RefreshDatabase's transaction rollback
        // already guarantees this in practice, but this keeps the test
        // self-contained regardless of that mechanism).
        $migration->up();
        $this->assertTrue(Schema::hasColumn('releases', 'readiness_policy'));
        $this->assertTrue(Schema::hasColumn('webhook_deliveries', 'dispatch_token'));
    }
}
