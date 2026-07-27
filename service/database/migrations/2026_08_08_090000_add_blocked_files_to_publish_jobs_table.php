<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.5: the agent already discards sensitive files before ever
 * reporting `changed_files` (IgnoreRules' MANDATORY_PATTERNS, Phase 5.4)
 * — but the Hub never learned WHAT was discarded, so the operator had no
 * way to see it. This stores that second list alongside changed_files,
 * same shape, same nullable-json treatment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->json('blocked_files')->nullable()->after('changed_files')
                ->comment('Files the agent discarded via IgnoreRules before ever staging them (e.g. .env, credentials) — reported for operator visibility only');
        });
    }

    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropColumn('blocked_files');
        });
    }
};
