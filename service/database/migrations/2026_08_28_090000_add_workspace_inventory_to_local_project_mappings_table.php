<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Workspace Inventory): latest-only structured snapshot from
 * WorkspaceInventoryScanner (local-agent/lib/WorkspaceInventoryScanner.php),
 * reported through the new signed POST /api/local-agent/workspace-inventory
 * endpoint. Deliberately a single latest-state row extension of the
 * existing per-mapping heartbeat columns (local_head_sha/reported_branch/
 * working_tree_dirty/remote_tracking_sha/reported_at, added 2026-08-22) —
 * not a history table; Phase 3 dashboard rendering only ever needs the
 * current state. Every column is nullable so an agent that has never
 * reported inventory (an older agent, or a mapping that hasn't scanned
 * yet) is unambiguously distinguishable from one that reported a genuinely
 * clean/zero-count workspace — see LocalProjectMapping::hasReportedWorkspaceInventory().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('workspace_inventory_schema_version')->nullable()->after('reported_at');
            $table->timestamp('workspace_inventory_scanned_at')->nullable()->after('workspace_inventory_schema_version');
            $table->timestamp('workspace_inventory_received_at')->nullable()->after('workspace_inventory_scanned_at');
            $table->string('workspace_inventory_snapshot_hash', 64)->nullable()->after('workspace_inventory_received_at');

            $table->string('workspace_branch')->nullable()->after('workspace_inventory_snapshot_hash');
            $table->string('workspace_head_sha', 40)->nullable()->after('workspace_branch');
            $table->string('workspace_upstream_ref')->nullable()->after('workspace_head_sha');
            $table->string('workspace_upstream_sha', 40)->nullable()->after('workspace_upstream_ref');
            $table->string('workspace_comparison_basis')->nullable()->after('workspace_upstream_sha');

            $table->unsignedInteger('workspace_ahead_count')->nullable()->after('workspace_comparison_basis');
            $table->unsignedInteger('workspace_behind_count')->nullable()->after('workspace_ahead_count');

            $table->unsignedInteger('workspace_tracked_modified_count')->nullable()->after('workspace_behind_count');
            $table->unsignedInteger('workspace_untracked_count')->nullable()->after('workspace_tracked_modified_count');
            $table->unsignedInteger('workspace_staged_count')->nullable()->after('workspace_untracked_count');
            $table->unsignedInteger('workspace_deleted_count')->nullable()->after('workspace_staged_count');
            $table->unsignedInteger('workspace_renamed_count')->nullable()->after('workspace_deleted_count');
            $table->unsignedInteger('workspace_conflicted_count')->nullable()->after('workspace_renamed_count');
            $table->unsignedInteger('workspace_total_changed_count')->nullable()->after('workspace_conflicted_count');

            $table->boolean('workspace_is_clean')->nullable()->after('workspace_total_changed_count');
            $table->boolean('workspace_is_truncated')->nullable()->after('workspace_is_clean');
            $table->unsignedInteger('workspace_omitted_file_count')->nullable()->after('workspace_is_truncated');
            $table->text('workspace_scan_error')->nullable()->after('workspace_omitted_file_count');

            $table->json('workspace_inventory_files')->nullable()->after('workspace_scan_error');
        });
    }

    public function down(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->dropColumn([
                'workspace_inventory_schema_version',
                'workspace_inventory_scanned_at',
                'workspace_inventory_received_at',
                'workspace_inventory_snapshot_hash',
                'workspace_branch',
                'workspace_head_sha',
                'workspace_upstream_ref',
                'workspace_upstream_sha',
                'workspace_comparison_basis',
                'workspace_ahead_count',
                'workspace_behind_count',
                'workspace_tracked_modified_count',
                'workspace_untracked_count',
                'workspace_staged_count',
                'workspace_deleted_count',
                'workspace_renamed_count',
                'workspace_conflicted_count',
                'workspace_total_changed_count',
                'workspace_is_clean',
                'workspace_is_truncated',
                'workspace_omitted_file_count',
                'workspace_scan_error',
                'workspace_inventory_files',
            ]);
        });
    }
};
