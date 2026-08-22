<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable links only: existing artifacts, publish jobs, and deployments remain
 * valid and keep their current behavior when release_id is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->foreignId('release_id')
                ->nullable()
                ->after('project_id')
                ->constrained('releases')
                ->nullOnDelete();
        });

        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->foreignId('release_id')
                ->nullable()
                ->after('project_id')
                ->constrained('releases')
                ->nullOnDelete();
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->foreignId('release_id')
                ->nullable()
                ->after('project_id')
                ->constrained('releases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });

        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });

        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });
    }
};
