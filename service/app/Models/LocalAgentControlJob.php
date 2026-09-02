<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5.3: the Hub's only channel to remotely act on a running Local
 * Agent (restart it, pull its recent log output) — there is deliberately
 * no inbound path to a developer's machine, so this is polled exactly
 * like PublishJob/LocalChangeDetectionJob. `action` discriminates within
 * one table, mirroring OperationJob.operation_type.
 */
class LocalAgentControlJob extends Model
{
    use HasFactory;

    public const ACTION_RESTART = 'restart';
    public const ACTION_FETCH_LOGS = 'fetch_logs';

    /**
     * Project-config sync: the only way an Admin can force an immediate
     * re-sync on a running agent — there is no other inbound path to a
     * developer's machine, so this reuses the exact same
     * queue/poll/report channel as restart/fetch_logs rather than adding
     * a new one. See agent.php's pollAndHandleControl().
     */
    public const ACTION_RELOAD_CONFIG = 'reload_config';
    public const ACTION_UPGRADE = 'upgrade';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_AWAITING_HEARTBEAT = 'awaiting_heartbeat';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'action',
        'status',
        'expected_version',
        'expected_commit_hash',
        'expected_branch',
        'repository_mapping',
        'upgrade_release_id',
        'reported_version',
        'reported_commit_hash',
        'package_tree_hash',
        'upgrade_started_at',
        'upgrade_verified_at',
        'requested_by',
        'output',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'upgrade_started_at' => 'datetime',
            'upgrade_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LocalAgentControlJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function upgradeRelease(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Release::class, 'upgrade_release_id');
    }
}
