<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-K: Operations Command Center. New table only — nothing
 * existing is touched. Deliberately independent of deployment_tasks/
 * agent_jobs (same reasoning as discovery_jobs before it): an operation
 * has no commit/branch/pipeline, and is requested against a Server (plus
 * an optional Project for Laravel-group operations that need a working
 * directory), never a Deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            // Nullable: only Laravel-group operations (cache_clear,
            // config_cache, route_cache, queue_restart) need a project's
            // working_directory/php_binary; Server-group operations
            // (disk_check, service_status, system_health) run with no
            // project context at all.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('operation_type');
            $table->string('status')->default('queued')->comment('queued|running|succeeded|failed');
            $table->string('requested_by')->nullable()->comment('dashboard user email, mirrors deployments.triggered_by / discovery_jobs.requested_by');
            $table->text('output')->nullable()->comment('sanitized command output, mirrors deployment_log_entries redaction');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_jobs');
    }
};
