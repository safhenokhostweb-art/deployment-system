<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * New Deployment Domain (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md §3).
 * Purely additive — a legacy Project with no Environment rows is
 * completely unaffected; see DeploymentTargetResolver.
 */
class Environment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'project_id',
        'name',
        'key',
        'status',
        'locked_by_deployment_id',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deploymentTargets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function lockedByDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'locked_by_deployment_id');
    }

    /**
     * Task 7: an Environment is available to a *different* in-flight
     * Deployment only when unlocked, or already locked by that same
     * Deployment (claiming a second target of the same rollout must not
     * be blocked by its own lock).
     */
    public function isLockedByAnotherDeployment(int $deploymentId): bool
    {
        return $this->locked_by_deployment_id !== null && $this->locked_by_deployment_id !== $deploymentId;
    }
}
