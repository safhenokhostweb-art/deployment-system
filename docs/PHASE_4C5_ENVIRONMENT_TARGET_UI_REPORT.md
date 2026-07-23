# Phase 4-C.5 — Environment & DeploymentTarget Management UI Report

## 1. Executive Summary

The Deployment Domain built in Phase 3-C (Project → Environment → DeploymentTarget → Server → Agent) had a full backend and zero UI — a project could only ever deploy through the legacy single-server path because nothing could create the rows the new domain needs. This phase adds that UI directly on the Project detail page: create environments, attach servers as deployment targets (one or several at once), enable/disable either, and delete a target when it's safe to.

**A real, consequential bug was found during the audit and fixed with your explicit sign-off before any UI was built**, per the brief's own "report before executing" rule (generalized here from migrations to the one Service change this phase required): `DeploymentTargetResolver::resolveTargetsFor()` picked a project's environment via `->first()` with no status filter. Harmless while `environments` held zero real rows (true since Phase 3-C shipped); actively dangerous the moment this phase's UI lets a project have more than one Environment — it could silently dispatch to a disabled one. Fixed as a two-line, backward-compatible change confirmed safe against the existing `DeploymentTargetResolverTest` suite before writing any new code.

149/149 tests pass (134 from before this phase, unmodified, + 15 new). No migration was run — the audit found the database already enforces "deleting a target must not break deployment history" via a `restrictOnDelete()` foreign key from `deployment_tasks`, discovered before writing the delete action, so the Controller only needed to turn that constraint into a friendly, translated message instead of duplicating the safety at the application layer.

## 2. Pre-Implementation Audit

**Models:** `Environment` (`project_id`, `name`, `key`, `status: active|disabled`, `locked_by_deployment_id`, `locked_at`) and `DeploymentTarget` (`environment_id`, `server_id`, `agent_id` nullable, `name`, `is_active`) already existed in full from Phase 3-C, with every relationship this phase needed (`Project::environments()`, `Environment::deploymentTargets()`, `DeploymentTarget::server()`/`agent()`/`deploymentTasks()`).

**Controllers:** No `EnvironmentController` existed. `ProjectController` had full CRUD for Project itself but nothing touching Environment/DeploymentTarget. `ServerController` was unaffected and needed no changes — servers are only *selected* by this phase's new UI, never created or edited by it.

**Routes:** Confirmed no `environments.*` or `deployment-targets.*` routes existed anywhere in `routes/web.php`.

**Views:** `projects/show.blade.php` (Phase 4-B) had project info, webhook setup, pipeline, manual deploy, and deployment history — no Environment/Target section at all, matching the audit's expectation exactly.

**Schema facts that shaped every design decision below** (from the Phase 3-C migration, re-read line by line before writing any Controller code):
- `deployment_targets.environment_id` and `.server_id` are `restrictOnDelete()`.
- `deployment_tasks.deployment_target_id` is **also** `restrictOnDelete()`. This is the database already guaranteeing Task 3's "deleting a target must not break deployment history" rule — a naive hard-delete on a target with history would throw a raw `QueryException`, not silently corrupt anything. No migration was needed to add this safety; it already existed.
- `environments.key` is unique **per project** (`unique(['project_id', 'key'])`), not globally.
- `deployment_targets.name` is unique **per environment** (`unique(['environment_id', 'name'])`), not globally.
- `AgentTaskService::claimNextTask()` matches candidate tasks by `deploymentTarget.server_id === $agent->server_id` — it does **not** read `deployment_targets.agent_id` at all. That column is schema-present but not consulted by the execution path. This directly shaped the Server Selection design (§4): the meaningful choice when creating a target is the **server**, not a specific agent — pretending otherwise would be misleading UX.
- **The bug**: `DeploymentTargetResolver::resolveTargetsFor()`'s `$project->environments()->first()` had no `status` filter — see §1 and the fix in §3.

**Conclusion:** yes, real Read/Write Controllers were needed (this phase's own rules explicitly allow it "when strictly needed to supply the UI, after audit"); no new Model, no new column, no new migration.

## 3. The Resolver Fix (reported before implementation, per your approval)

```php
// Before (Phase 3-C):
$environment = $project->environments()->first();

// After (Phase 4-C.5):
if ($project->environments()->doesntExist()) {
    return [$this->synthesizeLegacyTarget($project)];   // unchanged legacy branch
}
$environment = $project->environments()->where('status', Environment::STATUS_ACTIVE)->first();
if ($environment === null) {
    return [];   // has environments, none active — same "no fallback" principle
                 // DeploymentTargetResolverTest already established for zero targets
}
```

Two properties were preserved deliberately: (1) the legacy branch (project has *zero* Environment rows) is completely untouched — still checked first, still returns the synthesized single-server target; (2) a project with Environment rows but none active resolves to an **empty** target list, not a legacy fallback — this mirrors the exact principle `DeploymentTargetResolverTest::test_project_with_environment_but_zero_active_targets_resolves_to_empty_not_a_fallback` already encoded for the target-count case, extended here to the environment-status case. An empty target list is what already triggers `DeploymentDispatcher`'s existing, Phase-4-B-translated "Environment configured but no active deployment targets" error — no new error path was needed.

Verified against the full pre-existing `DeploymentTargetResolverTest` suite (all four tests use the factory's default `status: active`, so behavior is identical for every case that existed before this phase) before writing any Controller code, and two new regression tests were added (§9) for the specific multi-environment scenario this fix exists for.

## 4. Environment UI

`EnvironmentController::store()` — name + key (validated `^[a-z0-9-]+$`, unique per project via the same composite-uniqueness pattern the schema itself uses), always created `active`. `toggleStatus()` flips `active`⇄`disabled`. No delete action — the brief only asked for create + disable for Environment, and disabling already achieves "stop using this without losing history" (an Environment's `deploymentTargets` are untouched by disabling it; `restrictOnDelete()` would block deleting one with targets anyway).

Rendered on the Project detail page as one block per environment: name, status badge, target count, an enable/disable button, its targets nested directly beneath (§5), and a collapsible ("`<details>`", no new JS) "Create new environment" form at the end of the list.

## 5. DeploymentTarget UI

`DeploymentTargetController::store()` takes an array of `server_ids` and creates one `DeploymentTarget` per server in a single request — matching the brief's own checkbox-list example (`☑ Production VPS`, `☑ Test VPS`) more directly than a one-at-a-time form would. Each target is named after its server (de-duplicated with a `(2)`, `(3)`... suffix on collision within the same environment) rather than asking for a separate name field, keeping the form to exactly what the brief's example shows.

`toggleStatus()` flips `is_active`. Per the audit (§2), `DeploymentTargetResolver` already filters `->where('is_active', true)` — Task 3's "an inactive target must never be used for a deploy" rule needed no new code, only a UI for a column that already existed and was already respected.

`destroy()` — `abort_if($deploymentTarget->deploymentTasks()->exists(), 409, ...)` before deleting. This mirrors, rather than duplicates, the database's own `restrictOnDelete()` guarantee (§2): a target with deployment history gets a friendly Persian "cannot be deleted, disable instead" message and a 409; a target with none is really deleted. `test_deleting_a_target_with_deployment_history_is_blocked_and_preserves_history` confirms the row survives.

## 6. Server Selection

The "add target" form lists every `Server::where('is_active', true)` — the exact same filter `ProjectController::create()` already uses for its own server picker, so the selection criteria are consistent across the whole app. Per target's audited claim behavior (§2), the list shows each server's connected Agent (if any) and its online/offline badge purely as **information**, not as a routing choice — a target's `agent_id` is set automatically only when the chosen server has exactly one approved Agent (informational convenience for §7's Agent Visibility ask), and is left null otherwise rather than guessing. Only `id`/`name`/`last_seen_at` are ever selected from `servers`, and only `id`/`server_id`/`name`/`public_id`/`last_seen_at`/`status` from `agents` (§8) — no credential column is ever part of the query, not just hidden after the fact.

## 7. Agent Visibility

Wherever a target shows its Agent (Target row, "add target" server list), only `name`, `status`/online-offline, and (on the target row) nothing beyond that is rendered — version/heartbeat were part of Phase 4-C's existing Agent Status widget already and weren't duplicated here. `agent_token_hash`, `signing_secret`, and `registration_token` are never selected, never eager-loaded, and never referenced in any new Blade file — verified both by explicit column-restricted queries (§6) and by a new test (§9) that asserts a known `signing_secret` value never appears on the rendered Project detail page.

## 8. Project Detail Changes

`ProjectController::show()` now eager-loads `environments.deploymentTargets.server` and `.agent` (column-restricted per §6/§7) and passes the active-servers list for the target picker. The page structure now matches Task 6's requested order exactly: project info → **Environments** (new) → **Deployment Targets** (nested inside each Environment, new) → GitHub webhook setup → pipeline → manual deploy → **Deployment History** (unchanged, existing). Nothing about the webhook/pipeline/manual-deploy/history cards was altered — only inserted before them.

## 9. Permission Review

No role/permission system exists yet (Phase 4-A's audit already flagged this as a real backend gap, not something this phase's rules asked it to build). Per Task 7 ("at minimum, create a structural guard extensible in Phase 4-G"), a single named `Gate::define('manage-deployment-topology', ...)` was added in `AppServiceProvider::boot()` and is checked via `Gate::authorize(...)` at the top of every mutating action in both new Controllers (`store`/`toggleStatus`/`destroy`). Today it always returns `true` for any authenticated user — identical behavior to every other route behind the existing `auth` middleware — but it is now a single, named choke point; a future Phase 4-G role check needs to change one closure, not any Controller. `test_environment_and_target_mutations_require_authentication` confirms the baseline (unauthenticated requests still redirect to login) didn't regress.

## 10. Empty States

Both exact strings from Task 8 are implemented verbatim and covered by tests:
- Project with no Environment: **"هنوز محیطی برای این پروژه تعریف نشده است."**
- Environment with no Target: **"هیچ مقصدی برای استقرار انتخاب نشده است."**

Both render via the Phase 4-B `<x-empty-state>` component, consistent with every other empty state in the app.

## 11. Security Review

- No secret/token/credential column is queried anywhere in this phase's new code (§6/§7), not merely hidden at serialization time — verified by explicit `select()` column lists on every new eager-load.
- `test_project_detail_page_never_exposes_agent_or_server_credentials` asserts a known `Server.signing_secret` and `Agent.signing_secret` value never appear in the rendered page.
- No existing Agent is invalidated, re-issued, or has its credentials touched by anything in this phase — `EnvironmentController`/`DeploymentTargetController` never call `AgentCredentialService` or write to any `*_token*`/`*_secret*` column.
- `Gate::authorize()` calls exist on every mutation (§9) — currently a no-op relative to today's baseline, but present and testable.
- Deleting a target the database would otherwise reject now fails cleanly with a translated 409 rather than an unhandled 500/`QueryException`.

## 12. Files Changed

**New:** `app/Http/Controllers/Dashboard/EnvironmentController.php`; `app/Http/Controllers/Dashboard/DeploymentTargetController.php`; `tests/Feature/Dashboard/EnvironmentTargetManagementTest.php`.

**Modified:** `app/Services/Deployment/DeploymentTargetResolver.php` (the §3 fix — the one Business Logic change this phase made, reported and approved before implementation); `app/Providers/AppServiceProvider.php` (Gate definition only); `app/Http/Controllers/Dashboard/ProjectController.php` (`show()` — new eager-loads and `$availableServers`, no existing behavior changed); `routes/web.php` (new route definitions only); `resources/views/projects/show.blade.php` (new Environments/Targets section inserted, nothing removed); `resources/views/layouts/app.blade.php` (new CSS rules only); `lang/en/ui.php`, `lang/fa/ui.php`, `lang/en/messages.php`, `lang/fa/messages.php` (new key groups only).

**Untouched:** every other Model, every Migration, `ServerController`, `AgentController`, `DeploymentController`, `DeploymentTaskController`, and the entire legacy single-server deploy path.

## 13. Tests Result

```
Full suite: 149/149 passed, 394 assertions (134 pre-existing + 15 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/EnvironmentTargetManagementTest.php`):

1. Create an environment for a project.
2. Environment key must be unique within the same project.
3. The same key is allowed across two different projects (composite uniqueness, not global).
4. Toggle an environment disabled and back to active.
5. Attach multiple servers as deployment targets in one request (the checkbox-list flow).
6. Duplicate target names within one environment get disambiguated (`name (2)`).
7. Disable a deployment target.
8. A disabled target is excluded from `DeploymentTargetResolver` (Task 3's rule, already enforced by existing code — confirmed, not re-implemented).
9. Deleting a target with no deployment history succeeds.
10. Deleting a target **with** deployment history is blocked (409) and the row survives.
11. **The resolver-fix regression test**: with two environments (one disabled, one active) on the same project, only the active one's targets are ever resolved.
12. A project with only a disabled environment resolves to zero targets — not a legacy fallback.
13. A legacy project with zero Environment rows still resolves and its detail page still renders (with the correct empty state).
14. The project detail page never exposes a known `Server`/`Agent` signing secret.
15. Every mutating route redirects unauthenticated requests to login (Task 7's baseline).

## 14. Remaining Work

- `deployment_targets.agent_id` is populated best-effort (only when a server has exactly one approved Agent) and is purely informational — `AgentTaskService` doesn't read it. If a future phase wants to actually pin execution to a specific Agent rather than "any approved Agent on this server," that's a real Service-layer change this phase deliberately didn't make.
- No Environment edit (rename/re-key) was built — only create + status toggle, matching exactly what Task 2 asked for. Renaming would need to decide whether `key` changes are safe post-creation (agent-facing implications, similar to why `project_key` is locked after creation) — worth its own audit, not assumed here.
- The Gate defined in §9 is a real seam but is not yet backed by any actual role data — Phase 4-A's "Gap: no role/permission system" finding still stands exactly as it did before this phase.
- The "add target" server checklist has no search/filter — fine at current scale, would need one once server counts grow substantially (same caveat Phase 4-C's Agent Status widget already flagged).

## 15. Phase 4-D Recommendation

With Environment/DeploymentTarget now fully manageable end-to-end (create, attach servers, enable/disable, safe delete) and the resolver's multi-environment bug fixed, the Deployment Domain is for the first time actually usable in production without hand-editing the database. The two standing Phase 4-A Gaps are narrower now: GitHub OAuth/App integration is unchanged and still open; Environment/DeploymentTarget CRUD UI (previously the other named Gap) is now closed by this phase. A natural Phase 4-D would be the role/permission system this phase's Gate seam and Phase 4-A both flagged — it's now blocking real multi-operator use of the feature this phase just shipped, not just a theoretical nice-to-have.
