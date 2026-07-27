<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: One-Click Publish. Deliberately independent of
 * agent_jobs/discovery_jobs/operation_jobs — a publish has no
 * deployment_task/pipeline and is requested against a (LocalAgent,
 * Project) pair, never a Server. `action` discriminates between the
 * three things a Local Agent can be asked to do (status_check, publish,
 * rollback) inside a single table — the same "one job table, one
 * discriminator column" shape already used by
 * operation_jobs.operation_type, rather than a table per action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('action')->comment('status_check|publish|rollback');
            $table->string('status')->default('queued')->comment('queued|awaiting_approval|approved|running|succeeded|failed|rejected');
            $table->text('commit_message')->nullable()->comment('Supplied by the operator on Approve; never invented by the agent');
            $table->json('changed_files')->nullable()->comment('Reported by the agent from `git status --porcelain`, shown to the operator before Approve');
            $table->unsignedInteger('files_changed_count')->nullable();
            $table->string('result_commit_hash')->nullable();
            $table->string('requested_by')->nullable()->comment('dashboard user email, mirrors operation_jobs.requested_by');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['local_agent_id', 'status']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_jobs');
    }
};
