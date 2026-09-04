<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (commit 9f7adfd shipped the Server Agent package-build
 * poller without a Local Agent version bump, and the Hub queued
 * ServerAgentPackageBuildJob #1 with no record of what the assigned
 * builder actually reported at that moment): server_agent_package_build_jobs
 * already has `agent_version`, but that column means the TARGET SERVER
 * AGENT version (e.g. "1.4.1") -- never overloaded or renamed here.
 * builder_agent_version is a separate, point-in-time audit fact: what
 * agent_version the assigned Local Agent (local_agent_id) reported when
 * this job was queued. local_agent_id alone cannot answer that later,
 * since the referenced row's own agent_version is mutable over time
 * (the whole point of this fix is that it changes).
 *
 * Nullable and purely additive: an already-queued row (Production
 * ServerAgentPackageBuildJob #1, queued before this migration exists)
 * keeps builder_agent_version = null and remains fully claimable --
 * ServerAgentPackageService::claimNextBuildJob() never reads this
 * column, only status/local_agent_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_agent_package_build_jobs', function (Blueprint $table) {
            $table->string('builder_agent_version', 50)->nullable()->after('local_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_agent_package_build_jobs', function (Blueprint $table) {
            $table->dropColumn('builder_agent_version');
        });
    }
};
