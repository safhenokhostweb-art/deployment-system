<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('readiness_policy')->default('exact_commit_evidence_v1')->after('source');
            $table->timestamp('legacy_fallback_eligible_at')->nullable()->after('readiness_policy');
            $table->string('legacy_fallback_reason')->nullable()->after('legacy_fallback_eligible_at');
            $table->string('legacy_fallback_eligible_by')->nullable()->after('legacy_fallback_reason');
        });

        // Only historically explicit legacy/import contracts are grandfathered.
        // A webhook Release that later received signed/admin metadata retains
        // source=webhook and therefore remains on the exact-commit policy.
        DB::table('releases')
            ->whereIn('source', ['project_contract', 'admin_backfill', 'import', 'legacy'])
            ->update([
                'readiness_policy' => 'legacy_fallback_v1',
                'legacy_fallback_eligible_at' => now(),
                'legacy_fallback_reason' => 'historical_explicit_legacy_source',
                'legacy_fallback_eligible_by' => 'system:migration',
            ]);

        // Correct any READY row that was produced by the former implicit
        // signed fallback. Evidence-backed rows keep their state; ordinary
        // rows without a succeeded job-bound semantic Artifact fail closed.
        DB::table('releases')
            ->where('status', 'ready')
            ->where('readiness_policy', 'exact_commit_evidence_v1')
            ->orderBy('id')
            ->chunkById(100, function ($releases): void {
                foreach ($releases as $release) {
                    $evidenceJob = DB::table('publish_jobs')
                        ->where('release_id', $release->id)
                        ->where('action', 'build_artifact')
                        ->where('status', 'succeeded')
                        ->whereNotNull('evidence_verified_at')
                        ->where('reviewed_head_sha', $release->commit_hash)
                        ->where('reviewed_branch', $release->branch)
                        ->first();

                    $hasArtifact = $evidenceJob !== null && DB::table('artifacts')
                        ->where('publish_job_id', $evidenceJob->id)
                        ->where('release_id', $release->id)
                        ->where('project_id', $release->project_id)
                        ->where('commit_hash', $release->commit_hash)
                        ->where('branch', $release->branch)
                        ->where('status', 'available')
                        ->where('semantic_status', 'verified')
                        ->exists();

                    if ($hasArtifact) {
                        continue;
                    }

                    $metadata = is_string($release->metadata) ? json_decode($release->metadata, true) : null;
                    if (is_array($metadata)) {
                        $metadata['release_ready'] = false;
                    }
                    DB::table('releases')->where('id', $release->id)->update([
                        'status' => 'awaiting_artifact',
                        'ready_at' => null,
                        'metadata' => is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $release->metadata,
                    ]);
                }
            });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('dispatch_token')->nullable()->after('branch');
            $table->timestamp('dispatch_claimed_at')->nullable()->after('dispatch_token');
            $table->timestamp('dispatch_lease_expires_at')->nullable()->after('dispatch_claimed_at');
            $table->timestamp('processing_lease_expires_at')->nullable()->after('processing_at');
            $table->index(['status', 'dispatch_lease_expires_at'], 'webhook_dispatch_recovery_idx');
            $table->index(['status', 'processing_lease_expires_at'], 'webhook_processing_recovery_idx');
        });

        // The old receipt format cannot prove that its out-of-transaction
        // dispatch completed. Mark it replayable, never processed by fiat.
        DB::table('webhook_deliveries')->whereNull('dispatch_token')->update([
            'status' => 'failed',
            'processed_at' => null,
            'failed_at' => now(),
            'failure_message' => 'legacy_dispatch_ownership_unconfirmed',
        ]);
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('webhook_dispatch_recovery_idx');
            $table->dropIndex('webhook_processing_recovery_idx');
            $table->dropColumn(['dispatch_token', 'dispatch_claimed_at', 'dispatch_lease_expires_at', 'processing_lease_expires_at']);
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['readiness_policy', 'legacy_fallback_eligible_at', 'legacy_fallback_reason', 'legacy_fallback_eligible_by']);
        });
    }
};
