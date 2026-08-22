<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory;

    /** Informational only (Phase 4-D onboarding wizard) — nothing in the deploy/execution path reads this. */
    public const TYPE_VPS = 'vps';
    public const TYPE_CPANEL = 'cpanel';
    public const TYPE_SHARED_HOSTING = 'shared_hosting';
    public const TYPE_DOCKER = 'docker';

    protected $fillable = [
        'public_id',
        'name',
        'description',
        'type',
        'agent_token_hash',
        'signing_secret',
        'is_active',
        'last_seen_at',
        'agent_version',
    ];

    protected $hidden = [
        'agent_token_hash',
        'signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'signing_secret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            $server->public_id ??= (string) Str::uuid();
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function deploymentTargets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function operationJobs(): HasMany
    {
        return $this->hasMany(OperationJob::class);
    }

    /**
     * Phase 4-K: Laravel-group operations (cache_clear, etc.) need a
     * project's working_directory/php_binary — resolvable only when this
     * server hosts exactly one Project, whether reached via the legacy
     * direct link or the new-domain Environment/DeploymentTarget path.
     * Mirrors ProjectController::soleApprovedAgentFor()'s existing
     * "only offer this when it's unambiguous" convention. Returns null
     * (not an error) for zero or multiple candidates — the Command
     * Center UI simply doesn't offer Laravel-group actions in that case.
     */
    public function soleProject(): ?Project
    {
        $legacyProjectIds = $this->projects()->pluck('id');
        $newDomainProjectIds = Project::whereHas(
            'environments.deploymentTargets',
            fn ($query) => $query->where('server_id', $this->id)
        )->pluck('id');

        $candidateIds = $legacyProjectIds->merge($newDomainProjectIds)->unique();

        return $candidateIds->count() === 1 ? Project::find($candidateIds->first()) : null;
    }

    /**
     * A server is considered online when either:
     *
     * 1. At least one approved Agent assigned to this server has reported
     *    within the configured offline threshold; or
     * 2. The legacy Server heartbeat itself is still within that threshold.
     *
     * Agent heartbeat is the primary source for the current architecture.
     * Server.last_seen_at remains as a backward-compatible fallback for
     * legacy server-authenticated installations.
     */
    public function isOnline(): bool
    {
        $thresholdSeconds = (int) config('deployment.server_offline_threshold_seconds');
        $cutoff = now()->subSeconds($thresholdSeconds);

        $hasOnlineApprovedAgent = $this->relationLoaded('agents')
            ? $this->agents->contains(fn (Agent $agent) =>
                $agent->status === Agent::STATUS_APPROVED
                && $agent->last_seen_at !== null
                && $agent->last_seen_at->greaterThan($cutoff)
            )
            : $this->agents()
                ->where('status', Agent::STATUS_APPROVED)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>', $cutoff)
                ->exists();

        if ($hasOnlineApprovedAgent) {
            return true;
        }

        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan($cutoff);
    }
}
