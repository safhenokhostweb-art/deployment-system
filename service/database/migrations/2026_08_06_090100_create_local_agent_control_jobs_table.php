<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3: the Hub's only way to reach a running Local Agent is this
 * poll-based channel — there is deliberately no inbound path to a
 * developer's machine. `action` discriminates restart/fetch_logs within
 * one table, same pattern as operation_jobs.operation_type /
 * publish_jobs.action. Independent of PublishJob/LocalChangeDetectionJob
 * — a control action has no git/commit semantics at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_agent_control_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->string('action')->comment('restart|fetch_logs');
            $table->string('status')->default('queued')->comment('queued|running|succeeded|failed');
            $table->string('requested_by')->nullable();
            $table->text('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['local_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_agent_control_jobs');
    }
};
