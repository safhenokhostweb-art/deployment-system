<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One concrete claim-and-execute attempt of a DeploymentTask by a
 * specific Agent. Every retry is a new row (unique on
 * (deployment_task_id, attempt)) — never an update to a prior attempt —
 * so execution history is fully auditable per try.
 */
class AgentJob extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'public_id',
        'deployment_task_id',
        'agent_id',
        'attempt',
        'status',
        'started_at',
        'finished_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AgentJob $agentJob): void {
            $agentJob->public_id ??= (string) Str::uuid();
        });
    }

    public function deploymentTask(): BelongsTo
    {
        return $this->belongsTo(DeploymentTask::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function logEntries(): HasMany
    {
        return $this->hasMany(AgentJobLogEntry::class)->orderBy('sequence');
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
