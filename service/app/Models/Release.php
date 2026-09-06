<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Release extends Model
{
    use HasFactory;

    public const STATUS_SOURCE_RECEIVED = 'source_received';
    public const STATUS_AWAITING_ARTIFACT = 'awaiting_artifact';
    public const STATUS_ARTIFACT_BUILDING = 'artifact_building';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const READINESS_POLICY_EXACT_COMMIT = 'exact_commit_evidence_v1';
    public const READINESS_POLICY_LEGACY_FALLBACK = 'legacy_fallback_v1';

    protected $fillable = [
        'public_id',
        'project_id',
        'commit_hash',
        'branch',
        'status',
        'source',
        'readiness_policy',
        'legacy_fallback_eligible_at',
        'legacy_fallback_reason',
        'legacy_fallback_eligible_by',
        'source_received_at',
        'artifact_checked_at',
        'ready_at',
        'failed_at',
        'failure_code',
        'failure_message',
        'metadata',
        'dependency_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'source_received_at' => 'datetime',
            'legacy_fallback_eligible_at' => 'datetime',
            'artifact_checked_at' => 'datetime',
            'ready_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
            'dependency_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Release $release): void {
            $release->public_id ??= (string) Str::uuid();
            $release->source_received_at ??= now();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isLegacyFallbackEligible(): bool
    {
        return $this->readiness_policy === self::READINESS_POLICY_LEGACY_FALLBACK
            && in_array($this->source, ['project_contract', 'admin_backfill', 'import', 'legacy'], true)
            && $this->legacy_fallback_eligible_at !== null
            && is_string($this->legacy_fallback_reason)
            && $this->legacy_fallback_reason !== ''
            && is_string($this->legacy_fallback_eligible_by)
            && $this->legacy_fallback_eligible_by !== '';
    }
}
