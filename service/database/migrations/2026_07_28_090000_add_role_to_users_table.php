<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-F: Role & Permission Foundation. Additive only — no existing
 * column touched. Defaults to 'admin' deliberately: this app has always
 * behaved as "any authenticated user can do anything" (no role/permission
 * system existed before this phase), so every user that exists today, and
 * every User::factory()->create() in the existing test suite, keeps that
 * exact behavior unless explicitly downgraded afterward. Only a
 * newly-created user whose role is deliberately set to something other
 * than 'admin' is actually restricted by anything in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('admin')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
