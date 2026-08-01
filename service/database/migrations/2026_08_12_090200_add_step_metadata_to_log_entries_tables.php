<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix: a recipe step needs an explicit lifecycle status
 * (queued/running/succeeded/failed/skipped — Phase 1's own step state
 * machine) distinct from the pre-existing exit_code/timed_out columns,
 * since a `skipped` step (e.g. a health check the recipe never reached
 * because an earlier step failed) has neither. `step_type` records which
 * typed step ran (null = a legacy `pipeline` shell step, unaffected).
 * `failure_code` mirrors the same column added to `deployments` in this
 * same phase, at per-step granularity.
 *
 * Deliberately nullable with NO default and no backfill: every
 * pre-existing row already represents a step that reached a terminal
 * outcome (this table is only ever written to after a step finishes —
 * see AgentJobService::recordLog()), so inventing a default here would
 * be guessing at history. Anything reading `status` must fall back to
 * `exit_code === 0 ? 'succeeded' : 'failed'` when it's null, exactly the
 * meaning every pre-existing row already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_log_entries', function (Blueprint $table) {
            $table->string('status')->nullable()->after('timed_out');
            $table->string('step_type')->nullable()->after('status');
            $table->string('failure_code')->nullable()->after('step_type');
        });

        Schema::table('agent_job_log_entries', function (Blueprint $table) {
            $table->string('status')->nullable()->after('timed_out');
            $table->string('step_type')->nullable()->after('status');
            $table->string('failure_code')->nullable()->after('step_type');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_log_entries', function (Blueprint $table) {
            $table->dropColumn(['status', 'step_type', 'failure_code']);
        });

        Schema::table('agent_job_log_entries', function (Blueprint $table) {
            $table->dropColumn(['status', 'step_type', 'failure_code']);
        });
    }
};
