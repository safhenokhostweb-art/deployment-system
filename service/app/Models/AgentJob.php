<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'deployment_task_id',
        'agent_id',
        'attempt',
        'status',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deploymentTask(): BelongsTo
    {
        return $this->belongsTo(DeploymentTask::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
