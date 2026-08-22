<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive foundation for the SaaS release domain. This table is not wired
 * into the current webhook/publish/deployment execution paths in Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('commit_hash', 40);
            $table->string('branch')->nullable();
            $table->string('status')->default('source_received');
            $table->string('source')->default('manual')->comment('manual|webhook|publish|import|system');
            $table->timestamp('source_received_at')->nullable();
            $table->timestamp('artifact_checked_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'commit_hash']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
