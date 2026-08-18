<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (no-source-change publish lifecycle gap): a publish with
 * no source changes succeeds without a git push, so the GitHub push
 * webhook never fires and no Deployment is created by the normal
 * webhook-driven path. The publish-to-deployment bridge (see
 * PublishJobService::bridgeNoSourceChangeDeployment()) records the one
 * Deployment it creates for that publish here, giving the Publish page an
 * explicit correlation instead of a commit-hash guess that could point at
 * an unrelated historical deployment for the same SHA. Null for every
 * push-based publish, which remains webhook-driven and uncorrelated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->foreignId('deployment_id')
                ->nullable()
                ->after('result_commit_hash')
                ->constrained('deployments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deployment_id');
        });
    }
};
