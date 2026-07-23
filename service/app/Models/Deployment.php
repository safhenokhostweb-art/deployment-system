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
    /** Multi-target only (Phase 3-C.2): some tasks succeeded, some didn't. */
    public const STATUS_PARTIAL_SUCCESS = 'partial_success';

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
        'release_label',
        'release_notes',
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

    /**
     * New Deployment Domain (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md) —
     * empty for every deployment created through the legacy single-target
     * path (nothing creates DeploymentTask rows for those). Only present
     * once a future multi-target orchestrator (not built in this phase)
     * fans a Deployment out across several DeploymentTargets.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(DeploymentTask::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_PARTIAL_SUCCESS,
        ], true);
    }

    /**
     * Phase 4-I Task 7: a friendly release identifier when one was set on
     * manual deploy, falling back to a short commit hash for every
     * deployment created before this phase (or through the webhook/rollback
     * paths, which don't collect one) — never blank.
     */
    public function displayLabel(): string
    {
        return $this->release_label ?: substr($this->commit_hash, 0, 10);
    }

    /**
     * Wall-clock duration once the deployment has actually started —
     * null while still pending_approval/queued (nothing to measure yet).
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return ($this->finished_at ?? now())->diffInSeconds($this->started_at);
    }
}
