<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One deploy destination within an Environment. References — does not
 * own — a Server, so one Server can be referenced by many Targets across
 * many Environments/Projects (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md §3).
 */
class DeploymentTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'environment_id',
        'server_id',
        'agent_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function deploymentTasks(): HasMany
    {
        return $this->hasMany(DeploymentTask::class);
    }
}
