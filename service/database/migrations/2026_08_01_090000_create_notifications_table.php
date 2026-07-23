<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-J: custom notifications table, not Laravel's built-in
 * database-notifications package — see
 * docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md §3 for the full
 * reasoning. Mirrors audit_log_entries' actor_type/actor_identifier +
 * subject_type/subject_id shape deliberately, so "what caused this
 * notification" is answerable the same way "what caused this audit
 * entry" already is (Task 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('severity')->default('info')->comment('success|info|warning|critical');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('actor_type')->nullable()->comment('mirrors audit_log_entries.actor_type: system|user|agent|github');
            $table->string('actor_identifier')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
