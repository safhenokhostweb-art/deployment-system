<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (customer-site production deploys failing): the
 * production server cannot reach github.com directly (Iran connectivity
 * — direct connections and SSH on 22/443 both time out), but the
 * Deployment pipeline's first step was `git fetch origin {branch} &&
 * git reset --hard {commit}`, run ON the production server. The Local
 * Agent (on a developer machine, which DOES have working GitHub access)
 * now builds a deployable artifact via `git archive` immediately after
 * a successful push and uploads it here; the production Server Agent
 * downloads it from the Hub instead — a connection it already needs for
 * polling, so no new network dependency is introduced for it at all.
 *
 * One row per (project, commit) — a re-upload for the same commit
 * (a retried publish after an earlier upload failure) overwrites the
 * existing row rather than creating a duplicate, so a Deployment can
 * always be matched to at most one artifact by (project_id, commit_hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('commit_hash', 40);
            $table->string('branch');
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            // Relative to the private `artifacts` disk — never a public
            // URL, never guessable, downloaded only via the signed
            // agent-facing endpoint (see AgentArtifactController).
            $table->string('disk_path');
            $table->string('status')->default('available');
            $table->string('uploaded_by')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'commit_hash']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
