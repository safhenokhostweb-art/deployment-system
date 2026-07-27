<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.2: `auto_deploy_enabled` (Phase 5) was reserved for a future
 * auto-publish mode but never implemented or referenced anywhere.
 * `deployment_mode` (this phase) is that mechanism, and expresses three
 * states (manual/approval/auto) a boolean couldn't — so the old column
 * is dropped rather than left as unused, overlapping state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table) {
            $table->dropColumn('auto_deploy_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table) {
            $table->boolean('auto_deploy_enabled')->default(false)->after('remote_name');
        });
    }
};
