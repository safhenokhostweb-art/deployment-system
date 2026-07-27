<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.2: per-mapping trust level for how a detected local change is
 * handled. Deliberately per (local_agent_id, project_id) pair, not per
 * Project — two developers mapped to the same project may want
 * different levels of automation on their own machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table) {
            $table->string('deployment_mode')->default('approval')->after('auto_deploy_enabled')->comment('manual|approval|auto');
        });
    }

    public function down(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table) {
            $table->dropColumn('deployment_mode');
        });
    }
};
