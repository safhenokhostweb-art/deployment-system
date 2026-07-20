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
    public const UPDATED_AT = null;

    protected $fillable = [
        'delivery_id',
        'project_id',
        'event',
    ];
}
