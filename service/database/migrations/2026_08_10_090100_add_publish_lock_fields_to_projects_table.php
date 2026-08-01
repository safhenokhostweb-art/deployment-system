<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Project's checkout can be mapped to more than one Local Agent (see
 * LocalProjectMapping's own docblock: "a Project can be published from
 * more than one developer's machine"), so two developers publishing the
 * same repository/branch at once was previously unguarded — nothing
 * stopped two concurrent `git push` attempts from racing. Mirrors the
 * existing Environment.locked_by_deployment_id/locked_at convention
 * (see DeploymentStatusAggregator), with a TTL added since a lock owner
 * here can be an arbitrary developer machine rather than a managed
 * server-side job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('locked_by_publish_job_id')->nullable()->after('is_active')
                ->constrained('publish_jobs')->nullOnDelete();
            $table->foreignId('locked_by_local_agent_id')->nullable()->after('locked_by_publish_job_id')
                ->constrained('local_agents')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by_local_agent_id');
            $table->timestamp('lock_expires_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by_publish_job_id');
            $table->dropConstrainedForeignId('locked_by_local_agent_id');
            $table->dropColumn(['locked_at', 'lock_expires_at']);
        });
    }
};
