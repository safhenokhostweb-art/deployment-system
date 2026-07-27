<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: which local folder on a developer's machine corresponds to
 * which Hub Project, for a given Local Agent. A Local Agent can manage
 * several checkouts (e.g. saas-platform, customer-site); a Project can
 * be published from more than one developer's machine — hence the pair
 * is unique per (local_agent_id, project_id), not a column on either
 * side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_project_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('local_path')->comment('Absolute path on the developer machine, e.g. C:\\laragon\\www\\saas-platform');
            $table->string('git_branch')->nullable()->comment('Defaults to the Project.branch when null');
            $table->string('remote_name')->default('origin');
            $table->boolean('auto_deploy_enabled')->default(false)->comment('Reserved for a future auto-publish-on-save mode; unused by the one-click Publish flow itself');
            $table->timestamps();

            $table->unique(['local_agent_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_project_mappings');
    }
};
