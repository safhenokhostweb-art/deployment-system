<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->boolean('no_source_change')->default(false)->after('files_changed_count');
            $table->string('reviewed_head_sha', 40)->nullable()->after('no_source_change');
            $table->string('reviewed_branch')->nullable()->after('reviewed_head_sha');
            $table->foreignId('reviewed_mapping_id')->nullable()->after('reviewed_branch')->constrained('local_project_mappings')->nullOnDelete();
            $table->boolean('reviewed_working_tree_clean')->nullable()->after('reviewed_mapping_id');
        });
    }

    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_mapping_id');
            $table->dropColumn(['no_source_change', 'reviewed_head_sha', 'reviewed_branch', 'reviewed_working_tree_clean']);
        });
    }
};
