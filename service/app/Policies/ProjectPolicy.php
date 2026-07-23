<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Phase 4-G: the first real resource-level Policy in this app
 * (docs/PHASE_4G_RESOURCE_AUTHORIZATION_REPORT.md) — every prior phase's
 * authorization was role-only (Phase 4-F's Gate::before). Each ability
 * here composes two independent checks: does the user's global role grant
 * this permission verb at all (Phase 4-F's PermissionMatrix, unchanged),
 * AND are they specifically a member of *this* Project. Admin bypasses
 * both via before() — "دسترسی کامل" (Task 4) — which is also what keeps
 * every pre-4-G Project (zero membership rows) fully manageable by the
 * one role every existing test actually uses.
 */
class ProjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.view') && $project->isMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.update') && $project->isMember($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.delete') && $project->isMember($user);
    }

    public function deploy(User $user, Project $project): bool
    {
        return $user->hasPermission('deployments.create') && $project->isMember($user);
    }

    public function rollback(User $user, Project $project): bool
    {
        return $user->hasPermission('deployments.rollback') && $project->isMember($user);
    }
}
