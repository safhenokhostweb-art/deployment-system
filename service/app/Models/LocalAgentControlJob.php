<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5.3: the Hub's only channel to remotely act on a running Local
 * Agent (restart it, pull its recent log output) — there is deliberately
 * no inbound path to a developer's machine, so this is polled exactly
 * like PublishJob/LocalChangeDetectionJob. `action` discriminates within
 * one table, mirroring OperationJob.operation_type.
 */
class LocalAgentControlJob extends Model
{
    use HasFactory;

    public const ACTION_RESTART = 'restart';
    public const ACTION_FETCH_LOGS = 'fetch_logs';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'action',
        'status',
        'requested_by',
        'output',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::creating(function (LocalAgentControlJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }
}
