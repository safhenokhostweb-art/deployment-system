<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentHealthCheck extends Model
{
    protected $fillable = [
        'deployment_task_id',
        'type',
        'config',
        'status',
        'result_message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function deploymentTask(): BelongsTo
    {
        return $this->belongsTo(DeploymentTask::class);
    }
}
