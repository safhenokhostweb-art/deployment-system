# Phase 4-C — Persian Dashboard Redesign Implementation Report

## 1. Executive Summary

The Dashboard went from two tables (server list, 25 most recent deployments) to a real operations-control-center: four summary-stat cards, a system-health panel (problem agents, problem deployments, recent errors), quick actions, and three data widgets (recent deployments, agent status, server overview) — all in Persian, all RTL, all built from Phase 4-B's shared components.

Per the brief's relaxed rule for this phase ("no Model/Controller/Service change *unless strictly needed to supply dashboard read data, after audit*"), two files were touched beyond the presentation layer: `DashboardController` (now delegates to a new service instead of running two ad-hoc queries inline) and a brand-new `App\Services\Dashboard\DashboardService`. Both are pure reads — grep confirms zero `->create(`, `->update(`, `->delete(`, or `->save(` calls anywhere in the new service. No Model, Migration, Route, or any other Controller/Service was touched.

134/134 tests pass (124 from before this phase, unmodified, + 10 new), including a query-count guard that proves the new widgets don't scale with row count (no N+1), and two tests that specifically exercise legacy-only and new-domain deployment data through the same widgets to confirm neither path broke the other.

## 2. Pre-Implementation Audit

**`DashboardController` (before):** two queries — `Deployment::with(['project','server'])->latest('id')->take(25)->get()` and `Server::withCount('projects')->get()` — passed straight to the view via `compact()`. No stats, no health signal, no Agent data at all.

**`dashboard/index.blade.php` (before):** two `<x-card>` tables (servers, recent deployments) — Phase 4-B had translated and RTL'd these, but the *information architecture* was still whatever the very first MVP shipped.

**Models reviewed** (relationships and computed state actually available, no schema changes needed for any of it):
- `Server`: `isOnline()` (computed from `last_seen_at` vs `config('deployment.server_offline_threshold_seconds')`, default 90s), `agents()`, `projects()`, `deployments()`.
- `Agent`: `isOnline()` (same computed pattern), `STATUS_PENDING_APPROVAL`/`APPROVED`/`DISABLED`, `server()`.
- `Project`: `is_active`, `environment` (plain string — the legacy single-environment-per-project field), `environments()` (new domain, usually empty).
- `Deployment`: `STATUS_*` (including `partial_success`), `last_activity_at`, `started_at`, `finished_at`, `failure_message`, `tasks()` (empty for every legacy deployment).
- `DeploymentTask` / `AgentJob`: new-domain execution rows, each with their own `STATUS_*` and `started_at`/`last_activity_at` — critically, **separate from** the parent `Deployment`'s own columns of the same name (see §6 for why this mattered).
- `AuditLogEntry`: append-only, `actor_type`/`action`/`subject_type`/`metadata` — considered for the "recent errors" widget but not used directly (see §6).

**Existing read-only precedent found:** `App\Console\Commands\DetectStalledDeployments` already contains the exact "what counts as stalled" query logic (legacy: `Deployment.status=running` + stale `last_activity_at`; new domain: `AgentJob.status=running` + stale `last_activity_at`, checked separately since a multi-target `Deployment`'s own `last_activity_at` isn't touched by task-level activity). The health-overview widget in this phase reuses that exact query shape, read-only, never calling the command or mutating anything.

**Conclusion reached before writing code:** yes, a dedicated `DashboardService` was worth building — the moment "summary cards" + "health overview" + "three widgets" was on the table, keeping that logic inline in the Controller would have made it untestable in isolation and unreadable. No new migration or column was needed for anything requested — one gap was found (server "type", see §8) and resolved by deriving it from existing relationships rather than adding a column.

## 3. Dashboard Architecture

```
DashboardController::index()
    └── DashboardService (new, read-only)
            ├── summaryCards()        → 4 grouped-count queries
            ├── healthOverview()      → problem agents / problem deployments / recent errors
            ├── recentDeployments()   → latest 10, eager-loaded
            ├── agentStatusWidget()   → latest-active 20, eager-loaded
            └── serverOverviewWidget()→ all servers + connected-agent, eager-loaded
    └── dashboard/index.blade.php
            ├── Summary Cards (x-card × 4)
            ├── Quick Actions (x-card, links to existing routes only)
            ├── System Health (x-card, x-alert for the all-clear state)
            ├── Recent Deployments (x-card, x-badge, x-empty-state)
            ├── Agent Status (x-card, x-badge, x-empty-state)
            └── Server Overview (x-card, x-badge, x-empty-state)
```

Every list is capped (10–20 rows) — this is a dashboard, not a report generator; deep history already lives on the existing per-project/per-server/per-deployment pages this phase didn't touch.

## 4. Data Sources

| Widget | Source | Notes |
|---|---|---|
| Summary Cards | `Server`, `Agent`, `Project`, `Deployment` | One grouped `COUNT ... GROUP BY status` query each for Agent/Deployment; `Server`/`Project` counts are cheap enough not to need grouping |
| System Health — problem agents | `Agent` | Pending approval + approved-but-`!isOnline()` |
| System Health — problem deployments | `Deployment`, `DeploymentTask`, `AgentJob` | Failed / stalled / long-running, legacy and new-domain both checked (see §6) |
| System Health — recent errors | `Deployment` | Latest 10 `status=failed`, both domains (status lives on the parent `Deployment` regardless of legacy/new-domain) |
| Recent Deployments | `Deployment` + `tasks.deploymentTarget.environment` | Falls back to `Project.environment` (legacy string field) when no `Environment` row exists |
| Agent Status | `Agent` + `server` | All agents, ordered by most-recently-active first |
| Server Overview | `Server` + `agents` | "Type" is derived, not stored — see §8 |

No `AuditLogEntry` query ended up in the final widgets — every signal Task 3 asked for (offline, stalled, failed) already has a more direct, cheaper source on `Agent`/`Deployment` themselves. Flagged in §15 as something a future phase could still add (a literal "recent audit activity" feed is a different, legitimate thing from "recent errors").

## 5. Summary Cards

Four cards (سرورها / کارگزارها / پروژه‌ها / استقرارها), each a single `<x-card>` with `stat-row` lines:

- **سرورها**: کل / آنلاین / آفلاین — `Server::isOnline()`, no new query beyond `Server::all(['id','last_seen_at'])`.
- **کارگزارها**: کل / فعال / در انتظار تایید / غیرفعال — one `GROUP BY status` query.
- **پروژه‌ها**: کل / فعال — two small `COUNT` queries (`Project::count()`, `Project::where('is_active', true)->count()`).
- **استقرارها**: کل / موفق / ناموفق / در حال اجرا — one `GROUP BY status` query.

## 6. Health Overview

**Problem agents:** pending-approval agents + approved-but-offline agents. "Offline" and "stale heartbeat" were specified as two separate bullet points in the brief but collapsed into one signal here: `Agent::isOnline()` *is* the heartbeat-staleness check (same `server_offline_threshold_seconds` config, same computed-not-stored pattern as `Server::isOnline()`) — inventing a second, different staleness threshold not backed by any existing config would have been a new business rule, which this phase's rules forbid.

**Problem deployments — failed / stalled / long-running:** this is the one place the audit's finding about `DeploymentTask`/`AgentJob` having their *own* `started_at`/`last_activity_at`, separate from the parent `Deployment`, actually mattered. A naive `Deployment::where('last_activity_at', ...)` check would silently miss every stalled new-domain rollout, because multi-target deployments don't touch their own `last_activity_at` — only the child `AgentJob` rows do. Both `stalledDeployments()` and `longRunningDeployments()` therefore run two queries each (legacy: check `Deployment` directly, filtered to `whereDoesntHave('tasks')`; new domain: check `AgentJob.status`/`last_activity_at` through `whereHas('tasks.agentJobs', ...)`), unioned and de-duplicated by id. This exact split mirrors `DetectStalledDeployments`. "Long-running" uses a shorter, hardcoded 10-minute early-warning threshold (half of the default 30-minute stalled timeout) — a presentation cutoff, not a new config value, so it isn't exposed in `config/deployment.php`.

**Recent errors:** latest 10 `Deployment`s with `status=failed`, across both domains (the parent's `status` is authoritative either way, thanks to `DeploymentStatusAggregator` already keeping it in sync for multi-target rollouts).

**All-clear state:** when all three lists are empty, the whole card collapses to one `<x-alert type="success">` — this is the empty-state Task 11 asked to test.

## 7. Deployment Widgets

Recent Deployments (10 rows, id-descending) shows پروژه / محیط / مقصد / وضعیت / زمان / نتیجه. مقصد ("target") and محیط ("environment") both branch on whether the deployment has `DeploymentTask` rows:
- **Legacy** (no tasks): مقصد = the `Server` name; محیط = `Project.environment` (the plain string field).
- **New domain** (has tasks): مقصد = the target name, or `":count مقصد"` if more than one; محیط = the `Environment` name via `tasks.first().deploymentTarget.environment`.

نتیجه ("result") shows a truncated `failure_message` for failed deployments, an em-dash otherwise — status is already shown as a badge in its own column, so this column carries information the badge alone doesn't (Statuses throughout use `<x-badge>`, i.e. the Phase 4-B translation layer, exactly as the brief required).

## 8. Agent and Server Widgets

**Agent Status** (20 rows, most-recently-active first): name, server, status (approved agents further split into online/offline badges; pending/disabled agents show their own status badge), version, last heartbeat.

**Server Overview:** name, type, connected agent, connection status. "Type" needed a decision — the `servers` table (confirmed via its migration) has no type/kind column at all, and adding one would be a schema change this phase forbids. It's derived read-only: a server with at least one `Agent` row is "دارای کارگزار" (agent-based); one with none is "قدیمی (بدون کارگزار)" (legacy) — this is exactly the Phase 3-B "Old Agent vs New Agent" coexistence distinction the rest of the app already treats as meaningful, just surfaced here for the first time.

## 9. Dashboard Service Layer

`App\Services\Dashboard\DashboardService` — every public method returns a query result or a plain array/Collection; no method takes an ID and no method mutates anything (verified by grep, see §1). It was deliberately kept as one flat class rather than split into a "read model" + "repository" pair — the brief was explicit that no new business logic should appear, and a thin, directly-testable query class is the smallest thing that satisfies "keep queries out of the Controller" without inventing new abstractions the codebase doesn't otherwise use.

## 10. Performance Review

Query shape per dashboard load, regardless of table size:
- 4 summary-card groups: ~6 queries (2 grouped `COUNT`s, 2 plain `COUNT`s, 1 `Server` scan for online/offline, folded into the same request)
- Health overview: ~8 queries (pending agents, offline agents, failed/stalled-legacy/stalled-new-domain/long-running-legacy/long-running-new-domain deployments, recent errors) — each capped and each a single query, no per-row queries
- Recent deployments / agent widget / server widget: 1 query each, eager-loading their relations (`project`, `server`, `tasks.deploymentTarget.environment`, `agents`) so Blade never triggers a lazy-load inside the `@foreach`

`test_dashboard_query_count_stays_bounded_regardless_of_row_volume` seeds 15 servers, 15 approved agents, and 15 projects-with-deployments, then asserts the whole page executes under 40 queries — proving the widget count is flat, not proportional to row count. (Laravel's `preventLazyLoading()` was considered as a stricter global guard but rejected — turning it on affects every page, not just the dashboard, which is outside this phase's scope; the query-count assertion gives the same practical guarantee for this page specifically.)

## 11. Responsive Design

The summary cards use `grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))`, so they naturally reflow from 4-across (desktop) to 2-across (tablet) to 1-across (mobile) with no extra breakpoints needed. Quick actions use `flex-wrap: wrap`. Every table inherited Phase 4-B's existing responsive/RTL foundation (`.app-shell` column-collapse under 820px, logical CSS properties, `<x-ltr>` for codes/hashes) — nothing about that foundation was touched, only extended with the new `.summary-grid`/`.stat-row`/`.quick-actions` rules.

## 12. Security Review

No secret, token, or credential field is queried or rendered by any new widget — `DashboardService` never selects `agent_token_hash`, `signing_secret`, `registration_token`, or `github_webhook_secret` (all already `$hidden`/encrypted at the Model level from earlier phases; this phase didn't touch that). `test_dashboard_never_exposes_secrets_or_credentials` asserts a known webhook secret and signing secret never appear in the rendered page. No new route, no new write path, no change to authentication/authorization — the dashboard route is still behind the same `auth` middleware group it always was.

## 13. Files Changed

**Modified:** `app/Http/Controllers/Dashboard/DashboardController.php`; `resources/views/dashboard/index.blade.php`; `resources/views/layouts/app.blade.php` (new CSS rules only: `.summary-grid`, `.stat-row`, `.stat-good/-bad/-warn/-info`, `.quick-actions`); `lang/en/ui.php` and `lang/fa/ui.php` (extended `dashboard` key group).

**New:** `app/Services/Dashboard/DashboardService.php`; `tests/Feature/Dashboard/DashboardWidgetsTest.php`.

**Untouched by this phase:** every Model, every Migration, every Route, every other Controller/Service, and every Phase 4-B file not listed above (components, other views, the locale middleware, the message translator).

## 14. Tests Result

```
Full suite: 134/134 passed, 347 assertions (124 pre-existing + 10 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/DashboardWidgetsTest.php`):

1. Summary cards report exact counts across servers/agents/projects/deployments.
2. Health overview flags a pending-approval agent and an offline approved agent, with the correct `reason` each.
3. Health overview flags a stalled **legacy** deployment (stale `Deployment.last_activity_at`).
4. Health overview flags a stalled **new-domain** task (stale `AgentJob.last_activity_at`, parent `Deployment.last_activity_at` untouched) — the specific case §6 explains.
5. Server overview widget correctly labels a legacy server vs. an agent-based server.
6. Dashboard renders every widget's empty state correctly with zero data.
7. Dashboard renders a legacy deployment and a new-domain deployment (with a named target) side by side without error.
8. Dashboard never exposes a known webhook secret or signing secret.
9. Quick action links point at real, existing route URLs (and the same-page anchor for "view deployments").
10. Query count stays under a fixed ceiling with 15 rows seeded per widget — the N+1 guard.

## 15. Remaining Work

- A literal "recent audit log activity" feed (distinct from "recent errors") was considered and dropped for this phase — `AuditLogEntry` already has everything needed (`action`, `subject_type`, `metadata`, `created_at`); it just wasn't necessary once `Deployment`/`Agent` state alone covered every signal Task 3 asked for.
- The "long-running" 10-minute threshold is a hardcoded presentation cutoff, not configurable. If operators want to tune it, it belongs in `config/deployment.php` alongside `stalled_deployment_timeout_minutes` — a one-line addition, deliberately not made this phase since it wasn't asked for and would be a config *addition*, however small, worth calling out explicitly.
- The Agent Status widget caps at 20 rows with no pagination or filter UI. Fine at current scale; would need a real index page (new route) once agent counts grow past what fits on one dashboard card.
- No auto-refresh/polling was added — the dashboard is a plain server-rendered page, refreshed on navigation only, consistent with the rest of the app (no JS framework, no new dependency).

## 16. Phase 4-C.5 Recommendation

The two standing Gaps from Phase 4-A (GitHub OAuth/App integration, Environment/DeploymentTarget CRUD UI) are unchanged by this phase. Given this phase surfaced *reading* Environment/DeploymentTarget data cleanly (the Recent Deployments widget already branches correctly on it), a natural Phase 4-C.5 would be exactly the Environment/DeploymentTarget CRUD UI Phase 4-A flagged — the read side proved out here, and the domain/service layer for it already exists from Phase 3-C/3-C.2, leaving only Controller+View work.
