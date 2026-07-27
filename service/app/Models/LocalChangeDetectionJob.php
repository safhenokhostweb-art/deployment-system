<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5.2: one OPEN row per (local_agent_id, project_id) at a time —
 * the Local Agent's file watcher updates the same row every cycle
 * instead of creating a new row per poll. Independent of PublishJob (see
 * LocalChangeDetectionService's docblock): most detections never become
 * a publish at all.
 */
class LocalChangeDetectionJob extends Model
{
    use HasFactory;

    public const STATUS_DETECTED = 'detected';
    public const STATUS_WAITING_APPROVAL = 'waiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_IGNORED = 'ignored';

    /**
     * A detection is still "open" (the watcher may keep updating it) in
     * these statuses; once approved/published/ignored, the next reported
     * change starts a fresh row.
     */
    public const OPEN_STATUSES = [self::STATUS_DETECTED, self::STATUS_WAITING_APPROVAL];

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'project_id',
        'changed_files',
        'files_changed_count',
        'detected_at',
        'status',
        'suggested_commit_message',
        'publish_job_id',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LocalChangeDetectionJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function publishJob(): BelongsTo
    {
        return $this->belongsTo(PublishJob::class);
    }
}
