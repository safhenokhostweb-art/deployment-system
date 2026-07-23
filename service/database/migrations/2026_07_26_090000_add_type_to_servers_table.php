<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-D: additive only. Nothing here touches an existing servers
 * column — Step 1 of the Server Onboarding Wizard (docs/PHASE_4D_SERVER_ONBOARDING_REPORT.md)
 * asks the operator to pick a server type, and without somewhere to
 * persist it that choice would be collected and silently discarded.
 * Nullable so every server that exists before this phase (and any
 * created without picking a type) is completely unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('type')->nullable()->after('description')
                ->comment('vps|cpanel|shared_hosting|docker — operator-selected label, informational only');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
