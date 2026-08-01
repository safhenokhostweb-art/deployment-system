<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentLogEntry extends Model
{
    protected $fillable = [
        'deployment_id',
        'sequence',
        'step_name',
        'command',
        'output',
        'exit_code',
        'duration_ms',
        'timed_out',
        'status',
        'step_type',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'timed_out' => 'boolean',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
