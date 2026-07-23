<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-I Task 7: Release Concept Foundation. Purely additive, nullable
 * columns — every existing Deployment row (and every deployment created
 * through the unmodified legacy/webhook/rollback paths) simply has these
 * as null, which the UI falls back from to the commit hash. No existing
 * column is touched; no new table was needed for what the brief
 * explicitly asked to keep small ("بدون تغییر بزرگ").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release_label', 100)->nullable()->after('branch');
            $table->text('release_notes')->nullable()->after('release_label');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['release_label', 'release_notes']);
        });
    }
};
