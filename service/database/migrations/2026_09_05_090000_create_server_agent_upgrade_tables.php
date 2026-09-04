<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (Deployment #184 proved the Server Agent runtime at
 * /opt/deployment-server-agent silently drifts from the repository's
 * agent/lib/* — the Hub has no channel to it at all): a dedicated,
 * narrowly-scoped exact-commit self-upgrade contract for the Server
 * Agent (App\Models\Agent), structurally independent of
 * LocalAgentControlJob (which is exclusively about the Local/Build
 * Agent controlling itself) — see docs/PHASE_2_AGENT_V2_REPORT.md for
 * why Agent/Server identity must never be conflated with LocalAgent.
 *
 * Three tables, three responsibilities:
 *   - server_agent_control_jobs: the Hub -> Server Agent upgrade order
 *     (poll/claim/report, same shape as local_agent_control_jobs).
 *   - server_agent_packages: the Hub's own record of one materialized,
 *     hash-verified agent/** package for one exact commit (the Server
 *     Agent downloads FROM the Hub, never from git directly — it has no
 *     git access by design, see docs/ARTIFACT_DEPLOYMENT.md's own
 *     architecture diagram).
 *   - server_agent_package_build_jobs: the Hub -> Local Agent request to
 *     materialize one such package. The Local Agent is the only already-
 *     trusted, git-capable machine in this system; it already performs
 *     this exact class of work for its own exact-commit Linux self-
 *     upgrade (see local-agent/lib/LinuxUpgradePackageMaterializer.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_agent_packages', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->char('commit_hash', 40);
            $table->string('agent_version', 50);
            $table->string('repository_mapping');
            $table->char('tree_hash', 40)->comment('git tree hash of the exact commit\'s agent/ subtree');
            $table->string('checksum_sha256', 64)->comment('sha256 of the materialized package zip itself');
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk_path');
            $table->string('status')->default('available')->comment('available|superseded');
            $table->timestamps();

            $table->unique(['commit_hash', 'repository_mapping']);
        });

        Schema::create('server_agent_package_build_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->char('commit_hash', 40);
            $table->string('branch');
            $table->string('agent_version', 50);
            $table->string('repository_mapping');
            $table->string('status')->default('queued')->comment('queued|running|succeeded|failed');
            $table->foreignId('server_agent_package_id')->nullable()->constrained('server_agent_packages')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['commit_hash', 'repository_mapping']);
            $table->index(['local_agent_id', 'status']);
        });

        Schema::create('server_agent_control_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('action')->default('upgrade')->comment('upgrade');
            $table->string('status')->default('queued')->comment('queued|running|awaiting_heartbeat|succeeded|failed');
            $table->string('target_version', 50)->nullable();
            $table->char('target_commit_sha', 40)->nullable();
            $table->string('target_branch')->nullable();
            $table->string('repository_mapping')->nullable();
            $table->foreignId('server_agent_package_id')->nullable()->constrained('server_agent_packages')->nullOnDelete();
            $table->string('expected_package_hash', 64)->nullable();
            $table->char('reported_commit_hash', 40)->nullable();
            $table->string('reported_package_hash', 64)->nullable();
            $table->string('reported_version', 50)->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamp('upgrade_started_at')->nullable();
            $table->timestamp('upgrade_verified_at')->nullable();
            $table->text('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_agent_control_jobs');
        Schema::dropIfExists('server_agent_package_build_jobs');
        Schema::dropIfExists('server_agent_packages');
    }
};
