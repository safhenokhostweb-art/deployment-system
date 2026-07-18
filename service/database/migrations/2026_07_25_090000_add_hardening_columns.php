<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('agent_version')->nullable()->after('last_seen_at');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('finished_at')
                ->comment('Bumped on every status change and log line; used to detect a stalled running job.');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('agent_version');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
