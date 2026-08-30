<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Task 2: one row per GitHub webhook delivery ID ever accepted (post
 * signature verification). Its unique constraint on `delivery_id` is
 * what makes duplicate-delivery rejection atomic under concurrent
 * requests — the same pattern already used for AgentRequestNonce.
 */
class WebhookDelivery extends Model
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'delivery_id',
        'project_id',
        'event',
        'status',
        'commit_hash',
        'branch',
        'dispatched_at',
        'processing_at',
        'processed_at',
        'failed_at',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'processing_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
