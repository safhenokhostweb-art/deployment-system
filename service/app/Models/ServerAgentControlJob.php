<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The Hub's only channel to remotely upgrade a running Server Agent
 * process — deliberately its own table/model, never
 * LocalAgentControlJob, so Server Agent (App\Models\Agent) and
 * Local/Build Agent (App\Models\LocalAgent) identity and lifecycles can
 * never be conflated (see docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md for why
 * these are independent concepts). Currently supports exactly one
 * action — upgrade — modeled as a constant rather than a free column so
 * a future second action doesn't silently need a data migration.
 */
class ServerAgentControlJob extends Model
{
    use HasFactory;

    public const ACTION_UPGRADE = 'upgrade';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_AWAITING_HEARTBEAT = 'awaiting_heartbeat';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'agent_id',
        'action',
        'status',
        'target_version',
        'target_commit_sha',
        'target_branch',
        'repository_mapping',
        'server_agent_package_id',
        'expected_package_hash',
        'reported_commit_hash',
        'reported_package_hash',
        'reported_version',
        'requested_by',
        'upgrade_started_at',
        'upgrade_verified_at',
        'output',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'upgrade_started_at' => 'datetime',
            'upgrade_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ServerAgentControlJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function serverAgentPackage(): BelongsTo
    {
        return $this->belongsTo(ServerAgentPackage::class);
    }
}
