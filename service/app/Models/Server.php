<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'name',
        'description',
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

    /**
     * A server is "online" if it has made any authenticated call (poll,
     * heartbeat, log, or status update — all update last_seen_at) within
     * the configured threshold. Computed, not stored, so it's always
     * accurate and never needs a scheduled write to stay correct.
     */
    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $thresholdSeconds = config('deployment.server_offline_threshold_seconds');

        return $this->last_seen_at->greaterThan(now()->subSeconds($thresholdSeconds));
    }
}
