<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Deployment extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    /**
     * Recipe-only intermediate phases (Phase 4 of the sandogh deployment
     * overhaul) — a plain `pipeline` deployment never passes through
     * these, going straight running -> succeeded/failed exactly as
     * before. A recipe-driven deployment reports these via the same
     * AgentJobService::updateStatus() endpoint as its typed steps
     * execute, so the dashboard can show real build progress instead of
     * one opaque "running" spinner for several minutes.
     */
    public const STATUS_BUILDING = 'building';
    public const STATUS_MIGRATING = 'migrating';
    public const STATUS_ACTIVATING = 'activating';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    /** Multi-target only (Phase 3-C.2): some tasks succeeded, some didn't. */
    public const STATUS_PARTIAL_SUCCESS = 'partial_success';
    /**
     * The agent reverted `current` to the previous release (automatically
     * after a post-activation health-check failure, or on a manual
     * rollback request) and confirmed the previous release is healthy
     * again. Distinct from ACTION_ROLLBACK's legacy meaning (a brand-new
     * Deployment row that rebuilds an older commit from scratch) — this
     * is the fast, artifact-reuse in-place rollback recipe-based
     * deployments use. See rolled_back_at.
     */
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const ACTION_DEPLOY = 'deploy';
    public const ACTION_ROLLBACK = 'rollback';

    public const TRIGGER_WEBHOOK = 'webhook';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_ROLLBACK = 'rollback';
    /**
     * Created by the publish-to-deployment bridge (PublishJobService) for a
     * no-source-change publish that succeeded without a git push — the one
     * case where the GitHub push webhook cannot be the deployment trigger.
     */
    public const TRIGGER_PUBLISH = 'publish';

    public const FAILURE_CODE_BUILD_FAILED = 'build_failed';
    public const FAILURE_CODE_MIGRATION_FAILED = 'migration_failed';
    public const FAILURE_CODE_MIGRATION_REQUIRES_APPROVAL = 'migration_requires_approval';
    public const FAILURE_CODE_ACTIVATION_FAILED = 'activation_failed';
    public const FAILURE_CODE_SERVICE_RESTART_FAILED = 'service_restart_failed';
    public const FAILURE_CODE_HEALTH_CHECK_FAILED = 'health_check_failed';

    public const HTTP_HEALTH_NOT_CONFIGURED = 'not_configured';
    public const HTTP_HEALTH_PENDING = 'pending';
    public const HTTP_HEALTH_VERIFIED = 'verified';
    public const HTTP_HEALTH_FAILED = 'failed';
    public const EXECUTION_PENDING = 'pending';
    public const EXECUTION_SUCCEEDED = 'succeeded';
    public const EXECUTION_FAILED = 'failed';
    public const FAILURE_CODE_LEASE_EXPIRED = 'lease_expired';
    public const FAILURE_CODE_AGENT_HEARTBEAT_LOST = 'agent_heartbeat_lost';
    public const FAILURE_CODE_LATE_CALLBACK_REJECTED = 'late_callback_rejected';
    /** deployments:detect-stalled's hard ceiling (deployment_job_max_runtime_seconds) was hit — even if heartbeats kept arriving on schedule. */
    public const FAILURE_CODE_MAX_RUNTIME_EXCEEDED = 'max_runtime_exceeded';
    /** Root-cause fix (customer-site): artifact-based deploy failure codes — see ArtifactManager on the agent side. */
    public const FAILURE_CODE_ARTIFACT_DOWNLOAD_FAILED = 'artifact_download_failed';
    public const FAILURE_CODE_ARTIFACT_CHECKSUM_MISMATCH = 'artifact_checksum_mismatch';
    public const FAILURE_CODE_ARTIFACT_INTEGRITY_FAILED = 'artifact_integrity_failed';
    public const FAILURE_CODE_ARTIFACT_EXTRACT_FAILED = 'artifact_extract_failed';

    /** A project with requires_complete_artifact=true has no AVAILABLE artifact for the exact deployment commit - git fallback is forbidden, so this fails closed instead. */
    public const FAILURE_CODE_ARTIFACT_REQUIRED_MISSING = 'artifact_required_missing';

    /** A legacy Server credential polled for a DeploymentTask that only an Agent identity can claim. */
    public const FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE = 'agent_protocol_incompatible';
    /** @var array<int, string> every status a deployment can be actively executing under — see isInProgress()/isLeaseExpired(). */
    public const IN_PROGRESS_STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_BUILDING,
        self::STATUS_MIGRATING,
        self::STATUS_ACTIVATING,
        self::STATUS_VERIFYING,
    ];

    protected $fillable = [
        'public_id',
        'project_id',
        'release_id',
        'server_id',
        'action',
        'status',
        'commit_hash',
        'previous_commit_hash',
        'branch',
        'release_label',
        'release_notes',
        'trigger',
        'triggered_by',
        'approved_by',
        'approved_at',
        'queued_at',
        'started_at',
        'finished_at',
        'failure_message',
        'failure_code',
        'rollback_of_deployment_id',
        'artifact_id',
        'http_health_config',
        'execution_status',
        'execution_finished_at',
        'http_health_status',
        'http_health_verified_at',
        'http_health_details',
        'last_activity_at',
        'heartbeat_at',
        'lease_expires_at',
        'deployed_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'deployed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'http_health_config' => 'array',
            'execution_finished_at' => 'datetime',
            'http_health_verified_at' => 'datetime',
            'http_health_details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deployment $deployment): void {
            $deployment->public_id ??= (string) Str::uuid();

            if ($deployment->action === self::ACTION_DEPLOY
                && $deployment->artifact_id === null
                && Project::query()->whereKey($deployment->project_id)->value('requires_complete_artifact')) {
                throw new \LogicException('Artifact-required deployments must have an artifact before creation.');
            }
        });

        static::updating(function (Deployment $deployment): void {
            $claimableStatuses = [self::STATUS_QUEUED, ...self::IN_PROGRESS_STATUSES];

            if ($deployment->action === self::ACTION_DEPLOY
                && $deployment->artifact_id === null
                && in_array($deployment->status, $claimableStatuses, true)
                && ($deployment->isDirty('status') || $deployment->isDirty('artifact_id'))
                && Project::query()->whereKey($deployment->project_id)->value('requires_complete_artifact')) {
                throw new \LogicException('An artifact-required deployment cannot enter the execution queue without an artifact.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Root-cause fix (customer-site direct-GitHub-dependency removal):
     * null for every Deployment whose project/commit had no matching
     * Artifact at creation time — the agent then runs the exact legacy
     * git-based pipeline, unchanged. See DeploymentDispatcher.
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'rollback_of_deployment_id');
    }

    public function sentinelVerificationRuns(): HasMany
    {
        return $this->hasMany(SentinelVerificationRun::class)->orderByDesc('collected_at')->orderByDesc('id');
    }

    public function logEntries(): HasMany
    {
        return $this->hasMany(DeploymentLogEntry::class)->orderBy('sequence');
    }

    /**
     * New Deployment Domain (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md) —
     * empty for every deployment created through the legacy single-target
     * path (nothing creates DeploymentTask rows for those). Only present
     * once a future multi-target orchestrator (not built in this phase)
     * fans a Deployment out across several DeploymentTargets.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(DeploymentTask::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_PARTIAL_SUCCESS,
            self::STATUS_ROLLED_BACK,
        ], true);
    }

    /**
     * Whether this terminal deployment can be retried from the UI. True for
     * failed and partial_success only — succeeded, cancelled and rolled_back
     * are not retryable (a rollback of a previous success is expressed
     * through the existing rollback flow, never through retry).
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_PARTIAL_SUCCESS], true);
    }

    /**
     * A deployment the Hub considers "actively claimed" — running (in any
     * of the running/building/migrating/activating/verifying phases)
     * with a lease that hasn't expired yet. False for a lease-less
     * legacy row (one claimed before this column existed, or one whose
     * agent never renewed it) so it's picked up by
     * deployments:detect-stalled on its very first eligible sweep rather
     * than being treated as perpetually fresh.
     */
    public function isLeaseExpired(): bool
    {
        return $this->isInProgress()
            && ($this->lease_expires_at === null || $this->lease_expires_at->isPast());
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, self::IN_PROGRESS_STATUSES, true);
    }

    /**
     * Phase 5's strong success signal: `finished_at` (and, for a legacy
     * pipeline deployment, `status === succeeded`) only ever meant "the
     * agent's shell commands all exited 0" — never that anyone verified
     * the resulting service is actually serving the new code.
     * `deployed_at` is set once (a) there was no recipe to verify against
     * at all (finished_at IS the whole story, so it's set alongside it —
     * every pre-recipe Project keeps behaving exactly as before) or (b)
     * activation plus every recipe health-check step actually passed.
     */
    public function isVerifiedDeployed(): bool
    {
        return $this->http_health_status === self::HTTP_HEALTH_VERIFIED;
    }

    public function hasHttpHealthCheck(): bool
    {
        return is_array($this->http_health_config)
            && ($this->http_health_config['enabled'] ?? false) === true;
    }

    /**
     * Phase 4-I Task 7: a friendly release identifier when one was set on
     * manual deploy, falling back to a short commit hash for every
     * deployment created before this phase (or through the webhook/rollback
     * paths, which don't collect one) — never blank.
     */
    public function displayLabel(): string
    {
        return $this->release_label ?: substr($this->commit_hash, 0, 10);
    }

    /**
     * Wall-clock duration once the deployment has actually started —
     * null while still pending_approval/queued (nothing to measure yet).
     * Carbon 3's diffInSeconds($date) returns ($date - $this) — the
     * opposite sign of Carbon 2 — so the duration is computed FROM the
     * earlier timestamp (started_at) to the later one (finished_at /
     * now) to yield the intended positive execution time.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at ?? now());
    }
}
