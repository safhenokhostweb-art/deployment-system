<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only, per docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md.
 * Nothing here touches `projects`, `servers`, `deployments`, or
 * `deployment_log_entries` — only the new-domain tables created in
 * Phase 3-C (environments, deployment_tasks, agent_jobs), which have no
 * real data yet, plus one brand-new table for per-attempt logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->foreignId('locked_by_deployment_id')->nullable()->after('status')
                ->constrained('deployments')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by_deployment_id');
        });

        Schema::table('deployment_tasks', function (Blueprint $table) {
            // Task 4: exactly one task per target per deployment.
            $table->unique(['deployment_id', 'deployment_target_id']);
        });

        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->string('public_id', 36)->nullable()->unique()->after('id');
            // Mirrors Deployment.last_activity_at (Phase 1.5) so
            // deployments:detect-stalled can apply the exact same
            // no-activity timeout policy to new-domain jobs.
            $table->timestamp('last_activity_at')->nullable()->after('started_at');
        });

        // Deliberately a new table rather than adding an agent_job_id
        // column to deployment_log_entries: that table's existing
        // unique(deployment_id, sequence) constraint would otherwise
        // collide the moment two different targets of the same
        // deployment both log their own "sequence 1" — new-domain logs
        // need (agent_job_id, sequence) uniqueness instead, which is
        // cleaner as its own table than as a second, conditional
        // constraint bolted onto the legacy one.
        Schema::create('agent_job_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_job_id')->constrained('agent_jobs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('step_name');
            $table->text('command');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->timestamps();

            $table->unique(['agent_job_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_job_log_entries');

        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'last_activity_at']);
        });

        Schema::table('deployment_tasks', function (Blueprint $table) {
            $table->dropUnique(['deployment_id', 'deployment_target_id']);
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by_deployment_id');
            $table->dropColumn('locked_at');
        });
    }
};
