<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional operator-facing persistent-state configuration, mirroring
 * artifact_preserve_paths: a project-relative path listed here is NOT
 * copied per release — it becomes a symlink inside each staged release
 * pointing at {persistent_root}/{path}, so the real runtime data stays
 * outside the swappable release. The actual on-server values live in each
 * agent's own config.php (the Hub never learns server file paths — same
 * principle as artifact_preserve_paths); these columns are the operator
 * record validated with the same relative-path/no-overlap contract the
 * agent enforces fail-closed at prepare time
 * (ArtifactManager::preparePersistentReferences()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('artifact_persistent_paths')->nullable()->after('artifact_preserve_paths');
            $table->string('artifact_persistent_root', 255)->nullable()->after('artifact_persistent_paths');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['artifact_persistent_paths', 'artifact_persistent_root']);
        });
    }
};