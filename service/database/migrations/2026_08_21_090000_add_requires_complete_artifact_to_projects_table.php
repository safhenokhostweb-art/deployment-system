<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Project scope is deliberate: an Artifact is already uniquely
            // identified by project + commit, and one artifact may be sent
            // to many Environment/DeploymentTarget rows.
            $table->boolean('requires_complete_artifact')->default(false)->after('artifact_preserve_paths');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('requires_complete_artifact');
        });
    }
};
