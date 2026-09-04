<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A Hub -> Local Agent request to materialize one exact-commit
 * ServerAgentPackage. The Local Agent is the only already-trusted,
 * git-capable machine in this system (the Server Agent itself never
 * reaches git or GitHub) — it already performs this exact class of work
 * for its own Linux self-upgrade (see
 * local-agent/lib/LinuxUpgradePackageMaterializer.php), reused here for
 * the agent/ subtree instead of deployment-local-agent/.
 */
class ServerAgentPackageBuildJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'commit_hash',
        'branch',
        'agent_version',
        'repository_mapping',
        'status',
        'server_agent_package_id',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServerAgentPackageBuildJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function serverAgentPackage(): BelongsTo
    {
        return $this->belongsTo(ServerAgentPackage::class);
    }
}
