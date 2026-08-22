<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'name',
        'description',
        'project_key',
        'repository',
        'branch',
        'environment',
        'server_id',
        'github_account_id',
        'pipeline',
        'deployment_recipe',
        'artifact_preserve_paths',
        'artifact_persistent_paths',
        'artifact_persistent_root',
        'requires_complete_artifact',
        'application_root',
        'auto_deploy',
        'github_webhook_secret',
        'is_active',
        'locked_by_publish_job_id',
        'locked_by_local_agent_id',
        'locked_at',
        'lock_expires_at',
    ];

    protected $hidden = [
        'github_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'pipeline' => 'array',
            'deployment_recipe' => 'array',
            'artifact_preserve_paths' => 'array',
            'artifact_persistent_paths' => 'array',
            'requires_complete_artifact' => 'boolean',
            'auto_deploy' => 'boolean',
            'is_active' => 'boolean',
            'github_webhook_secret' => 'encrypted',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->public_id ??= (string) Str::uuid();
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Phase 4-L: which connected GitHubAccount (if any) this project's
     * webhook was configured through — null for every Project created
     * before this column existed, or one whose webhook was only ever
     * set up manually in GitHub's own UI.
     */
    public function githubAccount(): BelongsTo
    {
        return $this->belongsTo(GitHubAccount::class);
    }

    public function hasGithubWebhookConfigured(): bool
    {
        return $this->github_account_id !== null;
    }

    /**
     * `deployment_recipe` (an ordered list of typed steps — see
     * RecipeValidator) takes precedence over the legacy `pipeline`
     * column whenever present and non-empty. A Project that has never
     * set one (every Project that existed before this column, and any
     * new one left on the simple pipeline form) is entirely unaffected —
     * see AgentJobService::claimNextJob().
     */
    public function usesDeploymentRecipe(): bool
    {
        return is_array($this->deployment_recipe) && $this->deployment_recipe !== [];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * New Deployment Domain (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md) —
     * empty for every project that hasn't been explicitly given a real
     * Environment. See DeploymentTargetResolver.
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function latestSuccessfulDeployment(): ?Deployment
    {
        return $this->deployments()
            ->where('status', Deployment::STATUS_SUCCEEDED)
            ->latest('finished_at')
            ->first();
    }

    /**
     * Phase 4-G resource-level authorization — empty for every Project
     * that exists before this phase (and any never explicitly granted a
     * member). See ProjectPolicy for how this combines with a User's
     * global role/permission; Admin bypasses this relation entirely.
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Phase 5: which Local Agents (developer machines) are set up to
     * publish into this project — see LocalProjectMapping.
     */
    public function localProjectMappings(): HasMany
    {
        return $this->hasMany(LocalProjectMapping::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }

    /**
     * Root-cause fix (customer-site direct-GitHub-dependency removal):
     * artifacts the Local Agent has uploaded for this Project, one per
     * commit — see ArtifactService::findMatching().
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function changeDetectionJobs(): HasMany
    {
        return $this->hasMany(LocalChangeDetectionJob::class);
    }

    public function isMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function lockedByPublishJob(): BelongsTo
    {
        return $this->belongsTo(PublishJob::class, 'locked_by_publish_job_id');
    }

    /**
     * Root-cause fix (stuck publishes): a Project can be mapped to more
     * than one Local Agent, so two developers publishing the same
     * repository at once was previously unguarded. True only while an
     * unexpired publish lock is held — an expired lock is treated as
     * free (deployments:recover-stale releases it explicitly, but a
     * claim attempt must never trust that sweep has already run).
     */
    public function isPublishLocked(): bool
    {
        return $this->locked_by_publish_job_id !== null
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isFuture();
    }

    public function isPublishLockedByAnotherJob(int $publishJobId): bool
    {
        return $this->isPublishLocked() && $this->locked_by_publish_job_id !== $publishJobId;
    }
}
