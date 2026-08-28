<?php

namespace App\Models;

use App\Support\Deployment\SourceWorkspacePath;
use App\Support\Deployment\WorkspaceStatusResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5: which local checkout on a developer's machine corresponds to
 * which Hub Project, for a given Local Agent — the input a Local Agent
 * needs to know where to run `git status`/`git add`/`git commit`/
 * `git push` for a Publish request.
 */
class LocalProjectMapping extends Model
{
    use HasFactory;

    // Phase 5.2: how a detected local change on this specific
    // agent+project pair is handled. Supersedes the unused
    // `auto_deploy_enabled` boolean from Phase 5 (dropped — a boolean
    // couldn't express three states).
    public const MODE_MANUAL = 'manual';
    public const MODE_APPROVAL = 'approval';
    public const MODE_AUTO = 'auto';

    protected $fillable = [
        'local_agent_id',
        'project_id',
        'local_path',
        'source_workspace_path',
        'production_path',
        'git_branch',
        'remote_name',
        'deployment_mode',
        'local_head_sha',
        'reported_branch',
        'working_tree_dirty',
        'remote_tracking_sha',
        'reported_at',
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
    ];

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The branch a Local Agent should publish/rollback against for this
     * mapping — an explicit override on the mapping itself, falling back
     * to the Project's own branch when unset. Same fallback documented on
     * the `git_branch` column since the table was created; centralized
     * here so the Hub API and any other caller can't drift from it.
     */
    public function effectiveBranch(): string
    {
        return $this->git_branch ?? $this->project->branch;
    }

    /** The only path authorized for git and artifact-source operations. */
    public function sourceWorkspacePath(): string
    {
        foreach ([$this->source_workspace_path, $this->local_path] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && !SourceWorkspacePath::isProductionLike($candidate)) {
                return trim($candidate);
            }
        }

        return SourceWorkspacePath::defaultFor($this->project->project_key);
    }

    /**
     * Distinguishes "never reported workspace inventory" (an older agent,
     * or a mapping that just hasn't scanned yet) from a genuinely reported
     * clean/zero-count snapshot — every workspace_* column is nullable for
     * exactly this reason, so a missing report is never mistaken for CLEAN.
     */
    public function hasReportedWorkspaceInventory(): bool
    {
        return $this->workspace_inventory_schema_version !== null;
    }

    /** @see WorkspaceStatusResolver for the exact derivation and priority order. */
    public function workspaceStatus(): string
    {
        return WorkspaceStatusResolver::resolve($this);
    }

    protected function casts(): array
    {
        return [
            'working_tree_dirty' => 'boolean',
            'reported_at' => 'datetime',
            'workspace_inventory_schema_version' => 'integer',
            'workspace_inventory_scanned_at' => 'datetime',
            'workspace_inventory_received_at' => 'datetime',
            'workspace_ahead_count' => 'integer',
            'workspace_behind_count' => 'integer',
            'workspace_tracked_modified_count' => 'integer',
            'workspace_untracked_count' => 'integer',
            'workspace_staged_count' => 'integer',
            'workspace_deleted_count' => 'integer',
            'workspace_renamed_count' => 'integer',
            'workspace_conflicted_count' => 'integer',
            'workspace_total_changed_count' => 'integer',
            'workspace_is_clean' => 'boolean',
            'workspace_is_truncated' => 'boolean',
            'workspace_omitted_file_count' => 'integer',
            'workspace_inventory_files' => 'array',
        ];
    }
}
