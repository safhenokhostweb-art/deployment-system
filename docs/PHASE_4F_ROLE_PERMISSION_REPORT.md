# Phase 4-F — Role & Permission Foundation Implementation Report

## 1. Executive Summary

This phase adds real, backend-enforced authorization to a system where, until now, any authenticated user could do anything — a gap every one of Phases 4-C.5 through 4-E flagged explicitly, and Phase 4-C.5's `manage-deployment-topology` Gate named as its own literal placeholder for "a future phase." This is that phase: four roles (Admin/Operator/Developer/Viewer), a capability-based permission matrix, and every sensitive Controller action gated with `Gate::authorize('resource.action')` instead of a role `if` statement.

The central risk this phase carried — enforcing real restrictions without breaking any of the 180 existing tests, all of which act as an implicit "admin can do everything" user — was resolved by making every user's `role` default to `'admin'`, both at the database level and (critically) in `UserFactory`, so `User::factory()->create()` behaves exactly as it always has unless a test or operator explicitly assigns a different role. One real bug surfaced by this exact tension is documented in §9, not hidden.

199/199 tests pass (180 from before this phase, unmodified, + 19 new). One additive migration ran (nullable-equivalent `users.role`, defaulting to `'admin'`).

## 2. Pre-Implementation Audit

**Authentication**: `AuthController` — plain Laravel session auth (`Auth::attempt`), no role/permission concept anywhere. `User` model had no `role` column. Every dashboard route sits behind one `auth` middleware group with no per-route or per-action distinction.

**Authorization**: exactly one `Gate::define()` existed — `manage-deployment-topology` from Phase 4-C.5, literally `fn (User $user) => true`, whose own doc comment named it as "a seam a future phase can tighten." No Policy classes existed anywhere in the codebase.

**Controllers audited**: `ProjectController`, `ServerController`, `AgentController`, `DeploymentController`, `DeploymentTaskController`, `GitHubAccountController`, `EnvironmentController`, `DeploymentTargetController` — every mutating action (create, update, delete, approve, disable, rotate, deploy, rollback, connect, disconnect) had zero authorization beyond "is logged in."

**Views audited**: every dashboard view renders every button/form unconditionally to any authenticated user — no permission-aware UI existed anywhere.

**Conclusion — best insertion point**: Laravel's native `Gate` system, specifically a single `Gate::before()` hook resolving any dot-notation ability (`"resource.action"`) against a role→permission matrix. This requires zero Policy classes, zero per-ability `Gate::define()` calls, and lets `$user->can(...)`, `Gate::authorize(...)`, and Blade's `@can` all work immediately and consistently everywhere, exactly matching Task 5's explicit rule against scattering `if ($user->role === ...)` checks through Controllers.

## 3. Architecture Decision

**Hybrid (Option C)**, chosen over pure Gate/Policy (Option A, no stored role — unworkable, since Task 3 explicitly wants named, assignable roles) and full Role/Permission database tables (Option B — a `roles`/`permissions`/pivot schema, rejected as premature: this phase is explicitly foundation-only, and a full RBAC schema is the kind of thing that's easy to get wrong before real usage patterns exist).

The hybrid: a plain `role` string column on `users` (stored, simple, one query to check) + a permission matrix defined in PHP (`App\Support\Authorization\PermissionMatrix`) + `Gate::before()` resolving every dot-notation ability against it. This is deliberately shaped so a **future full database-backed RBAC phase changes only `PermissionMatrix`'s internals** — every call site (`$user->can('deployments.rollback')`, `@can('servers.create')`, `Gate::authorize('projects.update')`) is already the exact abstraction that phase would need; nothing here needs rewriting later, matching the brief's explicit framing of this phase as foundation for a system "در آینده قابل توسعه."

## 4. Role Design

| Role | Summary | Source |
|---|---|---|
| **Admin** | Everything (`'*'` in the matrix) — users, GitHub, servers, agents, projects, deploy, rollback, settings. | Task 3 |
| **Operator** | View everything; deploy and roll back. Cannot manage credentials or GitHub connections. | Task 3 |
| **Developer** | View everything; trigger deploys. Cannot manage servers, GitHub, or roll back (Task 3 lists rollback only under Operator/Admin). | Task 3 |
| **Viewer** | View-only across dashboard, projects, servers, agents, GitHub, deployments. No mutation of any kind. | Task 3 |

`users.role` defaults to `'admin'` at the database level — every user in the system today, and every future user created without an explicit role, keeps today's "any authenticated user can do anything" behavior unless deliberately downgraded via the new Users page (§8).

## 5. Permission Matrix

```
projects.view / .create / .update / .delete
servers.view / .create / .update / .delete / .rotate-credentials
github.view / .connect / .disconnect
deployments.view / .create / .rollback
agents.view / .approve / .disable
environments.manage   (Environment + DeploymentTarget mutation — the Phase 4-C.5 seam, closed here)
users.manage
```

| Permission | Admin | Operator | Developer | Viewer |
|---|:-:|:-:|:-:|:-:|
| `*.view` (projects/servers/agents/github/deployments) | ✅ | ✅ | ✅ | ✅ |
| `deployments.create` | ✅ | ✅ | ✅ | ❌ |
| `deployments.rollback` | ✅ | ✅ | ❌ | ❌ |
| `projects.create/.update/.delete` | ✅ | ❌ | ❌ | ❌ |
| `servers.create/.update/.delete/.rotate-credentials` | ✅ | ❌ | ❌ | ❌ |
| `github.connect/.disconnect` | ✅ | ❌ | ❌ | ❌ |
| `agents.approve/.disable` | ✅ | ❌ | ❌ | ❌ |
| `environments.manage` | ✅ | ❌ | ❌ | ❌ |
| `users.manage` | ✅ | ❌ | ❌ | ❌ |

`servers.update`/`.delete` and `projects.delete` are defined in the matrix's vocabulary but have no Controller action to attach to yet (no server/project delete route exists anywhere in the app) — included for completeness/forward-compatibility, not because anything currently checks them.

## 6. Authorization Layer

```php
// AppServiceProvider::boot()
Gate::before(function (User $user, string $ability) {
    if (!str_contains($ability, '.')) {
        return null; // not one of ours — let named Gates handle it
    }
    return PermissionMatrix::roleHasPermission($user->role, $ability);
});
```

Every Controller action calls `Gate::authorize('resource.action')` at its top — a 403 `AuthorizationException` on denial, exactly Laravel's standard behavior, with zero `if ($user->role === ...)` anywhere in any Controller (Task 5's explicit rule). The pre-existing `manage-deployment-topology` Gate (Phase 4-C.5) now delegates to `$user->hasPermission('environments.manage')` instead of its old unconditional `true` — the exact tightening its own doc comment predicted a future phase would do.

## 7. Protected Operations

Every operation Task 6 named is now gated:

| Operation | Permission | Controller#method |
|---|---|---|
| Connect / disconnect GitHub account | `github.connect` / `github.disconnect` | `GitHubAccountController` |
| Add server | `servers.create` | `ServerController::store` |
| Rotate server credentials | `servers.rotate-credentials` | `ServerController::rotateToken` |
| Approve / reject agent | `agents.approve` | `AgentController::approve/reject/createRegistration` |
| Disable / re-enable agent | `agents.disable` | `AgentController::disable/reenable` |
| Deploy (manual trigger, approve, cancel) | `deployments.create` | `ProjectController::deployManual`, `DeploymentController::approve/cancel` |
| Rollback (deployment or per-target) | `deployments.rollback` | `DeploymentController::rollback`, `DeploymentTaskController::rollback` |
| Create/update project | `projects.create` / `projects.update` | `ProjectController::store/update/rotateWebhookSecret` |
| Environment/Target create, toggle, delete | `environments.manage` | `EnvironmentController`, `DeploymentTargetController` (via `manage-deployment-topology`) |

## 8. UI Permission Handling

Every sensitive button/form/link identified in the audit is now wrapped in `@can('...')` across `layouts/app.blade.php` (sidebar), `dashboard/index.blade.php` (quick actions), `servers/{index,show}.blade.php`, `agents/{pending,show}.blade.php`, `projects/{index,show}.blade.php`, `deployments/show.blade.php`, `deployment-tasks/show.blade.php`, and `github/accounts/index.blade.php`. Per Task 7's explicit rule ("مخفی کردن UI جای Authorization نیست"), every one of these is a presentation-only convenience on top of a Controller-level `Gate::authorize()` call that already denies the request independently — `test_direct_url_access_to_a_protected_route_is_blocked_regardless_of_ui` in §12 proves the backend check holds even when no UI ever links to the route.

A minimal Users page (`/users`, Admin-only) was added — not because a Task explicitly asked for a full user-management UI, but because Task 3 lists "مدیریت کاربران" as an Admin capability and Task 8 requires role changes to be audit-logged, and there was no other way to actually change anyone's role otherwise. It's deliberately small: a list and one role-change form, not a full user CRUD suite.

## 9. Audit Log Integration

`user.role_changed` (new) — actor, target user, `from`/`to` role — added to `UserController::updateRole`. Everything else Task 8 asked for (`agent.approved`, `agent.disabled`, `deployment.approved` and friends, `github_account.connected`) was **already logged** by the Controllers those actions live in, going back to Phase 3-B/4-E — this phase's audit confirmed that coverage rather than needing to add it.

**One real bug found and fixed while building this, worth stating plainly**: `User::factory()->create()` initially relied on the new `users.role` column's database-level default (`'admin'`) rather than setting it explicitly in `UserFactory::definition()`. Empirically, that DB default was correctly applied to the row — but Eloquent's `create()` does **not** reflect DB-computed defaults back onto the in-memory model instance it returns, and Laravel's `$this->actingAs($user)` uses that exact in-memory instance for the rest of the test without re-querying the database. The result: every existing test's `$user->role` was `null` in the authenticated context, `PermissionMatrix::roleHasPermission(null, ...)` correctly returned `false` for everything, and every privileged action in the existing suite failed with 403. Fixed by setting `'role' => User::ROLE_ADMIN` explicitly in `UserFactory::definition()`. This is exactly the kind of thing "run the smoke tests before writing new ones" is for — it was caught and fixed before a single new test was written.

## 10. Security Review

- **Enforcement is backend-only, never UI-only** — every `@can` in a view has a corresponding `Gate::authorize()` in the Controller action it links to; §12's direct-URL test proves this.
- **No existing security control was reduced.** Nothing in this phase touches HMAC verification, credential hashing/encryption, webhook signature checking, or any Phase 1.5/3-B/3-D security work — this phase only adds a new, additional layer in front of actions that previously had no check beyond authentication.
- **No secret/credential exposure changed.** No permission check reveals or hides a token/secret value — masking, one-time display, and rotation all behave exactly as before regardless of role (a Viewer who somehow reached a page showing a masked secret would see the same mask an Admin does; they just can't reach the rotate action that would generate a new one).
- **Fail-closed, not fail-open**: `PermissionMatrix::roleHasPermission()` returns `false` for any role/permission combination not explicitly listed (including an unrecognized role string) — nothing defaults to allowed.
- **Authentication vs. authorization stay distinct**: a guest hitting a protected route is redirected to `/login` (401-equivalent, handled by the pre-existing `auth` middleware, unchanged); an authenticated user lacking permission gets a real 403 (`test_unauthenticated_requests_are_still_redirected_to_login_not_403` locks this in).

## 11. Files Changed

**New:** `app/Support/Authorization/PermissionMatrix.php`; `app/Http/Controllers/Dashboard/UserController.php`; `resources/views/users/index.blade.php`; `database/migrations/2026_07_28_090000_add_role_to_users_table.php`; `tests/Feature/Dashboard/RolePermissionTest.php`.

**Modified:** `app/Models/User.php` (`role` constants, `isAdmin()`, `hasPermission()`); `database/factories/UserFactory.php` (explicit `role` default — §9); `app/Providers/AppServiceProvider.php` (`Gate::before()`, `manage-deployment-topology` re-pointed); `app/Http/Controllers/Dashboard/{ProjectController,ServerController,AgentController,DeploymentController,DeploymentTaskController,GitHubAccountController}.php` (one `Gate::authorize()` call per action); `routes/web.php` (`users.*` routes); `resources/views/layouts/app.blade.php`, `dashboard/index.blade.php`, `servers/{index,show}.blade.php`, `agents/{pending,show}.blade.php`, `projects/{index,show}.blade.php`, `deployments/show.blade.php`, `deployment-tasks/show.blade.php`, `github/accounts/index.blade.php` (`@can` wrapping); `lang/en/ui.php`, `lang/fa/ui.php`, `lang/en/messages.php`, `lang/fa/messages.php`, `lang/en/nav.php`, `lang/fa/nav.php` (new key groups only).

**Untouched:** every Model beyond `User`, every Migration beyond the one new column, `AuthController`/session auth, `EnvironmentController`/`DeploymentTargetController`'s own logic (only their existing Gate's closure body changed), `GitHubAppClient`, `DeploymentDispatcher`, `RollbackService`, and the entire agent-facing API (`AgentJobController`, `AgentRegistrationController`) — none of it has any concept of dashboard users or roles, and none of it was touched.

## 12. Tests Result

```
Full suite: 199/199 passed, 516 assertions (180 pre-existing + 19 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/RolePermissionTest.php`):

1. A default `User::factory()->create()` is `role='admin'` and has full permission access.
2. Admin can create a server.
3. Viewer can view dashboard, servers, projects, agents, GitHub — all four `.view` permissions.
4. Viewer cannot create a server (`create` **and** `store`, both 403).
5. Viewer cannot rotate server credentials.
6. Viewer cannot connect or disconnect GitHub.
7. Viewer cannot approve or disable agents.
8. Viewer cannot deploy or roll back.
9. Operator can deploy.
10. Operator cannot manage servers or GitHub.
11. Developer can trigger deploy but cannot roll back.
12. Developer cannot manage servers.
13. Environment/Target management requires Admin (Operator denied).
14. **Direct URL access is blocked** regardless of UI — Task 9's explicit security requirement.
15. UI hides the "Register server" button for Viewer, shows it for Admin.
16. UI hides the Rollback button for Developer, shows it for Operator.
17. Only Admin can view/use the Users page; a non-admin's attempt to change a role is both 403'd and verified to not have actually changed anything.
18. Admin changing a user's role succeeds and is audit-logged.
19. An unauthenticated request still redirects to login (not 403) — authentication and authorization stay distinct failure modes.

## 13. Remaining Work

- This is explicitly a **foundation**, not a full RBAC system: no per-project or per-server scoping (a Developer sees *all* projects, not an assigned subset), no custom/composable roles, no permission table an Admin can edit through the UI (the matrix is code, deployed with the app). All named as expected next steps by the brief itself ("سیستم کامل Multi-Tenant Permission در آینده").
- `servers.update`/`.delete` and `projects.delete` permissions exist in the matrix but have no route/Controller action yet — inert until those actions exist.
- No self-service "forgot my role" or role-request flow — an Admin must proactively use the new Users page.
- The Users page has no search/pagination — fine at current scale, same caveat every other list-page phase has already flagged.

## 14. Next Phase Recommendation

With role-based access control now real and enforced, the natural next increment is per-resource scoping — e.g., a Developer restricted to specific Projects rather than all of them — which is exactly the "Multi-Tenant" direction the brief names as this foundation's purpose. That, and the GitHub installation-webhook listener Phase 4-E's report flagged, are the two most consequential open items across the last two phases.
