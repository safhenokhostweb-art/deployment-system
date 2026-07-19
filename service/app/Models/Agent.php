<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Independent identity/credential holder for a running agent process,
 * separate from Server (the "place" it runs on). See
 * docs/PHASE_2_AGENT_V2_REPORT.md §6 and docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md
 * for the design rationale.
 */
class Agent extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'public_id',
        'server_id',
        'status',
        'name',
        'agent_version',
        'os',
        'architecture',
        'ip_address',
        'capabilities',
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
        static::creating(function (Agent $agent): void {
            $agent->public_id ??= (string) Str::uuid();
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deploymentTargets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Same computed-not-stored reasoning as Server::isOnline() — accurate
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
