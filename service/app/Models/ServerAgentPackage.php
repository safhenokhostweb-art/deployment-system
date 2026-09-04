<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One materialized, hash-verified agent/** package for one exact commit
 * — built by a trusted, git-capable Local Agent (see
 * ServerAgentPackageBuildJob) and stored on the Hub so a Server Agent
 * (which has no git access at all, by design) can download it. Never
 * confused with Artifact — an Artifact is application deployment content
 * subject to NO CONFIRM -> NO DEPLOYMENT; a ServerAgentPackage is Server
 * Agent runtime code and is never itself deployed as an application
 * release.
 */
class ServerAgentPackage extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'public_id',
        'commit_hash',
        'agent_version',
        'repository_mapping',
        'tree_hash',
        'checksum_sha256',
        'size_bytes',
        'disk_path',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServerAgentPackage $package): void {
            $package->public_id ??= (string) Str::uuid();
        });
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
