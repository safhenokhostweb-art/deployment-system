<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix: a PublishJob claimed by a Local Agent that then hangs
 * (e.g. a `git add` blocked on a huge diff, or a crashed/killed agent
 * process) previously had no way to ever be told apart from a genuinely
 * long-running publish — it just sat "running" forever. These columns
 * give every claimed PublishJob a lease: claimNext() sets
 * lease_expires_at a short window out, the agent's own Job heartbeat
 * (distinct from its general online heartbeat) renews it every ~20-30s,
 * and deployments:recover-stale finalizes anything whose lease expired
 * without a renewal — see PublishJobService and RecoverStalePublishJobs.
 *
 * All nullable/additive: every pre-existing row (queued, running, or
 * already terminal) is unaffected until the next claim/heartbeat touches
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->unsignedInteger('attempt')->default(1)->after('action');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
            $table->timestamp('lease_expires_at')->nullable()->after('heartbeat_at');
            $table->string('failure_code')->nullable()->after('error_message')
                ->comment('agent_heartbeat_lost|job_lease_expired|process_timeout|agent_restarted|late_callback_rejected — set by recovery or the agent itself, never by a human');
            $table->json('recovery_metadata')->nullable()->after('failure_code');

            $table->index(['status', 'lease_expires_at'], 'publish_jobs_status_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropIndex('publish_jobs_status_lease_idx');
            $table->dropColumn(['attempt', 'heartbeat_at', 'lease_expires_at', 'failure_code', 'recovery_metadata']);
        });
    }
};
