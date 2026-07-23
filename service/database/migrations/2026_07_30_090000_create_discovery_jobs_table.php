<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-H: Local Discovery. New table only — nothing existing is
 * touched. Deliberately independent of the deployment_tasks/agent_jobs
 * domain (docs/PHASE_4H_LOCAL_DISCOVERY_IMPLEMENTATION_REPORT.md §2) — a
 * Discovery scan has no Deployment, no DeploymentTarget, and often no
 * Project yet (it exists to help create one), so reusing AgentJob would
 * mean a fake/nullable deployment_task_id on a table whose whole schema
 * assumes one always exists. `result` holds only sanitized metadata
 * (project name/path/type/framework version/git remote-branch-commit) —
 * never a secret, never a full file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('workspace_path');
            $table->string('status')->default('queued')->comment('queued|running|succeeded|failed');
            $table->string('requested_by')->nullable()->comment('dashboard user email, mirrors triggered_by/approved_by pattern');
            $table->json('result')->nullable()->comment('sanitized array of detected projects');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_jobs');
    }
};
