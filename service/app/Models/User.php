<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Authorization\PermissionMatrix;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_VIEWER = 'viewer';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasPermission(string $permission): bool
    {
        return PermissionMatrix::roleHasPermission($this->role, $permission);
    }

    /**
     * Phase 4-G resource-level authorization — which Projects this User
     * was explicitly granted membership on. Irrelevant for Admins, who
     * bypass this via ProjectPolicy::before().
     */
    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Phase 4-J: shadows Illuminate\Notifications\Notifiable's own
     * notifications() (a MorphMany over Laravel's never-migrated
     * database-notifications table — see
     * docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md §3) with this app's
     * own custom Notification table. Nothing in this codebase ever called
     * the trait's version, so this is a clean override, not a conflict.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    /**
     * Task 8: preferences are opt-out — an absent key (every user before
     * this phase, and any type never explicitly turned off) means the
     * notification stays on.
     */
    public function wantsNotification(string $type): bool
    {
        return (bool) ($this->notification_preferences[$type] ?? true);
    }
}
