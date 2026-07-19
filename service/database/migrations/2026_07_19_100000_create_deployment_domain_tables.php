<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only foundation for the new Deployment Domain
 * (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md). Nothing here touches
 * `projects`, `servers`, `deployments`, or `deployment_log_entries` — new
 * tables only, and `deployment_tasks` references the *existing* `deployments`
 * table directly rather than introducing a second, colliding "Deployment"
 * concept (a simplification found during implementation — see the Phase
 * 3-C implementation report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name');
            $table->string('key')->comment('Agent/webhook-facing identifier for this environment; unique per project');
            $table->string('status')->default('active')->comment('active|disabled');
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('deployment_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained('environments')->restrictOnDelete();
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('name')->comment('e.g. "web-01" — the agent-config-facing target identifier');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['environment_id', 'name']);
        });

        Schema::create('deployment_tasks', function (Blueprint $table) {
            $table->id();
            // References the EXISTING deployments table — one legacy-shaped
            // Deployment row can now optionally fan out to N tasks, one per
            // target, without inventing a second "Deployment" entity.
            $table->foreignId('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignId('deployment_target_id')->constrained('deployment_targets')->restrictOnDelete();
            $table->string('status')->default('queued')->comment('queued|running|succeeded|failed|cancelled');
            $table->timestamps();

            $table->index(['deployment_target_id', 'status']);
        });

        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_task_id')->constrained('deployment_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->unsignedInteger('attempt')->default(1)->comment('Every retry is a new AgentJob row, never an update to a prior one');
            $table->string('status')->default('running')->comment('running|succeeded|failed|cancelled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['deployment_task_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_jobs');
        Schema::dropIfExists('deployment_tasks');
        Schema::dropIfExists('deployment_targets');
        Schema::dropIfExists('environments');
    }
};
