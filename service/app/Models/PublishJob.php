<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5: one Local Agent publish/rollback request/result, independent
 * of the Deployment/DeploymentTask/AgentJob domain — a publish has no
 * pipeline, it only ever runs git commands against a developer's own
 * checkout. `action` discriminates status_check/publish/rollback within
 * one table, mirroring OperationJob.operation_type.
 */
class PublishJob extends Model
{
    use HasFactory;

    public const ACTION_STATUS_CHECK = 'status_check';
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_ROLLBACK = 'rollback';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    /** Agent-reported: its own command exceeded a configured timeout and was terminated. */
    public const STATUS_TIMED_OUT = 'timed_out';
    /** Hub-inferred (deployments:recover-stale): the claiming agent's lease expired with no heartbeat — crashed, killed, or lost connectivity. */
    public const STATUS_ABANDONED = 'abandoned';

    public const FAILURE_CODE_AGENT_HEARTBEAT_LOST = 'agent_heartbeat_lost';
    public const FAILURE_CODE_JOB_LEASE_EXPIRED = 'job_lease_expired';
    public const FAILURE_CODE_PROCESS_TIMEOUT = 'process_timeout';
    public const FAILURE_CODE_AGENT_RESTARTED = 'agent_restarted';
    public const FAILURE_CODE_LATE_CALLBACK_REJECTED = 'late_callback_rejected';

    /**
     * Root-cause fix (full local build + artifact pipeline): a publish is
     * only ever reported `succeeded` once the local composer/npm build,
     * the artifact archive, and its upload to the Hub have ALL
     * succeeded — see local-agent/lib/LocalBuildRunner.php and
     * ArtifactBuilder/ArtifactUploader. A project with neither
     * composer.json nor package.json never reaches the composer/npm
     * codes below (both stages are skipped entirely, not "succeeded").
     */
    public const FAILURE_CODE_LOCAL_COMPOSER_FAILED = 'local_composer_failed';
    public const FAILURE_CODE_LOCAL_NPM_INSTALL_FAILED = 'local_npm_install_failed';
    public const FAILURE_CODE_LOCAL_ASSET_BUILD_FAILED = 'local_asset_build_failed';
    public const FAILURE_CODE_ARTIFACT_BUILD_FAILED = 'artifact_build_failed';
    public const FAILURE_CODE_ARTIFACT_UPLOAD_FAILED = 'artifact_upload_failed';
    public const FAILURE_CODE_ARTIFACT_MISSING_VENDOR = 'artifact_missing_vendor';
    public const FAILURE_CODE_ARTIFACT_MISSING_BUILD = 'artifact_missing_build';

    /**
     * Root-cause fix (v1.4.2): a required PHP extension (e.g. ext-gd for
     * phpoffice/phpspreadsheet) missing from the Local Agent's configured
     * PHP binary — detected by LocalBuildRunner's own `composer
     * check-platform-reqs` pre-check BEFORE `composer install` ever
     * starts, so it never gets lumped into the generic
     * local_composer_failed. See LocalBuildRunner::assertPlatformRequirementsSatisfied().
     */
    public const FAILURE_CODE_MISSING_PHP_EXTENSION = 'missing_php_extension';

    /** @var array<int, string> every value the Local Agent may report via report()'s failure_code field. */
    public const AGENT_REPORTABLE_FAILURE_CODES = [
        self::FAILURE_CODE_PROCESS_TIMEOUT,
        self::FAILURE_CODE_LOCAL_COMPOSER_FAILED,
        self::FAILURE_CODE_LOCAL_NPM_INSTALL_FAILED,
        self::FAILURE_CODE_LOCAL_ASSET_BUILD_FAILED,
        self::FAILURE_CODE_ARTIFACT_BUILD_FAILED,
        self::FAILURE_CODE_ARTIFACT_UPLOAD_FAILED,
        self::FAILURE_CODE_ARTIFACT_MISSING_VENDOR,
        self::FAILURE_CODE_ARTIFACT_MISSING_BUILD,
        self::FAILURE_CODE_MISSING_PHP_EXTENSION,
    ];

    protected $fillable = [
        'public_id',
        'local_agent_id',
        'project_id',
        'action',
        'status',
        'attempt',
        'commit_message',
        'changed_files',
        'blocked_files',
        'files_changed_count',
        'result_commit_hash',
        'requested_by',
        'approved_by',
        'approved_at',
        'output',
        'error_message',
        'failure_code',
        'recovery_metadata',
        'started_at',
        'heartbeat_at',
        'lease_expires_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'blocked_files' => 'array',
            'recovery_metadata' => 'array',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PublishJob $job): void {
            $job->public_id ??= (string) Str::uuid();
        });
    }

    public function localAgent(): BelongsTo
    {
        return $this->belongsTo(LocalAgent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
            self::STATUS_TIMED_OUT,
            self::STATUS_ABANDONED,
        ], true);
    }

    /**
     * A job the Hub considers "actively claimed" — running with a lease
     * that hasn't expired yet. False for a lease-less legacy row (one
     * claimed before this column existed) so it's picked up by recovery
     * on its very first eligible sweep rather than being treated as
     * perpetually fresh.
     */
    public function isLeaseExpired(): bool
    {
        return $this->status === self::STATUS_RUNNING
            && ($this->lease_expires_at === null || $this->lease_expires_at->isPast());
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return ($this->finished_at ?? now())->diffInSeconds($this->started_at);
    }
}
