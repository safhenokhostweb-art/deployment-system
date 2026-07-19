# Phase 3-C — Deployment Domain Foundation (Additive Only, Audit + Design)

Audit and design only — this document is the entire Phase 3-C
deliverable. No migration was written or run, no legacy model was
removed or modified, no existing Deployment behavior changes as a
result of anything described here. `saas-platform` was not read or
touched. Builds on `docs/PHASE_2_AGENT_V2_REPORT.md` (target model),
`docs/PHASE_3A_DOMAIN_MIGRATION_AUDIT.md` (data-migration plan, deferred
to Phase 3-D), and `docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md` (the
dual-lookup pattern this document applies to the deployment domain
instead of the auth domain).

## 1. Executive Summary

Phase 3-B proved a pattern: ship the new thing as pure addition, resolve
old-vs-new at read time, never force a cutover. This document applies
that exact same pattern one layer up — to `Project`/`Server`/`Deployment`
instead of `Server`/`Agent`.

The central design decision: a **`DeploymentTargetResolver`** sits
between every future piece of deployment-execution code and the
question "what should this Project deploy to." For every Project that
exists today (and any created the old way going forward), it returns a
**synthesized, non-persisted** target built from `Project.server_id` —
not a real `DeploymentTarget` row, and nothing is ever written to the
new tables on its behalf. Only a Project an operator has *deliberately*
given real `Environment`/`DeploymentTarget` rows gets the real,
multi-target path. This is what makes "no data migration" and "current
Deployment must work unchanged" simultaneously true by construction,
exactly as Phase 3-B's dual-lookup made Old/New Agent coexistence a
structural property rather than a scheduling promise.

## 2. Current Domain Analysis

Re-verified directly against the repository (full detail already on
record in `PHASE_3A_DOMAIN_MIGRATION_AUDIT.md` §2–§3 and unchanged since
— nothing in the codebase has moved between that report and this one):

- **Project**: `server_id` single FK, `environment` a plain string (not
  a relation), `branch`/`pipeline`/`auto_deploy`/`github_webhook_secret`
  all live directly on Project.
- **Server**: still the sole credential holder as of this writing —
  Phase 3-B's Agent table is itself still design-only, not yet built.
  Phase 3-C's new domain therefore currently has **no real Agent to
  resolve down to** — see §9's dependency note.
- **Deployment**: `server_id` denormalized from the project at creation
  time; `status` is a leaf execution value, not an aggregate.
- **Pipeline**: no model — a JSON array on `Project.pipeline`, validated
  inline in `ProjectController::validateProject()`. No reusable
  validation service exists.
- **Job**: no separate model — `AgentJobService::claimNextJob()` claims
  a `Deployment` row directly; "job" and "deployment" are the same row
  today.
- **Webhook flow**: `{projectKey}` routes directly to `Project`,
  unchanged since Phase 1.

### Dependency report — specific to this phase's concern

- **`AgentJobService::claimNextJob()`** is the *only* place that defines
  "what's claimable" today (`Deployment` where `status = queued` and
  `server_id` matches). The new domain's claim logic must live on
  **entirely separate tables**, so this query is literally untouched —
  not just logically unaffected, but physically incapable of seeing new
  rows it was never written to select.
- **One polling endpoint, two sources**: `GET /api/agent/jobs/next` must
  keep working for an agent exactly as it does today. Rather than adding
  a second URL the agent would need to learn, the design in §5/§9
  extends this single endpoint's *handler* to check both the legacy
  `Deployment` table and the new `AgentJob` table, so nothing on the
  agent side — protocol, URL, or config — ever needs to change on
  account of this phase.
- **Implicit concurrency protection today**: nothing explicitly locks a
  legacy Project against two simultaneous deployments — but because one
  Agent claims one job per poll, and a legacy Deployment is always
  scoped to a single `server_id`, a second queued Deployment for the
  same server simply waits for the same agent's next poll. This is
  *why* explicit Environment-level locking (§7) is a genuinely new
  requirement for the multi-target case, not a gap in what exists today:
  the new model introduces a scenario — several different Agents, on
  different Servers, claiming work for the *same Environment*
  concurrently — that the legacy single-server model structurally never
  had to consider.

## 3. New Domain Design

```
Project  (unchanged: repository, name)
   │ hasMany
   ▼
Environment        (name, branch, pipeline incl. `requires`, auto_deploy,
                     webhook_key/secret)
   │ hasMany
   ▼
DeploymentTarget   (target_key, references one Server, is_active)
   │ hasMany (across its history)
   ▼
DeploymentTask     (one per Deployment × Target; persists across retries)
   │ hasMany
   ▼
AgentJob           (one per execution attempt, claimed by one Agent)
```

| Entity | Responsibility | Ownership | Relationship |
|---|---|---|---|
| **Environment** | One deployable configuration of a Project for one purpose (e.g. "production"): owns branch, pipeline steps (incl. capability `requires`), auto_deploy, its own webhook secret/key. | `belongsTo` Project; deletion restricted while it has any Target or Deployment history. | `hasMany` DeploymentTarget, `hasMany` (new-domain) Deployment, owns its own lock state (§7). |
| **DeploymentTarget** | One place, within an Environment, a deployment should reach. References exactly one Server; carries the agent-config-facing `target_key`; `is_active` lets an operator pull it out of fan-out without deleting history. | `belongsTo` Environment; **references, does not own,** Server (a Server may be referenced by many Targets across many Environments/Projects). | `hasMany` DeploymentTask (across every Deployment that included it). |
| **DeploymentTask** | The persistent "this target needs commit X" work item for one (new-domain) Deployment × one DeploymentTarget pair. Its `status` is the latest known state, derived from its AgentJob attempts. | `belongsTo` (new-domain) Deployment **and** `belongsTo` DeploymentTarget — a genuine two-parent join-like entity. | `hasMany` AgentJob (one row per retry attempt). |
| **AgentJob** | One concrete claim-and-execute attempt of a DeploymentTask by a specific Agent: `attempt_number`, `status`, `started_at`/`finished_at`/`last_activity_at`, `failure_message`, `cancel_requested`. | `belongsTo` DeploymentTask; `belongsTo` Agent (the claimer, from Phase 3-B). | `hasMany` AgentJobLogEntry (not separately requested in this brief, but necessarily implied — step-by-step output per attempt, the same shape as today's `DeploymentLogEntry`, just re-parented one level deeper). |

**Naming note carried into implementation**: the new aggregate-level
entity that groups `DeploymentTask`s together is conceptually also
called "Deployment" in Phase 2's report — but it must **not** share a
PHP class or table with today's `App\Models\Deployment`. Recommend a
distinct namespace (e.g. `App\Domain\Deployment\*` or an equivalent
non-colliding name) decided explicitly in 3-C.1 (§9), so "Legacy
Deployment" and "New Deployment" are never ambiguous in code, only in
prose.

## 4. Compatibility Strategy

```
Legacy Deployment (Project.server_id, flat)
        │
        ▼
   DeploymentTargetResolver   ← the adapter
        │
        ▼
New Deployment Domain (Environment / DeploymentTarget / DeploymentTask / AgentJob)
```

`DeploymentTargetResolver::resolveTargetsFor(Project $project): array<TargetView>`
is the one method every future execution-path piece of code (dispatch,
capability matching, claim logic, the webhook adapter) calls instead of
reading `Project.server_id` or `Project.environment` directly:

- **If the Project has no `Environment` rows** (true for every existing
  Project today, and for any created the old way going forward): returns
  exactly **one synthesized, non-persisted** `TargetView` — built on the
  fly from `Project.server_id`, `.branch`, `.pipeline`, `.auto_deploy`,
  `.github_webhook_secret`, `.environment` (string). Same interface
  shape as a real one; calling code never needs to know which kind it
  received. **Nothing is written to `environments`/`deployment_targets`
  for this branch — this is what satisfies "بدون انتقال داده‌های موجود"
  precisely.**
- **If the Project has real rows**: returns the actual DB-backed targets
  for the relevant Environment.
- **`target_key` for a synthesized target = the Project's existing
  `project_key`** — the same reuse-existing-identifiers principle from
  Phase 3-A §5, so a Project promoted to the real model later (an
  explicit, optional, future action — mirroring Phase 3-B's "promote a
  Server" tool) doesn't change the value any already-installed agent's
  `config.php` maps against.
- **The webhook adapter reuses the identical resolver logic**: `{projectKey}`
  lookup tries a real `Environment.webhook_key` first, falls back to
  `Project.project_key` directly — the same dual-lookup shape Phase 3-B
  established for auth, applied here to deployment routing.

## 5. Service Refactor Plan

| Current / needed | Disposition |
|---|---|
| `DeploymentDispatcher` | **Untouched for legacy path.** Its `createFromWebhook`/`createManual` continue to produce exactly today's single-`Deployment`-row behavior for any Project the resolver treats as legacy — no DeploymentTask/AgentJob rows are ever created on that path. |
| `MultiTargetDeploymentOrchestrator` *(new)* | Net-new class, engaged **only** when the resolver returns real (non-synthesized) targets. Creates the new-domain Deployment + one DeploymentTask per selected/active target. |
| `AgentJobService` | **Untouched.** Keeps claiming legacy `Deployment` rows exactly as today. |
| `AgentTaskService` *(new)* | Net-new class implementing DeploymentTask/AgentJob claiming against the new tables. |
| Single poll endpoint | Its handler is extended (not replaced) to check both `AgentJobService::claimNextJob()` (legacy) and `AgentTaskService::claimNext()` (new), returning whichever is eligible — the only place old and new claiming logic touch at all, and it's additive glue, not a modification of either service. |
| Pipeline validation | **New extraction**: a real `PipelineCompiler`/`PipelineValidator` for `Environment.pipeline` (with `requires` support) is built from scratch for the new domain. The legacy inline validation in `ProjectController::validateProject()` stays completely untouched for legacy `Project.pipeline`. Two parallel, non-interfering validators during the transition. |
| `CapabilityMatcher`, `EnvironmentLockService` *(new)* | Still nothing to refactor into them (per Phase 3-A §7) — built from nothing, only ever consulted by the new-domain orchestrator, never by the legacy path. |
| `DeploymentTargetResolver` *(new)* | The adapter itself (§4) — the one piece of genuinely shared code every other new service depends on. |

## 6. Multi Target Flow

- **Manual Deployment**: for a Project with real Environments, the
  operator sees a checklist of that Environment's active targets
  (pre-checked, per Phase 2 §9) and `MultiTargetDeploymentOrchestrator`
  fans out to the selection. For a legacy Project, **the existing manual
  deploy UI/flow (`ProjectController::deployManual`) runs completely
  unchanged** — no checklist appears, because the resolver has nothing
  to offer a choice between; the experience is pixel-for-pixel identical
  to today.
- **Webhook Deployment**: a legacy Project's webhook keeps hitting
  `GitHubWebhookController → ProcessGitHubWebhookPush →
  DeploymentDispatcher::createFromWebhook` exactly as today. A Project
  the resolver recognizes as "New" instead deploys to **every currently
  active DeploymentTarget** in the triggered Environment — no human is
  present to select a subset, matching Phase 2 §9's rule verbatim.
- **Rollback, per target**: for the new domain, rollback supports
  targeting **as few as one specific DeploymentTarget** — e.g. "web-03
  had a bad deploy, roll back only web-03 while web-01/02 stay on the
  newer commit." This is a direct concretization of Phase 2 §9's
  `selected_target_ids` mechanism, now explicit that a single-target
  selection is a fully supported, expected case, not just an edge of "a
  subset." A legacy Project's rollback continues to use the existing
  `RollbackService::rollbackTo()`/`rollbackToPrevious()` completely
  unchanged (single `server_id`, exactly as today).

## 7. Locking Design

- **Scope**: Environment-level (reaffirming Phase 2 §10.4) — and, per
  §2's dependency note, this is a genuinely *new* requirement for the
  multi-target case specifically, not a gap being retrofitted into the
  legacy path (which never needed it, and still won't).
- **Mechanism**: a nullable `locked_by_deployment_id` + `locked_at` on
  `Environment`, acquired the moment the first `AgentJob` for a
  (new-domain) Deployment is claimed, released once every `DeploymentTask`
  belonging to that Deployment reaches a terminal state — identical
  shape to Phase 2 §10.4, unchanged here.
- **Timeout, recovery, stalled detection**: recommend **extending** the
  existing `deployments:detect-stalled` command's implementation to also
  scan new-domain `AgentJob` rows for no-activity timeout (releasing the
  Environment lock when it fails a stuck aggregate Deployment), rather
  than standing up a second, near-identical scheduled command. The
  underlying policy — find stuck things, fail them, log it, same
  timeout config — is identical in both domains; only the table being
  scanned differs. This is the one place this document recommends
  extending an existing artisan command's body rather than adding a
  parallel one, specifically because maintaining two copies of the same
  policy logic is a worse long-term risk than the small increase in that
  command's scope.

## 8. Testing Strategy

- **Existing 37 tests**: unaffected, structurally, not just for this
  phase — because legacy `Project`/`Server`/`Deployment`/
  `AgentJobService` code is never modified by this design (only extended
  at one narrow glue point, §5), every existing test keeps passing
  indefinitely, not merely until some later phase touches it. This is a
  stronger guarantee than Phase 3-A's roadmap assumed would be possible
  by this point — Phase 3-D remains the one place legacy tests are
  expected to change, and that expectation is unaffected by this report.
- **New tests required** (for the eventual 3-C implementation):
  - `DeploymentTargetResolver`: returns a synthesized target for a
    legacy Project; returns real targets for a Project with Environments;
    `target_key` continuity between the two.
  - Environment/DeploymentTarget/DeploymentTask/AgentJob model
    relationships and ownership/delete-restriction constraints.
  - Capability matcher (once built) evaluated against the new domain
    only — never invoked on the legacy path.
  - Multi-target manual deploy: checklist selection produces exactly the
    expected `DeploymentTask` fan-out.
  - Multi-target webhook deploy: all active targets included, no partial
    selection possible.
  - Per-target rollback: exactly one `DeploymentTask` created for the
    chosen target; unrelated targets in the same Environment are
    untouched.
  - Environment locking: concurrent claim attempts across different
    simulated Agents for the same Environment — this specifically
    exercises the new concurrency scenario §2 identified as absent from
    the legacy model.
  - Extended stalled-detection command: covers both legacy `Deployment`
    and new `AgentJob` rows without regressing any existing assertion in
    `DetectStalledDeploymentsTest`.

## 9. Implementation Roadmap

Depends on **Phase 3-B being implemented first** (a real `Agent` must
exist for `DeploymentTarget → Server → Agent` resolution to mean
anything) — as of this writing, 3-B is itself still design-only, so
Phase 3-C's own implementation cannot begin before it.

| Stage | Scope | Ship-time behavior |
|---|---|---|
| **3-C.1** | New tables (Environment/DeploymentTarget/DeploymentTask/AgentJob) + `DeploymentTargetResolver` returning synthesized views for all-legacy state; the naming-collision decision (§3) settled in code. | Provably a no-op — zero real Environments exist yet, every Project resolves through the synthesized branch, identical to today. |
| **3-C.2** | `MultiTargetDeploymentOrchestrator`, manual-deploy checklist UI, webhook adapter branch, `AgentTaskService`, the extended single poll-endpoint handler. | Still a no-op for every current Project — this path only ever engages for a Project an operator has explicitly given real Environment rows, and none exist yet at ship time. |
| **3-C.3** | Environment locking, extended stalled-detection, per-target rollback. | Same — inert until a real multi-target Environment exists. |

Phase 3-A's Phase 3-D (the actual data backfill + eventual legacy-column
cleanup) remains the separate, later, higher-risk stage this document
does not revisit.

## 10. Risks and Recommendations

- **Synthesized (virtual) target views must be airtight**: they must
  never be accidentally persisted, and never leak a null/placeholder id
  into a real foreign key. Recommend the `TargetView` value object be a
  plain, non-Eloquent DTO (not a lightweight fake model) specifically so
  it's structurally impossible to `->save()` one by mistake.
- **Naming collision risk** between legacy `App\Models\Deployment` and
  the new aggregate entity is real and must be settled explicitly in
  3-C.1, not left to whoever writes the first migration to improvise —
  see §3's recommendation.
- **Claim-fairness risk**: once the single poll endpoint checks two
  sources (§5), an ordering rule is needed so new-domain jobs can't be
  starved behind a backlog of legacy ones, or vice versa. Recommend
  comparing `queued_at`/equivalent across both sources and claiming
  whichever is globally oldest, rather than always checking one source
  first — a fixed priority order is simpler to write but silently unfair
  under load.
- **Explicit dependency on Phase 3-B**: this document assumes Agent
  exists; if Phase 3-B's implementation timeline slips, Phase 3-C's
  cannot start regardless of this design being ready.
- As with every phase in this series: nothing above is built yet. This
  document is the plan Phase 3-C's own implementation stages (3-C.1
  through 3-C.3) execute against, not something already done.
