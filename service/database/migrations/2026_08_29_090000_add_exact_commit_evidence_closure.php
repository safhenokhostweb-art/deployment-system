<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->string('active_build_key')->nullable()->unique()->after('release_id');
            $table->json('evidence_contract')->nullable()->after('active_build_key');
            $table->json('release_evidence')->nullable()->after('evidence_contract');
            $table->string('evidence_digest', 64)->nullable()->after('release_evidence');
            $table->timestamp('evidence_verified_at')->nullable()->after('evidence_digest');
        });

        Schema::table('artifacts', function (Blueprint $table) {
            $table->foreignId('publish_job_id')->nullable()->after('release_id')->constrained('publish_jobs')->nullOnDelete();
            $table->string('semantic_status')->default('legacy')->after('status');
            $table->timestamp('semantic_verified_at')->nullable()->after('semantic_status');
            $table->string('semantic_failure')->nullable()->after('semantic_verified_at');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('status')->default('accepted')->after('event');
            $table->string('commit_hash', 40)->nullable()->after('status');
            $table->string('branch')->nullable()->after('commit_hash');
            $table->timestamp('dispatched_at')->nullable()->after('branch');
            $table->timestamp('processing_at')->nullable()->after('dispatched_at');
            $table->timestamp('processed_at')->nullable()->after('processing_at');
            $table->timestamp('failed_at')->nullable()->after('processed_at');
            $table->text('failure_message')->nullable()->after('failed_at');
            $table->timestamp('updated_at')->nullable();
        });

        // Rows created by the pre-state-machine controller were only ever
        // persisted as the terminal dedupe receipt, so grandfather them as
        // processed rather than replaying historical pushes after rollout.
        DB::table('webhook_deliveries')->update([
            'status' => 'processed',
            'processed_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn(['status', 'commit_hash', 'branch', 'dispatched_at', 'processing_at', 'processed_at', 'failed_at', 'failure_message', 'updated_at']);
        });
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publish_job_id');
            $table->dropColumn(['semantic_status', 'semantic_verified_at', 'semantic_failure']);
        });
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropUnique(['active_build_key']);
            $table->dropColumn(['active_build_key', 'evidence_contract', 'release_evidence', 'evidence_digest', 'evidence_verified_at']);
        });
    }
};
