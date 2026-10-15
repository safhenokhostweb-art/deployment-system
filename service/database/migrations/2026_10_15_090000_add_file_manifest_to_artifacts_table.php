<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->char('file_manifest_hash', 64)->nullable()->after('required_paths');
            $table->unsignedInteger('file_manifest_entry_count')->nullable()->after('file_manifest_hash');
            $table->unsignedBigInteger('file_manifest_total_size')->nullable()->after('file_manifest_entry_count');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn(['file_manifest_hash', 'file_manifest_entry_count', 'file_manifest_total_size']);
        });
    }
};