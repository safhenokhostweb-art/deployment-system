<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B: persist the latest valid runtime snapshot the Local Agent
 * reports on its signed heartbeat so AgentRuntimeEvidenceReader can read
 * REAL stored evidence instead of reconstructing it from Hub records.
 *
 * The column is JSON, nullable, and never stores raw payload or
 * secret-shaped data -- the controller validates every incoming snapshot
 * through SentinelRuntimeEvidenceContract before writing, and the
 * contract itself rejects credential-shaped values and path escapes.
 *
 * NOT executed in this phase; created only. See Phase 3B report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_agents', function (Blueprint $table) {
            $table->json('runtime_snapshot')->nullable()->after('last_seen_at');
            $table->unsignedBigInteger('runtime_snapshot_sequence')->nullable()->after('runtime_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('local_agents', function (Blueprint $table) {
            $table->dropColumn('runtime_snapshot');
            $table->dropColumn('runtime_snapshot_sequence');
        });
    }
};
