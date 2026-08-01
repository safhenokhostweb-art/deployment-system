<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4-J: the Notification Center's foundation entity. A pure
 * presentation/awareness record — never a source of truth for anything
 * else (Deployment/Agent/Server status is always read from its own
 * model, never inferred from a Notification row). See
 * docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md §3-4.
 */
class Notification extends Model
{
    use HasFactory;

    // Task 4: Deployment
    public const TYPE_DEPLOYMENT_STARTED = 'deployment.started';
    public const TYPE_DEPLOYMENT_SUCCESS = 'deployment.success';
    public const TYPE_DEPLOYMENT_FAILED = 'deployment.failed';
    public const TYPE_DEPLOYMENT_ROLLBACK = 'deployment.rollback';
    public const TYPE_DEPLOYMENT_ROLLBACK_STARTED = 'deployment.rollback_started';

    // Task 4: Agent
    public const TYPE_AGENT_CONNECTED = 'agent.connected';
    public const TYPE_AGENT_OFFLINE = 'agent.offline';
    public const TYPE_AGENT_APPROVAL_REQUIRED = 'agent.approval_required';

    // Task 4: GitHub
    public const TYPE_GITHUB_CONNECTED = 'github.connected';
    public const TYPE_GITHUB_FAILED = 'github.failed';

    // Task 4: Security
    public const TYPE_PERMISSION_CHANGED = 'permission.changed';
    public const TYPE_CREDENTIAL_ROTATED = 'credential.rotated';

    // Phase 4-K Task 10: Operations Command Center
    public const TYPE_OPERATION_STARTED = 'operation.started';
    public const TYPE_OPERATION_SUCCESS = 'operation.success';
    public const TYPE_OPERATION_FAILED = 'operation.failed';

    // Phase 5: Local Development Publisher Agent
    public const TYPE_LOCAL_AGENT_APPROVAL_REQUIRED = 'local_agent.approval_required';
    public const TYPE_PUBLISH_AWAITING_APPROVAL = 'publish.awaiting_approval';
    public const TYPE_PUBLISH_SUCCESS = 'publish.success';
    public const TYPE_PUBLISH_FAILED = 'publish.failed';
    public const TYPE_PUBLISH_ABANDONED = 'publish.abandoned';

    // Phase 5.2: Auto File Watch + Smart Publish Queue
    public const TYPE_CHANGE_DETECTION_AWAITING_APPROVAL = 'change_detection.awaiting_approval';

    /**
     * Task 9: severity is a pure data concept — every consumer (badge
     * color, filter dropdown, future email subject prefix) reads this
     * enum, never re-derives it from `type`.
     */
    public const SEVERITY_SUCCESS = 'success';
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'severity',
        'related_type',
        'related_id',
        'actor_type',
        'actor_identifier',
        'metadata',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
