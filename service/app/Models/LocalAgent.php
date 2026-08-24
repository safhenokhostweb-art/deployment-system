<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Phase 5: identity/credential holder for a Local Development Agent
 * running on a developer's own machine — the counterpart to Agent, but
 * deliberately independent of it (no server_id: a Local Agent has no
 * registered Server, it runs wherever the developer's checkout lives).
 */
class LocalAgent extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'public_id',
        'status',
        'name',
        'hostname',
        'operating_system',
        'capabilities',
        'agent_version',
        'agent_token_hash',
        'signing_secret',
        'registration_token',
        'registration_token_expires_at',
        'last_seen_at',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = [
        'agent_token_hash',
        'signing_secret',
        'registration_token',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'signing_secret' => 'encrypted',
            'registration_token' => 'encrypted',
            'registration_token_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LocalAgent $agent): void {
            $agent->public_id ??= (string) Str::uuid();
        });
    }

    public function projectMappings(): HasMany
    {
        return $this->hasMany(LocalProjectMapping::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }

    public function changeDetectionJobs(): HasMany
    {
        return $this->hasMany(LocalChangeDetectionJob::class);
    }

    public function controlJobs(): HasMany
    {
        return $this->hasMany(LocalAgentControlJob::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * A cheap fingerprint of this agent's assigned project config (the
     * exact fields LocalAgentProjectSyncController serves), computed from
     * data that already exists — no new column, no extra table. Returned
     * on both the heartbeat and the project-sync endpoints so the agent
     * can tell "nothing changed since I last synced" from "the Hub has a
     * newer mapping/branch/path for me" without re-downloading and
     * re-diffing the full project list on every single poll cycle.
     * Changes whenever a mapping is added/edited/removed, or the
     * underlying Project's branch/active flag changes.
     */
    public function projectConfigVersion(): string
    {
        $fingerprint = $this->projectMappings()
            ->with('project')
            ->whereHas('project', fn ($query) => $query->where('is_active', true))
            ->get()
            ->map(fn (LocalProjectMapping $mapping) => implode('|', [
                $mapping->project->project_key,
                $mapping->sourceWorkspacePath(),
                $mapping->project->repository,
                $mapping->remote_name,
                $mapping->effectiveBranch(),
                $mapping->project->application_root ?? '.',
                $mapping->project->requires_complete_artifact ? 'artifact-required' : 'artifact-optional',
                optional($mapping->updated_at)->timestamp,
                optional($mapping->project->updated_at)->timestamp,
            ]))
            ->sort()
            ->implode(',');

        return hash('sha256', $fingerprint);
    }

    /**
     * Check-and-notify only (Phase 5.3 §5 — no auto-download/replace of
     * agent code, see config('deployment.latest_local_agent_version')'s
     * own docblock for why). False when this agent has never reported a
     * version at all (nothing to compare).
     */
    public function isUpdateAvailable(): bool
    {
        if ($this->agent_version === null) {
            return false;
        }

        return version_compare($this->agent_version, (string) config('deployment.latest_local_agent_version'), '<');
    }

    /**
     * Same computed-not-stored reasoning as Agent::isOnline() — accurate
     * by construction, never needs a scheduled write to stay correct.
     */
    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $thresholdSeconds = config('deployment.server_offline_threshold_seconds');

        return $this->last_seen_at->greaterThan(now()->subSeconds($thresholdSeconds));
    }

    public function isRegistrationTokenValid(): bool
    {
        return $this->registration_token !== null
            && $this->registration_token_expires_at !== null
            && $this->registration_token_expires_at->isFuture();
    }
}
