# Phase 4-G — Resource Level Authorization + Team Access Implementation Report

## 1. Executive Summary

Phase 4-F gave every action a role-level permission check (`$user->can('deployments.rollback')`), but nothing scoped *which* project that permission applied to — a Developer with `deployments.create` could trigger a deploy on every Project in the system, not just ones they were meant to work on. This phase closes that gap with the first real resource-level `Policy` in this codebase: a `project_user` membership table plus a `ProjectPolicy` that composes Phase 4-F's permission matrix (unchanged) with "is this user actually a member of this specific project."

Admin bypasses membership entirely (`ProjectPolicy::before()`), which is what makes this phase's central compatibility requirement possible: every Project that existed before this phase has zero membership rows, and stays **fully manageable by the one role every existing test actually uses**. 210/210 tests pass (199 from before this phase + 11 new). One additive migration ran. Three pre-existing Phase 4-F tests needed their *setup* adjusted (not their assertions weakened) — documented plainly in §12, since they tested exactly the un-scoped behavior this phase was explicitly asked to change.

## 2. Pre-Implementation Audit

**Authorization (Phase 4-F, confirmed unchanged):** `role` column on `users`, `App\Support\Authorization\PermissionMatrix` (role → permission list), a single `Gate::before()` resolving any dot-notation ability against it, and `Gate::define('manage-deployment-topology', ...)` for Environment/Target mutation. **No Policy class existed anywhere** — every check was role-level only.

**Models reviewed:** `Project` (no membership concept), `Server`, `Environment`, `Deployment` (belongs to `Project`), `DeploymentTarget`. `User` had `role` but no relation to any resource.

**Controllers reviewed:** `ProjectController`, `DeploymentController`, `DeploymentTaskController`, `ServerController`, `GitHubAccountController` — every one already had a Phase 4-F `Gate::authorize('resource.action')` call, but none had any concept of *which* resource instance beyond "does this role have the verb at all."

**Where current permission is insufficient:** exactly the brief's own example — `deployments.create`/`deployments.rollback`/`projects.view`/`projects.update` all needed a second, independent dimension: is this specific user allowed on this specific Project. **Resources needing scope:** `Project` (direct), `Deployment` and `DeploymentTask` (inherit their parent Project's scope — neither needed its own membership table). **Servers were audited and found already correctly scoped by role alone** (§8) — a Server isn't "owned" by a team the way a Project is; it's shared infrastructure Admin manages, which Phase 4-F already modeled correctly.

**Best insertion point:** Laravel's native Policy system. `Gate::authorize('view', $project)` (no dot in the ability name) never touches the Phase 4-F `Gate::before()` hook, so it composes cleanly alongside the existing role-only abilities without any risk of the two systems fighting each other.

## 3. Architecture Decision

**Hybrid (Option C)** — a `project_user` membership table (Option A's simplicity), *not* a generic polymorphic `resource_access` table (Option B, rejected as premature: this phase names only Project-level scoping as a real need, and Task 6 explicitly says to build Environment-level scope "فقط در صورت نیاز واقعی" — only if actually needed. A generic polymorphic table is exactly the kind of infrastructure that's easy to build wrong before a second real use case exists to validate its shape against).

The hybrid composes two independent, already-correct pieces rather than inventing a third permission system: Phase 4-F's role/permission matrix answers "can this role do X at all"; the new `project_user` table plus `ProjectPolicy` answers "is this user on this project." Neither piece was weakened to build the other — `ProjectPolicy`'s methods call `$user->hasPermission(...)` (Phase 4-F, unchanged) as their first condition, ANDed with membership as the second.

## 4. Resource Access Model

```
User -> role (Phase 4-F, e.g. "developer")
     -> ProjectMember rows (Phase 4-G, e.g. member of "saas-platform", "customer-site")

Access to Project P = Admin (bypasses everything)
                    OR (User.role grants the permission verb AND User is a ProjectMember of P)
```

`project_role` (optional, per Task 3's own example — "Maintainer") exists as a column on `project_user` but is **not consulted by any Gate or Policy this phase** — informational only, exactly like `deployment_targets.agent_id` was in Phase 4-C.5, for the same reason: no concrete rule was specified for what a project-local role should additionally restrict, so nothing was invented for it.

## 5. Project Membership

`ProjectMember` (table `project_user`, additive migration): `project_id`, `user_id`, `project_role` (nullable), unique on `(project_id, user_id)`. `Project::members()`/`isMember(User $user)` and `User::projectMemberships()` relations. Managed via a new, Admin-only `ProjectMemberController` (`projects.manage-members` permission — not granted to any non-admin role in the Phase 4-F matrix) with a small add/remove UI section on the Project detail page, visible only to Admins.

## 6. Policy Design

```php
class ProjectPolicy
{
    public function before(User $user): ?bool { return $user->isAdmin() ? true : null; }
    public function view(User $user, Project $project): bool
        { return $user->hasPermission('projects.view') && $project->isMember($user); }
    public function update(User $user, Project $project): bool
        { return $user->hasPermission('projects.update') && $project->isMember($user); }
    public function deploy(User $user, Project $project): bool
        { return $user->hasPermission('deployments.create') && $project->isMember($user); }
    public function rollback(User $user, Project $project): bool
        { return $user->hasPermission('deployments.rollback') && $project->isMember($user); }
}
```

Registered via `Gate::policy(Project::class, ProjectPolicy::class)` in `AppServiceProvider::boot()`, alongside (not replacing) the Phase 4-F `Gate::before()` hook — the two coexist because Policy ability names (`view`, `update`, `deploy`, `rollback`) never contain a dot, so the hook's `str_contains($ability, '.')` guard always lets them fall through untouched.

## 7. Deployment Authorization

`Deployment`/`DeploymentTask` have no membership of their own — every check resolves through `$deployment->project` / `$deploymentTask->deployment->project`. `DeploymentController::show/approve/cancel/rollback` and `DeploymentTaskController::show/rollback` each gained one additional `Gate::authorize('view'|'deploy'|'rollback', $project)` call alongside their existing Phase 4-F role check. A Developer without membership on a Project now gets a 403 on that Project's deployments — view, deploy, and rollback all fail closed, exactly Task 5's requirement, verified in §12.

## 8. Environment Scope

**Evaluated, not built.** Task 6 explicitly frames per-environment scoping (e.g., staging-only access) as conditional — "فقط در صورت نیاز واقعی." Project-level scope already satisfies every concrete example in the brief (Developer A sees Project A and B, not C); nothing in this phase's actual requirements needs a Developer scoped to *some but not all* environments within one Project they already have access to. Building that now would mean a second membership table with no validated use case to shape it against — deferred to a future phase if that need becomes real (named explicitly in §14).

## 9. Server Security Review

Re-audited, not re-built: `ServerController::rotateToken` (`servers.rotate-credentials`, Admin-only), Agent token/secret fields (never selected or rendered regardless of role, confirmed unchanged since Phase 4-D/4-E), and the Server detail page's credential sections — all already correctly role-gated by Phase 4-F, with no Project-membership concept applicable (a Server is shared infrastructure, not a per-team resource, matching Task 7's own framing: "Operator: Deploy دارد. اما: Server Management ندارد" — a role-level distinction, which already existed).

## 10. UI Integration

- **Project list** (`ProjectController::index`): non-admins now only see Projects they're a member of — Admin still sees all (`whereHas('members', ...)` scoping added, activated only for `!$user->isAdmin()`).
- **Project detail:** the Members section (add/remove) is wrapped in `@can('projects.manage-members')` — Admin-only, matching Task 8/§5.
- **Manual deploy button** (`projects/show.blade.php`): `@can('deploy', $project)` — was role-only (`@can('deployments.create')`) before this phase.
- **Deployment approve/cancel/rollback buttons** (`deployments/show.blade.php`) and **target rollback button** (`deployment-tasks/show.blade.php`): all switched from role-only `@can('deployments.*')` to the Policy-based `@can('deploy'|'rollback', $project)`, which already re-checks the Phase 4-F permission internally — one check now covers both dimensions.
- Every one of these remains a presentation convenience on top of an independent Controller-level `Gate::authorize()` call — Task 8's explicit rule ("UI جای Authorization نیست") holds exactly as it did in Phase 4-F.

## 11. Audit Integration

New: `project_member.added`, `project_member.removed` (`ProjectMemberController`, actor + target user + project). Already covered by Phase 4-F and untouched by this phase: `user.role_changed` ("چه کسی Permission تغییر داد"), every deployment-related action ("چه کسی Deploy کرد" — `deployment.approved`, etc., going back to Phase 1.5/3-C.2).

## 12. Files Changed

**New:** `app/Models/ProjectMember.php`; `app/Policies/ProjectPolicy.php`; `app/Http/Controllers/Dashboard/ProjectMemberController.php`; `database/factories/ProjectMemberFactory.php`; `database/migrations/2026_07_29_090000_create_project_user_table.php`; `tests/Feature/Dashboard/ProjectResourceAuthorizationTest.php`.

**Modified:** `app/Models/Project.php` (`members()`, `isMember()`); `app/Models/User.php` (`projectMemberships()`); `app/Providers/AppServiceProvider.php` (`Gate::policy()` registration); `app/Http/Controllers/Dashboard/ProjectController.php` (membership-scoped `index()`, resource-level `Gate::authorize()` calls, member list eager-load); `app/Http/Controllers/Dashboard/DeploymentController.php`, `DeploymentTaskController.php` (resource-level checks added); `routes/web.php` (`projects.members.*`); `resources/views/projects/show.blade.php` (Members section), `deployments/show.blade.php`, `deployment-tasks/show.blade.php` (Policy-based `@can`); `lang/en/ui.php`, `lang/fa/ui.php`, `lang/en/messages.php`, `lang/fa/messages.php` (new key groups).

**One real compatibility tension, resolved and disclosed rather than hidden:** `tests/Feature/Dashboard/RolePermissionTest.php`'s `test_operator_can_deploy_and_rollback`, `test_developer_can_trigger_deploy_but_not_rollback`, and `test_ui_hides_deploy_button_for_developer_but_shows_rollback_for_operator` were written under Phase 4-F's role-only model, where "operator has `deployments.create`" was sufficient to deploy on *any* Project. Task 4 of this phase explicitly redefines Operator/Developer access as project-scoped — meaning those three tests' *setup* (no membership row) tested exactly the behavior this phase was asked to change. Each was fixed by adding the now-realistic `ProjectMember` row their scenario requires — no assertion was weakened or removed; every test still verifies the identical positive/negative outcome it always did, just with a real project membership standing in for what used to be implicit "any project."

**Untouched:** every Phase 4-F role/permission file beyond the `AppServiceProvider` registration above (`PermissionMatrix`, `UserController`, the `Gate::before()` hook itself); `ServerController`, `AgentController`, `GitHubAccountController` (audited, found already correct — §9); `DeploymentDispatcher`, `RollbackService`, `DeploymentTargetResolver`; the entire agent-facing API.

## 13. Tests Result

```
Full suite: 210/210 passed, 547 assertions (199 pre-existing [3 setups adjusted, see §12] + 11 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/ProjectResourceAuthorizationTest.php`):

1. Admin sees and fully manages a legacy Project with zero membership rows (the core compatibility guarantee).
2. A Developer **with** membership can view and deploy their Project.
3. A Developer **without** membership cannot view or deploy a *different* Project (403, not silently redirected).
4. The Project list only shows Projects the user is a member of (Admin sees all; a non-admin's non-member Project never even appears).
5. An Operator can roll back on a Project they're a member of, and is denied on one they aren't.
6. `DeploymentTaskController`'s rollback/show also inherit their parent Project's scope correctly.
7. **Direct URL access to an unauthorized Project's deployment is blocked** — Task 10's explicit security requirement, regardless of any UI link.
8. Admin can add and remove a Project member; both actions are audit-logged.
9. A non-admin (Operator) cannot manage Project membership, even for a Project they're a member of.
10. The UI shows the Members section to Admin and hides it from a non-admin viewing the same Project.
11. The exact pattern every pre-4-G test already used (`User::factory()->create()`, i.e. Admin) is completely unaffected by membership.

## 14. Remaining Work

- Environment/DeploymentTarget-level scope (Task 6) remains deliberately unbuilt — no concrete requirement justified it yet; flagged as the natural next increment if a real "Developer sees staging but not production" need arises.
- `project_role` (Maintainer/etc.) is stored but not enforced anywhere — a future phase could use it to distinguish, e.g., a project-local "can manage members of just this project" tier without going through global Admin.
- No bulk membership management (add multiple users at once, or copy membership from one Project to another) — the UI is one-at-a-time, matching this phase's "foundation" framing.
- The Members section has no search across a large user base — same caveat every list-page phase before this one has already flagged at current scale.

## 15. Next Phase Recommendation

With per-project scoping now real, the two most consequential open items across the whole Phase 4 series are: the GitHub installation-webhook listener (Phase 4-E), and — now genuinely closer to relevant given real team-style access exists — the Environment-level scope this phase deliberately deferred, should a concrete staging-vs-production access need actually surface.
