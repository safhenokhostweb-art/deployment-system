<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A deployable, git-clean package of one Project at one exact commit —
 * built by the Local Agent (which has working GitHub access) via
 * `git archive`, uploaded here, and downloaded by the production Server
 * Agent instead of it ever needing to reach GitHub directly. See
 * ArtifactService for the store/match/cleanup lifecycle.
 */
class Artifact extends Model
{
    use HasFactory;

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'public_id',
        'project_id',
        'release_id',
        'publish_job_id',
        'commit_hash',
        'branch',
        'checksum_sha256',
        'size_bytes',
        'required_paths',
        'disk_path',
        'status',
        'semantic_status',
        'semantic_verified_at',
        'semantic_failure',
        'uploaded_by',
        'failure_message',
        'expires_at',
        'file_manifest_hash',
        'file_manifest_entry_count',
        'file_manifest_total_size',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
            'required_paths' => 'array',
            'semantic_verified_at' => 'datetime',
            'file_manifest_entry_count' => 'integer',
            'file_manifest_total_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Artifact $artifact): void {
            $artifact->public_id ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function publishJob(): BelongsTo
    {
        return $this->belongsTo(PublishJob::class);
    }

    /**
     * An available artifact attached to a Deployment is part of its
     * forensic/rollback trail and must not be removed by retention.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function sizeMb(): float
    {
        return round($this->size_bytes / 1024 / 1024, 2);
    }
}
