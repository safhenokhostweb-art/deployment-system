<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Deployment extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTION_DEPLOY = 'deploy';
    public const ACTION_ROLLBACK = 'rollback';

    public const TRIGGER_WEBHOOK = 'webhook';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_ROLLBACK = 'rollback';

    protected $fillable = [
        'public_id',
        'project_id',
        'server_id',
        'action',
        'status',
        'commit_hash',
        'previous_commit_hash',
        'branch',
        'trigger',
        'triggered_by',
        'approved_by',
        'approved_at',
        'queued_at',
        'started_at',
        'finished_at',
        'failure_message',
        'rollback_of_deployment_id',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deployment $deployment): void {
            $deployment->public_id ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'rollback_of_deployment_id');
    }

    public function logEntries(): HasMany
    {
        return $this->hasMany(DeploymentLogEntry::class)->orderBy('sequence');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
