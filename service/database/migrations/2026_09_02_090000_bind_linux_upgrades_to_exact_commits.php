<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_agent_control_jobs', function (Blueprint $table) {
            $table->char('expected_commit_hash', 40)->nullable()->after('expected_version');
            $table->string('expected_branch')->nullable()->after('expected_commit_hash');
            $table->string('repository_mapping')->nullable()->after('expected_branch');
            $table->foreignId('upgrade_release_id')->nullable()->after('repository_mapping')->constrained('releases')->restrictOnDelete();
            $table->char('reported_commit_hash', 40)->nullable()->after('reported_version');
            $table->char('package_tree_hash', 40)->nullable()->after('reported_commit_hash');
        });
    }

    public function down(): void
    {
        Schema::table('local_agent_control_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upgrade_release_id');
            $table->dropColumn([
                'expected_commit_hash',
                'expected_branch',
                'repository_mapping',
                'reported_commit_hash',
                'package_tree_hash',
            ]);
        });
    }
};
