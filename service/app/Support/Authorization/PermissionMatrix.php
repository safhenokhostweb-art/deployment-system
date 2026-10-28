<?php

namespace App\Support\Authorization;

use App\Models\User;

/**
 * Phase 4-F Role & Permission Foundation
 * (docs/PHASE_4F_ROLE_PERMISSION_REPORT.md). Deliberately a plain PHP
 * array, not a database table — this phase is explicitly foundation-only.
 * Every call site that consults this (Gate::before in AppServiceProvider,
 * $user->can(...), Blade's @can) is the same abstraction a future
 * database-backed RBAC phase would use too — only this class's internals
 * would need to change, never the call sites.
 */
class PermissionMatrix
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function map(): array
    {
        return [
            User::ROLE_ADMIN => ['*'],

            // Task 3: "اجرای Deploy، مشاهده Logs، Rollback... بدون مدیریت
            // Credential، بدون تغییر GitHub Connection".
            User::ROLE_OPERATOR => [
                'projects.view',
                'servers.view',
                'agents.view',
                'github.view',
                'deployments.view',
                'deployments.create',
                'deployments.rollback',
                // Phase 4-K Task 9: "Operator: اجرا" — can run an
                // operation, not just see the Command Center.
                'operations.view',
                'operations.execute',
                // Phase 5: One-Click Publish is exactly the kind of
                // "run a deploy-adjacent action without managing
                // credentials/GitHub connections" capability Operator
                // already has for deployments/operations.
                'local_agents.view',
                'local_agents.publish',
                // Visual Agent Management Dashboard: see the canvas only.
                // workspace_agents.manage (run/stop/toggle/save) stays
                // Admin-only — it writes agents.json and starts processes
                // on the Hub host.
                'workspace_agents.view',
            ],

            // Task 3: "مشاهده Project، مشاهده Deployment، Trigger Deploy...
            // بدون مدیریت Server، بدون مدیریت GitHub". No rollback —
            // Task 3 only lists rollback under Operator/Admin. Phase 4-K
            // Task 9 doesn't name Developer at all — given view-only for
            // everything else, operations.view without .execute matches.
            User::ROLE_DEVELOPER => [
                'projects.view',
                'servers.view',
                'agents.view',
                'github.view',
                'deployments.view',
                'deployments.create',
                'operations.view',
                // Phase 5: this is the primary persona One-Click Publish
                // exists for — a developer publishing their own changes,
                // still without touching Server/GitHub management.
                'local_agents.view',
                'local_agents.publish',
                'workspace_agents.view',
            ],

            // Task 3: "فقط مشاهده: Dashboard، Project، Deployment Status".
            // Phase 4-K Task 9: "Viewer: مشاهده" — view only, never execute.
            User::ROLE_VIEWER => [
                'projects.view',
                'servers.view',
                'agents.view',
                'github.view',
                'deployments.view',
                'operations.view',
                // Phase 5: view only, matching every other capability here
                // — never .publish, never .manage.
                'local_agents.view',
                'workspace_agents.view',
            ],
        ];
    }

    public static function roleHasPermission(?string $role, string $permission): bool
    {
        $granted = self::map()[$role] ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }
}
