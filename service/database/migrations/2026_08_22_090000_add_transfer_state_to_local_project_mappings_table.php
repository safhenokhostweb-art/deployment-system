<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->string('local_head_sha', 40)->nullable()->after('deployment_mode');
            $table->string('reported_branch')->nullable()->after('local_head_sha');
            $table->boolean('working_tree_dirty')->nullable()->after('reported_branch');
            $table->string('remote_tracking_sha', 40)->nullable()->after('working_tree_dirty');
            $table->timestamp('reported_at')->nullable()->after('remote_tracking_sha');
            $table->index(['project_id', 'reported_at'], 'local_project_mapping_project_reported_idx');
        });
    }

    public function down(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->dropIndex('local_project_mapping_project_reported_idx');
            $table->dropColumn(['local_head_sha', 'reported_branch', 'working_tree_dirty', 'remote_tracking_sha', 'reported_at']);
        });
    }
};
