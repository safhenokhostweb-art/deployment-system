<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5: one Local Agent publish/rollback request/result, independent
 * of the Deployment/DeploymentTask/AgentJob domain — a publish has no
 * pipeline, it only ever runs git commands against a developer's own
 * checkout. `action` discriminates status_check/publish/rollback within
 * one table, mirroring OperationJob.operation_type.
 */
class PublishJob extends Model
{
    use HasFactory;

    public const ACTION_STATUS_CHECK = 'status_check';
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_ROLLBACK = 'rollback';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'project_id',
        'action',
        'status',
        'commit_message',
        'changed_files',
        'blocked_files',
        'files_changed_count',
        'result_commit_hash',
        'requested_by',
        'approved_by',
        'approved_at',
        'output',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'blocked_files' => 'array',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PublishJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_REJECTED], true);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return ($this->finished_at ?? now())->diffInSeconds($this->started_at);
    }
}
