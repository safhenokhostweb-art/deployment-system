<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-K Task 11: Server Health Foundation — purely additive, nullable
 * columns reported on the *existing* heartbeat call (no new endpoint, no
 * new poll cycle). Every existing Agent row, and every agent binary that
 * predates this phase, simply never populates these — heartbeat's
 * existing required fields (agent_version) are completely unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->float('cpu_load_percent')->nullable()->after('capabilities');
            $table->float('memory_usage_percent')->nullable()->after('cpu_load_percent');
            $table->float('disk_usage_percent')->nullable()->after('memory_usage_percent');
            $table->timestamp('health_reported_at')->nullable()->after('disk_usage_percent');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['cpu_load_percent', 'memory_usage_percent', 'disk_usage_percent', 'health_reported_at']);
        });
    }
};
