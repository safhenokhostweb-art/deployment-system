<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalAgentRequestNonce extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'local_agent_id',
        'nonce',
    ];
}
