<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('application_root')->default('.')->after('artifact_preserve_paths');
        });
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->json('required_paths')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn('required_paths');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('application_root');
        });
    }
};
