<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.2: the Local Agent's own file-watch reports, independent of
 * PublishJob — a detection has no commit message yet, no approval, and
 * most of the time never becomes a publish at all (Manual mode, or the
 * developer keeps editing). Same "new action category, new table"
 * precedent PublishJob itself established over AgentJob/OperationJob.
 * One OPEN row per (local_agent_id, project_id) at a time — the agent
 * updates the same row every watch cycle instead of one row per poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_change_detection_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->json('changed_files')->nullable();
            $table->unsignedInteger('files_changed_count')->default(0);
            $table->timestamp('detected_at')->nullable();
            $table->string('status')->default('detected')->comment('detected|waiting_approval|approved|published|ignored');
            $table->text('suggested_commit_message')->nullable();
            $table->foreignId('publish_job_id')->nullable()->constrained('publish_jobs')->nullOnDelete();
            $table->timestamps();

            $table->index(['local_agent_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_change_detection_jobs');
    }
};
