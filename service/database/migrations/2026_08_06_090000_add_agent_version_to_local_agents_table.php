<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3: LocalAgent never tracked its own binary version, unlike
 * Agent.agent_version — needed for the Hub-side "update available" check
 * (heartbeat compares this against config('deployment.latest_local_agent_version')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_agents', function (Blueprint $table) {
            $table->string('agent_version')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('local_agents', function (Blueprint $table) {
            $table->dropColumn('agent_version');
        });
    }
};
