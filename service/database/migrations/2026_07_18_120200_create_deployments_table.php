<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->string('action')->default('deploy')->comment('deploy|rollback');
            $table->string('status')->default('pending_approval')->comment('pending_approval|queued|running|succeeded|failed|cancelled');
            $table->string('commit_hash', 40);
            $table->string('previous_commit_hash', 40)->nullable();
            $table->string('branch');
            $table->string('trigger')->comment('webhook|manual|rollback');
            $table->string('triggered_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('rollback_of_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
