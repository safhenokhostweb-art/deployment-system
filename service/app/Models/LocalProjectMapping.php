<?php

namespace App\Models;

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
        'git_branch',
        'remote_name',
        'deployment_mode',
        'local_head_sha',
        'reported_branch',
        'working_tree_dirty',
        'remote_tracking_sha',
        'reported_at',
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

    protected function casts(): array
    {
        return [
            'working_tree_dirty' => 'boolean',
            'reported_at' => 'datetime',
        ];
    }
}
