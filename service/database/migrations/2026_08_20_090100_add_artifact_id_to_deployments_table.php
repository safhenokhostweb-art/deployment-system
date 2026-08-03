<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable and additive: a Deployment whose project/commit has a
 * matching Artifact (see DeploymentDispatcher::attachArtifactIfAvailable())
 * gets this set at creation time, so AgentJobService::claimNextJob() can
 * include download details in the job payload. A Deployment with no
 * matching artifact (every Deployment created before this column, or any
 * project not using the Local Agent artifact-publish flow) leaves this
 * null and the agent runs the exact legacy git-based pipeline as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->foreignId('artifact_id')->nullable()->after('rollback_of_deployment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artifact_id');
        });
    }
};
