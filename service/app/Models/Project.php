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
        'auto_deploy',
        'github_webhook_secret',
        'is_active',
    ];

    protected $hidden = [
        'github_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'pipeline' => 'array',
            'auto_deploy' => 'boolean',
            'is_active' => 'boolean',
            'github_webhook_secret' => 'encrypted',
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

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
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

    public function isMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }
}
