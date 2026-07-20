<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only, per docs/PHASE_3D_PRODUCTION_READINESS_REPORT.md.
 * Nothing here touches `projects`, `servers`, `deployments`, or any
 * Phase 3-C/3-C.2 table's existing columns/constraints — new tables plus
 * two new nullable columns on `deployment_tasks` for retry scheduling.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Task 2: webhook idempotency.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique()->comment('GitHub X-GitHub-Delivery header — same value on a redelivery of the same event');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('event')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Task 3: retry scheduling for new-domain tasks. Nullable/additive
        // — a task that never retries (the default) never sets these.
        Schema::table('deployment_tasks', function (Blueprint $table) {
            $table->timestamp('next_retry_at')->nullable()->after('status');
        });

        // Task 5: health check framework.
        Schema::create('deployment_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_task_id')->constrained('deployment_tasks')->cascadeOnDelete();
            $table->string('type')->comment('e.g. http_status — see App\\Services\\HealthCheck');
            $table->json('config');
            $table->string('status')->default('pending')->comment('pending|success|failed');
            $table->text('result_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_health_checks');

        Schema::table('deployment_tasks', function (Blueprint $table) {
            $table->dropColumn('next_retry_at');
        });

        Schema::dropIfExists('webhook_deliveries');
    }
};
