<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256 —
 * "Dependency contract must pin every Composer path repository exactly
 * once"): ArtifactDependencyPolicy's config carried both the trusted
 * package/path allowlist AND the exact upstream commit_sha as one static,
 * hand-maintained literal, with no lifecycle for advancing it and no
 * record of which commit a given Release actually built against.
 *
 * dependency_snapshot is the ONE-TIME resolved v2 dependency contract for
 * a Release that requires pinned dependencies (see
 * App\Services\Publish\ArtifactDependencySnapshotService), frozen the
 * first time it is needed and replayed unchanged for every later
 * poll/retry/rollback of that exact Release — deliberately NEVER updated
 * afterward, even if the upstream project ships a newer Release. Additive
 * and nullable: existing Release rows are untouched, and a null value here
 * means "resolve on first need" (new rows) or "legacy/pinning not
 * required" (rows created before this column existed), never a migration
 * failure or a forced backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->json('dependency_snapshot')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('dependency_snapshot');
        });
    }
};
