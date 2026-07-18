<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRequestNonce extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'server_id',
        'nonce',
    ];
}
