<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLogEntry extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_GITHUB = 'github';
    public const ACTOR_AGENT = 'agent';
    public const ACTOR_USER = 'user';

    protected $fillable = [
        'actor_type',
        'actor_identifier',
        'action',
        'subject_type',
        'subject_id',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(
        string $actorType,
        ?string $actorIdentifier,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
    ): self {
        return self::create([
            'actor_type' => $actorType,
            'actor_identifier' => $actorIdentifier,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
        ]);
    }
}
