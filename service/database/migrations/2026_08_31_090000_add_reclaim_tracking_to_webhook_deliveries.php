<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('reclaim_count')->default(0)->after('processing_lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('reclaim_count');
        });
    }
};
