<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-attempt step log for the new Deployment Domain — the AgentJob
 * analog of the legacy DeploymentLogEntry. A separate table rather than
 * a shared one because deployment_log_entries' existing
 * unique(deployment_id, sequence) constraint would otherwise collide the
 * moment two different targets of the same Deployment both log their
 * own "sequence 1" (see the Phase 3-C.2 implementation report).
 */
class AgentJobLogEntry extends Model
{
    protected $fillable = [
        'agent_job_id',
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

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class);
    }
}
