<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_agent_control_jobs', function (Blueprint $table) {
            $table->string('expected_version', 50)->nullable()->after('status');
            $table->string('reported_version', 50)->nullable()->after('expected_version');
            $table->timestamp('upgrade_started_at')->nullable()->after('reported_version');
            $table->timestamp('upgrade_verified_at')->nullable()->after('upgrade_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('local_agent_control_jobs', function (Blueprint $table) {
            $table->dropColumn(['expected_version', 'reported_version', 'upgrade_started_at', 'upgrade_verified_at']);
        });
    }
};
