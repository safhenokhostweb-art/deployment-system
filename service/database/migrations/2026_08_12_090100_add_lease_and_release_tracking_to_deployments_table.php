<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix: mirrors the exact lease/heartbeat pattern already
 * proven for PublishJob (see 2026_08_10_090000_add_lease_fields_to_publish_jobs_table.php)
 * for the separate Deployment/AgentJob domain — a recipe-based deploy can
 * now legitimately run for many minutes (npm install, two builds, a
 * migration), and the existing deployments:detect-stalled command only
 * had a single, coarse `last_activity_at` signal updated once per
 * COMPLETED step; a single long-running step (e.g. `npm ci` on a cold
 * cache) could be wrongly marked stalled. `heartbeat_at`/`lease_expires_at`
 * are renewed by the agent mid-step (see agent/lib/Executor.php's
 * $onTick), independent of step boundaries.
 *
 * `deployed_at` is the new, stronger success signal Phase 5 needs:
 * distinct from `finished_at` (set the moment the agent's shell commands
 * all exited 0, the pre-existing weak definition) — `deployed_at` is only
 * set once activation AND every http_health_check step in the recipe has
 * actually passed. A Project with no recipe (plain `pipeline`) has no
 * health-check step to satisfy, so `deployed_at` is set alongside
 * `finished_at` for it — behavior for every existing Project is
 * unchanged.
 *
 * `rolled_back_at` + `failure_code` extend the existing status string
 * ('rolled_back' becomes a new valid value — see Deployment::STATUS_ROLLED_BACK
 * — no DB-level enum/check constraint exists on this column, so this is
 * a pure application-level addition, consistent with how every other
 * status value in this project already works).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable()->after('last_activity_at');
            $table->timestamp('lease_expires_at')->nullable()->after('heartbeat_at');
            $table->timestamp('deployed_at')->nullable()->after('finished_at')
                ->comment('Set only once activation + every recipe health check has passed — distinct from finished_at, which only means "the agent reported success".');
            $table->timestamp('rolled_back_at')->nullable()->after('deployed_at');
            $table->string('failure_code')->nullable()->after('failure_message')
                ->comment('build_failed|migration_failed|migration_requires_approval|activation_failed|service_restart_failed|health_check_failed|lease_expired|agent_heartbeat_lost — set by the agent report or recovery, never by a human');

            $table->index(['status', 'lease_expires_at'], 'deployments_status_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex('deployments_status_lease_idx');
            $table->dropColumn(['heartbeat_at', 'lease_expires_at', 'deployed_at', 'rolled_back_at', 'failure_code']);
        });
    }
};
