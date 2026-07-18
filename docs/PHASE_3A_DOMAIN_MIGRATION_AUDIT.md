# Phase 3-A — Domain Migration Foundation (Audit + Design Only)

Audit and migration planning only. No migration, model change, or
refactor was written for this report. `saas-platform` was not read or
touched, and no dependency is proposed anywhere below. Grounded in the
actual current codebase (re-read fresh for this document, not recalled
from memory) as of the Phase 2 architecture commit.

## 1. Executive Summary

Phase 2 decided *what* the target domain looks like. This document
answers the harder question: **how does a system with real, already-
deployed `Project`/`Server` rows, live GitHub webhook URLs, and live
agent `config.php` files on production boxes get there without breaking
any of them on the day it ships.**

The single sharpest edge in the whole migration is that today's
`Project.project_key` does two unrelated jobs at once — it's both the
GitHub webhook URL segment *and* the string an already-deployed agent's
local `config.php` uses to find its working directory. Every
recommendation in §5–§6 flows from deliberately **not** breaking either
of those two consumers simultaneously. The core strategy, stated up
front: **reuse existing identifier values as the new tables' keys during
backfill**, so the migration is a *relocation* of data, not a
*regeneration* of it — nothing GitHub already has configured, and nothing
any already-deployed agent already has in its `config.php`, needs to
change for the migration itself to succeed.

## 2. Current Architecture Audit

Re-verified directly against the repository (not the Phase 2 report's
summary) for this document:

**Models** (`app/Models/`): `Server`, `Project`, `Deployment`,
`DeploymentLogEntry`, `AuditLogEntry`, `AgentRequestNonce`, `User`. There
is no `Job` model — a "job" is the in-flight representation of a
`Deployment` row itself (claimed via `AgentJobService`), not a separate
table. There is no `Pipeline` model — a pipeline is a JSON array stored
directly on `Project.pipeline`, validated ad hoc inline in
`ProjectController::validateProject()`, not through any reusable service.
There is no `Agent`-related structure at all yet — everything
agent-identity-shaped lives directly on `Server`.

**Services** (`app/Services/`): `Deployment/DeploymentDispatcher`,
`Deployment/AgentJobService`, `Deployment/RollbackService`,
`Security/RequestSigner`, `Security/JobPayloadSigner`,
`Security/GitHubWebhookVerifier`, `Security/AgentCredentialService`,
`Security/SecretRedactor`. No `PipelineService`, `AgentService`, or
`WebhookService` exists as a named class — webhook handling lives in
`Http/Controllers/Api/GitHubWebhookController` plus the queued
`Jobs/ProcessGitHubWebhookPush`.

**Routes**: `POST /api/webhooks/github/{projectKey}` (unauthenticated by
token — authenticated by per-project HMAC body signature).
`agent.signed`-protected group: `POST /api/agent/heartbeat`,
`GET /api/agent/jobs/next`, `POST /api/agent/jobs/{deployment:public_id}/status`,
`POST /api/agent/jobs/{deployment:public_id}/log`. Dashboard routes
(`web.php`) CRUD `servers`/`projects` and act on `deployments`
(approve/cancel/rollback).

## 3. Current Domain Model

| Table | Key columns | Relationships |
|---|---|---|
| `servers` | `public_id` (uuid, auth identity today), `name`, `description`, `agent_token_hash`, `signing_secret` (encrypted), `is_active`, `last_seen_at`, `agent_version` | `hasMany` Project, `hasMany` Deployment |
| `projects` | `public_id`, `project_key` (**unique, dual-purpose — see below**), `repository`, `branch`, `environment` (plain **string**, e.g. `"production"` — not a relation), `server_id` (FK, single), `pipeline` (json array of `{name, command}`), `auto_deploy`, `github_webhook_secret` (encrypted), `is_active` | `belongsTo` Server, `hasMany` Deployment |
| `deployments` | `public_id`, `project_id` (FK), `server_id` (FK — **denormalized copy** of the project's server_id at creation time, not derived through the relation), `action`, `status` (leaf execution status, not aggregate), `commit_hash`, `previous_commit_hash`, `branch`, `trigger`, `triggered_by`, `approved_by`/`approved_at`, `queued_at`/`started_at`/`finished_at`/`last_activity_at`, `failure_message`, `rollback_of_deployment_id` (self-FK) | `belongsTo` Project, `belongsTo` Server, `belongsTo` Deployment (rollbackOf), `hasMany` DeploymentLogEntry |
| `deployment_log_entries` | `deployment_id` (FK, cascade delete), `sequence` (unique per deployment), `step_name`, `command`, `output` (redacted at write time since Phase 1.5), `exit_code`, `duration_ms`, `timed_out` | `belongsTo` Deployment |
| `audit_log_entries` | `actor_type`, `actor_identifier`, `action`, `subject_type`/`subject_id` (plain strings/ints — polymorphic *by convention*, not a real Eloquent morph), `metadata` (json), `created_at` only | none (append-only by convention — no update/delete path exists anywhere in the codebase) |
| `agent_request_nonces` | `server_id` (FK, cascade), `nonce`, unique `(server_id, nonce)` | the atomic replay-protection primitive itself |

### Hidden dependencies / implicit coupling

1. **`Project.project_key` is dual-purpose**: it is both (a) the webhook
   URL segment (`/api/webhooks/github/{projectKey}`) and (b) the exact
   string an agent's local `config.php` `projects` map uses to resolve a
   working directory (sent back and forth in the job payload as
   `project_key`). Any redesign must disentangle these two roles without
   breaking either consumer at the same moment.
2. **`Deployment.server_id` is already denormalized**, not derived from
   `project.server_id` at read time. This is a deliberate, useful pattern
   (deployment history survives a later change to the project's target)
   that the target model must preserve **one level down**, on
   `DeploymentTask`, not on `Deployment` itself.
3. **`AgentJobService::claimNextJob`** filters directly on
   `Deployment.server_id` — the single piece of code most tightly coupled
   to "one deployment, one server," and the deepest rewrite in the whole
   migration.
4. **`VerifyAgentRequestSignature`** resolves identity via
   `Server::where('public_id', ...)` and reads `agent_token_hash`/
   `signing_secret` directly off `Server` — the second piece of code with
   deep, load-bearing coupling to today's shape, and the most
   security-sensitive one to touch.
5. **`Project.environment` (string column) name-collides** with the new
   `Environment` *entity* Phase 2 introduces. This needs a deliberate
   rename during transition (proposed: `legacy_environment_label`) to
   avoid ambiguity in migration code between "the old string field" and
   "a row in the new `environments` table."
6. **`Project::latestSuccessfulDeployment()`** and
   `RollbackService::rollbackToPrevious()` both scope by `project_id`
   alone. Once one Project can have several Environments, "latest
   successful" must scope by `environment_id`, not `project_id` — an
   assumption baked into two call sites today that isn't obvious from
   either method's name.
7. **Many existing tests construct `Deployment::create([...,
   'server_id' => ...])` directly** (`AgentJobFlowTest`,
   `GitHubWebhookTest`, `DeploymentApprovalTest`, and the three Phase 1.5
   console-command tests). These encode today's flat shape as fixture
   setup, not just as assertions — flagged here so Phase 3-D's scope
   estimate (§9) is realistic rather than a surprise. Per this phase's
   own rule, none of them are touched now.

## 4. Phase 2 Target Model (recap)

Full detail and rationale already committed in
`docs/PHASE_2_AGENT_V2_REPORT.md` — not restated here. Referenced only:
`Server hasMany Agent`; `Project hasMany Environment hasMany
DeploymentTarget`, each target referencing a `Server`; `Deployment
hasMany DeploymentTask`, each task referencing a `DeploymentTarget` and
claimed by that target's Server's currently-approved `Agent`.

## 5. Migration Impact Analysis

### A) `Project.server_id` → `Project → Environment → DeploymentTarget`

**Backfill**: for every existing `Project`, create exactly one
`Environment` (`name` = the project's current `environment` string;
`branch`, `pipeline`, `auto_deploy`, `github_webhook_secret` = moved
verbatim from the project). For every newly-created `Environment`,
create exactly one `DeploymentTarget` (`server_id` = the project's
current `server_id`; **`target_key` = the project's existing
`project_key` value, reused as-is** — this is the load-bearing decision
that lets an already-deployed agent's `config.php` keep working
unmodified, since the string it already maps to a working directory
doesn't change, only which table on the Hub side owns that string's
definition).

| | Migrates | Transforms | Deprecated | Kept |
|---|---|---|---|---|
| Data | `branch`, `pipeline`, `auto_deploy`, `github_webhook_secret` (moved to Environment); `server_id` (moved to DeploymentTarget) | `environment` string → `Environment.name` (same value, relocated) | `project.server_id`, `.branch`, `.pipeline`, `.auto_deploy`, `.github_webhook_secret`, `.environment` (kept as read-only/renamed columns through the transition window, §6) | `public_id`, `name`, `repository`, `project_key` (repurposed as the seed value for `target_key`), `is_active` |

### B) Server Credential → Agent Credential

**Backfill**: for every existing `Server` (all of them carry credentials
today), create exactly one `Agent`: `server_id` = that Server's id,
**`status = 'approved'`** (grandfathered — forcing already-deployed
agents through a new pending-approval gate retroactively would silently
stop production deployments the day this ships; unacceptable),
`agent_token_hash`/`signing_secret` = **copied verbatim, never
regenerated** (regenerating would immediately invalidate every deployed
agent's credentials), `version`/`last_seen_at` = copied from the
Server's current values, `capabilities` = null/empty initially (this
data was simply never collected before — no backfill is possible for it;
it populates itself on the agent's first post-migration heartbeat).

**The single most important compatibility decision in this migration**:
the backfilled `Agent.public_id` **reuses the Server's existing
`public_id`** rather than minting a new UUID. An already-deployed agent's
`config.php` already has that UUID configured as `server_id` — reusing
it means the exact same value it already sends in the
`X-Deployment-Server-Id` header continues to resolve correctly after
migration, whether or not the header name is ever renamed at the wire
level (recommendation: **don't rename it** — it's just a label, and
leaving it as-is removes an entire class of "does every agent need a
config change" risk for free).

| | Migrates | Transforms | Deprecated | Kept |
|---|---|---|---|---|
| Data | `agent_token_hash`, `signing_secret`, `agent_version`, `last_seen_at` (all moved to Agent) | none — pure relocation, same values, same meaning | `server.agent_token_hash`, `.signing_secret`, `.agent_version`, `.last_seen_at` | `public_id` (dual-purpose during transition — see above), `name`, `description`, `is_active` |

### C) Direct `Deployment` → `Deployment` + `DeploymentTask`

**Backfill**: for every existing `Deployment`, create exactly one
`DeploymentTask` (since no historical deployment ever had more than one
target): carries over `status`, `commit_hash`, `started_at`/
`finished_at`/`last_activity_at`/`failure_message`, and references the
`DeploymentTarget` created in (A) for that project. Every existing
`DeploymentLogEntry` is re-parented from `deployment_id` to the new
`deployment_task_id` — a straightforward 1:1 remap, not a fan-out, since
today's data never had more than one target per deployment to begin
with. `Deployment` gains `environment_id` (derived from its project's
new Environment) and an aggregate `status`, initially just a copy of the
single child task's status (an aggregate-of-one is trivially that value).

| | Migrates | Transforms | Deprecated | Kept |
|---|---|---|---|---|
| Data | `status`, `queued_at`/`started_at`/`finished_at`/`failure_message`/`last_activity_at`, `server_id`, `branch` (all moved to DeploymentTask); log entries re-parented | `status` becomes a leaf value copied onto the new task, then Deployment's own `status` becomes the aggregate | `deployment.server_id`, `.branch`, `.status` (as leaf), the timestamp/failure columns (kept mirrored, read-only, through transition) | `public_id`, `commit_hash`, `previous_commit_hash`, `trigger`, `triggered_by`, `approved_by`/`approved_at`, `rollback_of_deployment_id` |

## 6. Backward Compatibility Strategy

**GitHub webhook flow today**: `GitHub → POST /api/webhooks/github/{projectKey}
→ GitHubWebhookController (sig check, event/branch filter) →
ProcessGitHubWebhookPush (queued) → DeploymentDispatcher::createFromWebhook`.

**Requirement**: every already-configured GitHub webhook (URL + secret,
already sitting in some repository's settings) must keep working,
unchanged, through and after the migration.

**Is an adapter needed? Yes — exactly one, and it's small.** The route
`POST /api/webhooks/github/{projectKey}` itself does not change at all.
Only the controller's *lookup* changes, from
`Project::where('project_key', $projectKey)` to
`Environment::where('webhook_key', $projectKey)` (or equivalent) — and
because (A)'s backfill seeds that lookup value directly from the
project's pre-existing `project_key`, and moves the exact secret value
(not a regenerated one) onto the Environment, **the URL GitHub already
calls and the secret it already signs with both keep validating with
zero reconfiguration**. Signature verification logic itself
(`GitHubWebhookVerifier`) does not change at all — only which table
supplies the secret it's given.

**Is a broader compatibility layer needed? Yes, for the transition
window specifically** (not indefinitely): recommend a **dual-read
fallback** — the webhook lookup checks `Environment.webhook_key` first,
and falls back to the legacy `Project.project_key` lookup only if no
Environment row matches, logging an audit entry (`webhook.legacy_lookup_used`)
every time the fallback fires. This covers any edge case the backfill
migration didn't anticipate, and gives a concrete, observable exit
criterion: once telemetry shows zero fallback hits for a defined window
(recommend: one full week of expected webhook traffic, or 30 days,
whichever is more conservative for the affected projects), the fallback
and the deprecated `Project` columns from (A)/(C) are safe to drop in a
dedicated cleanup migration (end of Phase 3-E, §9).

**Transition period, concretely**: both the old (deprecated, mirrored)
columns and the new tables exist simultaneously; reads prefer new,
writes go to new only, deprecated columns are frozen (no longer written)
the moment Phase 3-D ships, and are dropped only after the fallback's
zero-hit exit criterion is met — never on a fixed calendar date
regardless of actual traffic.

## 7. Service Refactor Impact

| Current service | Disposition | Notes |
|---|---|---|
| `DeploymentDispatcher` | **Refactor** | Gains: Environment-awareness (read branch/pipeline from Environment, not Project), multi-target fan-out (create N `DeploymentTask` rows instead of setting one `server_id`), a capability-check gate before creation (new dependency, doesn't exist yet). Shape and responsibility stay the same. |
| `AgentJobService` | **Split** | Stays as the claim/log/status service, now operating on `DeploymentTask`. A **new** `AgentLifecycleService` (register/approve/disable/retire, capability storage) must be built from scratch — there is no current code to refactor into it, because none of that lifecycle exists today. |
| `RollbackService` | **Refactor** | `rollbackTo()`/`rollbackToPrevious()` change from "one new Deployment, one server_id" to "one new Deployment, fan-out to the environment's current active targets (default) or an explicit subset (override)." Method shapes stay; internals change meaningfully. |
| `GitHubWebhookController` + `ProcessGitHubWebhookPush` | **Refactor, not split** | Signature verification and event/branch filtering are conceptually unchanged; only the entity resolved by `{projectKey}` changes, per §6's adapter. |
| Pipeline validation (currently inline in `ProjectController`) | **New extraction** | Does not exist as a service today. Must become a real `PipelineCompiler`/`PipelineValidator` in Phase 3-C to support `requires` (capability) validation and eventual DAG-readiness — there is no current logic to refactor, only inline validation to replace. |
| `CapabilityMatcher`, `EnvironmentLockService` | **New, from nothing** | No current equivalent exists anywhere in the codebase. |
| `RequestSigner`, `JobPayloadSigner`, `GitHubWebhookVerifier`, `AgentCredentialService`, `SecretRedactor` | **Unchanged logic, call-site update only** | Only *which model* supplies the secret changes (Agent instead of Server); the algorithms themselves are untouched. This is the lowest-risk part of the entire migration, precisely because Phase 1.5 already isolated these as small, single-purpose classes. |

## 8. Testing Impact

**Unaffected, remain valid as-is**: every Security-layer test
(`SecretRedactorTest`, the HMAC/replay/signature assertions embedded in
`AgentJobFlowTest` and `AgentHeartbeatTest`) — the algorithms under test
don't change, only which model happens to supply the key at the call
site, which these tests don't assert on directly.

**Will require rewriting, but not now — in Phase 3-D specifically**:
any test that constructs `Deployment::create([..., 'server_id' => ...])`
directly or asserts on `$deployment->status` as a leaf execution value
(`AgentJobFlowTest`, `GitHubWebhookTest`, `DeploymentApprovalTest`,
`PruneAgentNoncesTest`/`DetectStalledDeploymentsTest`/
`PruneDeploymentDataTest`'s fixture setup). Naming this now, per this
phase's own audit mandate, so Phase 3-D's scope isn't a surprise — none
of these files are touched in Phase 3-A.

**New tests required** (already anticipated in the Phase 2 report §14;
one additional category specific to this phase's concern):

- Everything listed in `PHASE_2_AGENT_V2_REPORT.md` §14 (registration,
  approval, heartbeat metadata, capability validation, token lifecycle,
  multi-target fan-out, locking, retry, failure independence, rollback
  target selection).
- **Migration correctness tests** (new category, not previously called
  out, because Phase 2 had no live data to migrate against yet): for
  every existing `Project`, the backfill creates exactly one
  `Environment` and one `DeploymentTarget` with values that exactly
  match the original `branch`/`pipeline`/`secret`/`server_id`; for every
  existing `Deployment`, exactly one `DeploymentTask` is created
  carrying over `status`/timestamps/`failure_message` unchanged, and
  every existing `DeploymentLogEntry` is re-parented without losing a
  single row. These tests are what makes the backfill migration
  reviewable and re-runnable with confidence in Phase 3-D.

## 9. Implementation Roadmap

| Stage | Goal | Risk | Dependency |
|---|---|---|---|
| **Phase 3-B — Agent Identity** | Build `Agent` entity, registration/approval endpoints, expanded heartbeat (capabilities/os/arch/ip), and the (B) backfill grandfathering existing Server credentials as pre-approved Agents. Deployment/job execution keeps flowing through today's shape — only *who authenticates* changes, not *what runs*. | **Medium** — touches `VerifyAgentRequestSignature`, the most security-sensitive code path in the system. Mitigated: the signing/replay algorithm itself doesn't change, only which table supplies the secret. | None — this audit is its prerequisite; can start immediately. |
| **Phase 3-C — New Deployment Domain** | Build `Environment`, `DeploymentTarget`, `DeploymentTask` (additive — new tables only, nothing old touched or removed), plus `CapabilityMatcher`, `PipelineCompiler`, `EnvironmentLockService` as new code. Old webhook/dashboard paths keep running unchanged, in parallel. | **Low** — purely additive; nothing existing is modified or at risk. | Phase 3-B (a `DeploymentTask` claim needs `Agent` to exist). |
| **Phase 3-D — Migration** | Run the (A)/(B)/(C) backfills, cut the webhook adapter over (§6's dual-read fallback), cut dashboard/agent-facing paths to the new tables, freeze (don't yet drop) the deprecated columns. | **Highest in the roadmap** — the only stage touching live data, and the only stage where existing tests are expected (and allowed) to change. | Phase 3-B and 3-C both complete and independently tested. |
| **Phase 3-E — Execution Engine** | Enable real multi-target fan-out end-to-end, retry/cancel/timeout refinements, dashboard views, and — only once §6's zero-fallback-hit exit criterion is observed — drop the deprecated columns in a dedicated cleanup migration. | **Low–Medium** — building on an already-migrated, already-tested foundation. | Phase 3-D complete and stable for an observed period. |

## 10. Risks and Recommendations

- The `project_key` dual-meaning (§3, hidden dependency #1) is the
  sharpest edge in this entire plan. The reuse strategy in §5(A)/(B) is
  what defuses it — lock that decision before writing a single line of
  migration code in Phase 3-D; reversing it after the fact means
  choosing between breaking every live webhook URL or every live agent
  config at once.
- Write the Phase 3-D backfill migration to be **strictly additive and
  re-runnable** — safe to execute against a copy of production data
  repeatedly while validating, and destructive to nothing until the
  explicit Phase 3-E cleanup step.
- Give the Phase 3-D backfill a **dry-run/report mode** that prints
  exactly what it would create without writing anything, so the mapping
  can be sanity-checked against real data before it's ever committed —
  cheap to build, and the highest-confidence safeguard available for the
  riskiest stage in the roadmap.
- Schedule Phase 3-D as its **own isolated deploy**, not bundled with
  Phase 3-C's new-code-only changes — so that if a problem surfaces in
  production, it can be attributed unambiguously to the migration step
  itself and not to unrelated new code shipped in the same release.
- None of the above changes this phase's own output: **no migration,
  model, or code accompanies this document.** Everything above is the
  plan Phase 3-B onward executes against, not something already done.
