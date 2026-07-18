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
        'project_key',
        'repository',
        'branch',
        'environment',
        'server_id',
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

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function latestSuccessfulDeployment(): ?Deployment
    {
        return $this->deployments()
            ->where('status', Deployment::STATUS_SUCCEEDED)
            ->latest('finished_at')
            ->first();
    }
}
