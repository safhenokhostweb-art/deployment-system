<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One target's execution of a (legacy-shaped) Deployment. Deliberately
 * references the existing App\Models\Deployment directly rather than a
 * second "Deployment" concept — one Deployment row can now optionally
 * fan out to N tasks, one per target.
 */
class DeploymentTask extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'deployment_id',
        'deployment_target_id',
        'status',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function deploymentTarget(): BelongsTo
    {
        return $this->belongsTo(DeploymentTarget::class);
    }

    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }
}
