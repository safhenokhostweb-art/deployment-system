<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional, project-relative paths (never absolute server paths — kept
 * consistent with docs/ARCHITECTURE.md §8's "Hub never learns server
 * file paths": '.env'/'storage' are repo-relative structure knowledge,
 * true for any server hosting this code, not a given server's own
 * filesystem layout) that must survive an artifact swap — the runtime
 * files a fresh `git archive` extraction never contains at all: .env,
 * the storage directory (and its public symlink), user-uploaded files.
 * Null means "use the built-in Laravel default list" — see
 * ArtifactManager::DEFAULT_PRESERVE_PATHS on the agent side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('artifact_preserve_paths')->nullable()->after('deployment_recipe');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('artifact_preserve_paths');
        });
    }
};
