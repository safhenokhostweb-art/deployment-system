<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 4-H: one Local Discovery scan request/result, independent of the
 * deployment_tasks/agent_jobs domain (see the migration's comment for
 * why). `result` is a plain array of sanitized detected-project metadata
 * — never a token, password, or full file.
 */
class DiscoveryJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'agent_id',
        'workspace_path',
        'status',
        'requested_by',
        'result',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DiscoveryJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
