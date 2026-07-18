# Phase 2 — Agent v2 & Multi-Server Deployment Architecture

Architecture-only. No migration, model, controller, or test was written for
this report — per the task rules, this document is the entire Phase 2
deliverable. Nothing in `saas-platform` was read or touched, and no new
dependency is proposed anywhere below.

## 1. Executive Summary

The MVP (`v0.1.0-mvp-complete` + Phase 1.5 hardening) proved the core
security model — pull-based agent, HMAC-signed requests, signed job
payloads, replay protection — and that model is **kept unchanged in this
design**. What Phase 2 redesigns is everything built *on top* of it: who
is allowed to become an agent in the first place, what an agent is
allowed to say about itself, and how one deployment reaches more than one
server.

Three structural decisions drive the rest of this document:

1. **Agent becomes its own entity**, separate from `Server` (§6) — a
   `Server` is a place; an `Agent` is a credentialed, versioned process
   that runs there and goes through its own `Register → Approve`
   lifecycle (§5).
2. **`Project → Server` (1:1) becomes `Project → Environment →
   DeploymentTarget → Server/Agent`** (§8, §9) — the only way to
   represent "deploy to web-01, web-02, and web-03" without duplicating a
   Project three times.
3. **`Deployment` splits into `Deployment` (the trigger/intent, one per
   commit-per-environment) and `DeploymentTask` (one per target)** (§10)
   — so a single push can succeed on two servers and fail on a third, and
   the dashboard can show that truthfully instead of averaging it away.

Every other task in the brief (capability matching, locking, retry,
cancel, update-checking, dashboard) is a consequence of these three
decisions, not an independent one.

## 2. Initial Audit Summary

Full detail in `docs/PHASE_2_AUDIT.md` (committed separately, unchanged
here). The load-bearing findings that this design responds to:

- Registration is 100% admin-driven today; there is no agent-initiated
  `Register → Approve` step at all.
- Heartbeat carries only `agent_version`; no OS, architecture, IP, or
  capability data exists anywhere.
- Job claiming has no concept of capability matching — a step requiring
  Docker would be handed to an agent without it.
- `Project.server_id` is a single mandatory FK — multi-server deployment
  is not representable today short of duplicating the Project.
- None of the above are security regressions; Phase 1.5's HMAC/replay/
  authorization posture is sound and is preserved, not reopened, by this
  design (re-confirmed in §11).

## 3. Architecture Decisions (summary log)

| # | Decision | Rationale (short) |
|---|---|---|
| D1 | Agent is a new entity, `Server hasMany Agent` | Separates "credentialed process" from "place" — see §6 for the full comparison |
| D2 | Registration is self-service (agent-initiated) + human approval, via a short-lived, single-use, HMAC-signed registration token | Closes the audit's #1 gap without weakening steady-state auth |
| D3 | `Server` keeps no credentials after this change; all auth material moves to `Agent` | A disabled/compromised Agent can be revoked without touching the Server record other agents on the same box may still be using |
| D4 | `Project → Environment → DeploymentTarget → Server` (Agent resolved from Server at claim time) | Only structure that lets one Project fan out to N servers without duplicating Project config |
| D5 | `Deployment` (intent) is split from `DeploymentTask` (one per target) | Required for truthful partial-success reporting across N servers |
| D6 | Capability requirements are declared per pipeline step (`requires: {...}`), matched by simple presence/equality against `Agent.capabilities`, checked at Deployment **creation** time, fail-closed | Matches the brief's "Deployment نباید اجرا شود" instruction — reject before anything runs, not mid-run |
| D7 | Deployment lock scope = **Environment**, implemented as a DB row + the existing `lockForUpdate()`/stall-detection pattern, no new lock service | Cheapest correct mechanism; reuses infrastructure already proven in Phase 1.5 |
| D8 | Task failures are **independent** across targets in the same Deployment (no fail-fast cancellation of siblings) | A partial success (2 of 3 servers) is actionable information; forced cancellation would destroy it |
| D9 | Cancellation of a running task is **best-effort and asynchronous** (flag-and-poll, not a kill signal) | The pull-only/no-inbound-connection model is not being compromised for this feature |
| D10 | Auto-retry of a failed task is **off by default**, opt-in per Environment, because deployment pipelines are not guaranteed idempotent | Blind auto-retry of e.g. a half-applied migration can do active harm; manual retry remains the safe default path |
| D11 | Agent auto-update is design-only in this phase; any future implementation must verify a release signature with a keypair independent of any agent's `signing_secret` | Prevents one compromised agent from being able to forge a "legitimate update" for the fleet |
| D12 | Log streaming is periodic-chunk-append + dashboard short-poll, not websockets/SSE | No new dependency; fits the existing HTTP-only stack |

## 4. ADR Updates

No ADRs exist yet for this concrete project (the earlier ADR-01..13 list
from an unrelated, broader "DevOps Hub" concept discussion does **not**
apply here — this is a different, narrower, already-implemented system).
This phase proposes the first real ADR set for `deployment-system`,
to be split into individual `docs/adr/000X-*.md` files in Phase 3
alongside the code that implements them:

| ADR | Title | Status |
|---|---|---|
| ADR-001 | Agent as an entity independent from Server | Proposed (this doc, §6) |
| ADR-002 | Agent bootstrap via short-lived signed registration token + human approval | Proposed (§5) |
| ADR-003 | Environment/DeploymentTarget hierarchy for multi-server fan-out | Proposed (§8, §9) |
| ADR-004 | Deployment/DeploymentTask split for per-target status | Proposed (§10) |
| ADR-005 | Capability matching: presence/equality only, checked at creation time, fail-closed | Proposed (§7) |
| ADR-006 | Environment-scoped locking via existing DB-transaction + stall-detection pattern (no new lock service) | Proposed (§10.4) |
| ADR-007 | Task failure independence across targets (no fail-fast) | Proposed (§10.3) |
| ADR-008 | Cancellation is flag-and-poll, best-effort | Proposed (§10.5) |
| ADR-009 | Agent update mechanism: design only, dual-keypair signature requirement | Proposed (§12) |

## 5. Agent Lifecycle Design

```
 Agent installer runs on target box
        │  (admin already created a Server row + a one-time
        │   registration token for it, out-of-band — see §6.3)
        ▼
 POST /api/agent/register
   { registration_token, name, os, architecture, capabilities }
   signed with the registration_token itself as an HMAC key
   (same canonical-string scheme as steady-state RequestSigner —
   no new crypto primitive, just a short-lived key)
        │
        ▼
 Agent row created, status = pending_approval
 registration_token is single-use: consumed atomically, a replayed
 registration attempt with the same token is rejected
        │
        ▼
 ┌─────────────────────────────────────────────────────────┐
 │ Dashboard: Pending Approvals queue                       │
 │ An operator reviews name/OS/arch/capabilities and clicks │
 │ Approve (or Reject, which deletes the pending row)       │
 └─────────────────────────────────────────────────────────┘
        │  Approve
        ▼
 agent_token + signing_secret generated, shown once
 status = approved, approved_by/approved_at recorded
 (identical shown-once-and-hashed handling as today's Server tokens)
        │
        ▼
 Heartbeat (POST /api/agent/heartbeat, full auth — bearer+HMAC+replay
 protection, unchanged mechanism, now keyed by Agent instead of Server)
   → updates last_seen_at, capabilities, version, ip (server-captured)
        │
        ▼
 Receive Job (GET /api/agent/jobs/next, now resolves DeploymentTask
 rows via this Agent's server_id instead of Deployment rows directly)
        │
        ▼
 Execute (unchanged agent-side mechanics: proc_open, step timeout,
 output truncation — now additionally honors a cancel_requested flag,
 see §10.5)
        │
        ▼
 Report Result (status/log endpoints, unchanged shape, now scoped to
 DeploymentTask instead of Deployment)
```

**Status enum** (`Agent.status`): `pending_approval`, `approved`,
`disabled` (operator-revoked, can be re-enabled), `retired` (superseded
by a newer approved Agent for the same Server — terminal, not
re-enabled). **"Offline" is deliberately not a stored status** — exactly
like today's `Server::isOnline()`, it stays a computed property from
`last_seen_at` vs a threshold, for the same reason: a computed value
can't go stale, a stored one can.

**One-active-agent-per-server rule**: approving a new Agent for a Server
that already has an approved Agent automatically retires the previous
one. This keeps "which Agent currently serves this Server's jobs"
unambiguous without needing a runtime tie-breaker, and cleanly models the
real scenario of re-provisioning a box with a fresh agent install.
Multiple *pending* Agents for the same Server can coexist harmlessly
(e.g., an install script run twice) — approval is what disambiguates.

## 6. Agent Entity Design Decision

### 6.1 Option comparison

| Criterion | Option A — independent Agent entity | Option B — extend Server |
|---|---|---|
| Security | Credential revocation/rotation of one process is independent of the Server record; a Server hosting multiple environments can have one Agent disabled without affecting the others | Disabling "the Server" would have to mean disabling every credential tied to it — no way to revoke one process without the other |
| Multi-server (§8/§9) | `Server` is free to be a pure "place" referenced by many `DeploymentTarget` rows across many Environments/Projects | `Server` stays overloaded as both "place" and "credential holder," awkward once one physical box serves several Environments |
| Multi-environment | A single box running two isolated agent processes (e.g. staging + production side by side) maps to two `Agent` rows under one `Server` | Would require either two `Server` rows for one physical machine (wrong — duplicates the "place" concept) or cramming two credential sets onto one row |
| Maintenance/versioning | Version, capabilities, and update lifecycle are properties of the *software process* — re-provisioning a box naturally becomes "retire old Agent, register new Agent," Server row and its history untouched | Version/capability history gets tangled with the Server's own identity; "this Server was upgraded" and "this box was reformatted" become indistinguishable |
| Cost today | One new table, one new relation — pure design cost, zero migration risk since this phase ships no migration | Would need re-splitting later anyway once multi-environment-per-box comes up, at which point it's a breaking migration against live data |

**Decision: Option A.** It wins on every criterion the brief asked to
judge it against, and Phase 2's own stated goals (multi-server,
multi-environment) are difficult to satisfy correctly under Option B
without revisiting it later at higher cost (live data, live credentials
in the field).

### 6.2 Final relationship

```
Server (1) ──< (N) Agent
```

- **`Server`** — a place. Retains: `public_id`, `name`, `description`,
  `is_active` (server-level: e.g. decommissioned), timestamps. **No
  credential fields after this change** — those move to `Agent`.
- **`Agent`** — a credentialed, versioned process. `public_id` (UUID —
  the "Agent Identity" the brief asks for), `server_id` (FK), `name`
  (agent-declared or operator-assigned label), `status` (§5), `version`,
  `os`, `architecture`, `ip_address` (captured server-side from the
  request, **never** client-self-reported, to prevent spoofing),
  `capabilities` (JSON, §7), `agent_token_hash`, `signing_secret`
  (encrypted — same two-secret model as today, just relocated),
  `registration_token_hash` + `registration_token_expires_at` (bootstrap
  only, cleared after approval), `last_seen_at`, `approved_by`,
  `approved_at`, timestamps.

### 6.3 Bootstrap: how a registration token is issued without hardcoding anything

An operator still creates the `Server` row first (a label — "web-01",
its description — not a credential), then requests a registration token
for it from the dashboard. That token is short-lived (proposed default:
30 minutes), single-use, shown once, and hashed at rest exactly like
`agent_token` — it is handed to whoever is provisioning the box the same
out-of-band way credentials are handed over today (this doesn't change
the "no hardcoded server info" constraint: nothing about the box's
network location, filesystem layout, or long-lived credentials is ever
in source code — only a human-carried, expiring bootstrap secret is).

## 7. Capability System Design

### 7.1 Declaration

`Agent.capabilities` — a flat JSON object, reported on every heartbeat
(overwriting the stored value each time, so it's always current):

```json
{
  "os": "linux",
  "architecture": "x86_64",
  "php": "8.4",
  "composer": true,
  "docker": true,
  "queue": true,
  "extensions": ["pdo", "redis"]
}
```

Purely additive — a new capability key needs no schema change on either
side, satisfying the brief's "extensible for the future" requirement
directly.

### 7.2 Requirement declaration

Each pipeline step gains an optional `requires` object:

```json
{
  "name": "compose up",
  "type": "docker",
  "action": "compose_up",
  "requires": { "docker": true }
}
```

### 7.3 Matching semantics (v1 — deliberately simple)

A conceptual `CapabilityMatcher::check(Agent $agent, array $requires):
array` returns the list of unmet requirements. Matching in v1 is
**presence/equality only**: a boolean requirement must equal `true` on
the agent; a scalar requirement (e.g. a future `"node": "20"`) must
equal exactly. **Version-range matching (e.g. `"php": ">=8.2"`) is
explicitly out of scope for v1** — it needs a semver comparison
component this phase doesn't justify building before there's a real
pipeline that needs it (see §14).

### 7.4 Enforcement point

Checked once, at **Deployment creation time** (webhook, manual, and
rollback paths alike), against **every** `DeploymentTarget`'s currently
active `Agent` that would be included in the fan-out. If **any** target
agent is missing **any** requirement used by **any** step in the
Environment's pipeline, the Deployment is not created at all — it is
rejected with a specific, audit-logged reason naming the offending
target and capability. This is fail-closed at the whole-deployment level
by design: allowing the deployment to start and only skip the
unsupported step on one target would silently produce an inconsistent
fleet, which is worse than refusing the whole rollout up front.

## 8. Multi-Server Deployment Design

### 8.1 New hierarchy

```
Project  (repository, name)
   │  hasMany
   ▼
Environment  (name e.g. "production", branch, pipeline, auto_deploy,
              its own webhook secret + webhook URL slug)
   │  hasMany
   ▼
DeploymentTarget  (target_key, references one Server, is_active)
   │  resolves to
   ▼
Server → (its current approved) Agent
```

### 8.2 Why branch/pipeline/webhook move to Environment, not stay on Project

Today all of these live on `Project`. Once one Project can have both a
`production` and a `staging` Environment, they can no longer be
Project-wide: staging typically tracks a different branch, often skips
steps (or has different `requires`), and needs its own webhook secret so
GitHub deliveries route to the right Environment. `Project` becomes
purely "this repository, under this name"; everything that varies by
environment lives on `Environment`.

### 8.3 Agent-side config key changes

Today the agent's local `config.php` maps `project_key → {working_directory}`.
That stops being sufficient the moment one physical box hosts the same
Environment for two different Projects. The mapping key becomes
`target_key` (unique per `DeploymentTarget`, agent-chosen/operator-set at
target-creation time) instead of `project_key` — a small, mechanical
rename with the same shape and the same "server never learns this from
the Hub" security property (still purely local to the agent's own
`config.php`).

### 8.4 Ownership

- `Project` owns its `Environment`s (delete restricted while any
  Environment has deployment history, same `restrictOnDelete()` pattern
  used elsewhere today).
- `Environment` owns its `DeploymentTarget`s (same restriction rule).
- `DeploymentTarget` **references** (does not own) a `Server` — a
  `Server` may be referenced by many `DeploymentTarget`s across many
  Environments and Projects (the existing "shared box running two apps"
  scenario from `docs/ARCHITECTURE.md` §7, now properly modeled instead
  of implied).

## 9. Deployment Target Architecture

`DeploymentTarget`: `environment_id` (FK), `server_id` (FK), `target_key`
(the agent-config-facing identifier, §8.3), `is_active` (lets an operator
temporarily pull one target out of an Environment's fan-out — e.g.
"web-03 is being reimaged, exclude it" — without deleting history),
timestamps.

**Per-deployment target selection** (the brief's checkbox example):
every deployment-triggering action (manual trigger, rollback) presents
the Environment's currently-active targets as a checklist, pre-checked;
the operator may uncheck any before confirming. The resulting
`Deployment` row records `selected_target_ids` (JSON array) so history
shows exactly what was intended, and only the selected targets get a
`DeploymentTask`. **Webhook-triggered (auto_deploy) deployments always
use every active target** — there's no human present to uncheck
anything, and a webhook silently deploying to a subset would be a
surprising, hard-to-audit behavior.

## 10. Job Queue / Deployment Task Design

### 10.1 Three-level shape

```
Deployment           (the trigger: one commit, one Environment, one intent)
   │  hasMany
   ▼
DeploymentTask        (one per selected DeploymentTarget; what an Agent claims)
   │  hasMany
   ▼
DeploymentTaskLogEntry (unchanged shape from today's DeploymentLogEntry,
                         just re-parented to DeploymentTask)
```

`Deployment` fields (superset of today's, environment-scoped now):
`environment_id`, `commit_hash`, `previous_commit_hash`, `trigger`,
`triggered_by`, `approved_by`/`approved_at`, `selected_target_ids`,
**aggregate** `status` (`pending_approval`, `queued`, `running`,
`succeeded`, `partially_succeeded`, `failed`, `cancelled` — computed from
child task states, not set directly).

`DeploymentTask` fields: `deployment_id`, `deployment_target_id`,
`commit_hash` (denormalized for convenience), `status` (`queued`,
`running`, `succeeded`, `failed`, `cancelled`), `attempt_number`,
`cancel_requested` (bool), `started_at`, `finished_at`,
`last_activity_at` (drives the existing stall-detector, now per-task),
`failure_message`.

### 10.2 Claiming (unchanged mechanics, new scope)

`claimNextJob` becomes "claim the oldest queued `DeploymentTask` whose
`deployment_target.server_id` matches the authenticated Agent's
`server_id`" — same atomic `lockForUpdate()` pattern as today, same
signed-job-payload guarantee, just one relation deeper.

### 10.3 Failure independence (D8)

A failed `DeploymentTask` does **not** cancel its siblings in the same
`Deployment`. Each target runs to its own conclusion. The parent
`Deployment.status` is computed once all tasks reach a terminal state:
`succeeded` only if every task succeeded, `failed` only if every task
failed, `partially_succeeded` otherwise. This is deliberately more
informative than a binary pass/fail for a 3-server rollout.

### 10.4 Locking (D7)

Lock scope is the **Environment**, not the target and not the whole
system. Mechanism: a nullable `locked_by_deployment_id` + `locked_at` on
`Environment`, acquired the moment the *first* `DeploymentTask` of a
`Deployment` is claimed, released once *every* task belonging to that
`Deployment` reaches a terminal state. A second `Deployment` for the same
Environment can still be *created* while one is in flight (nothing is
silently dropped) — its tasks simply are not claimable until the lock
clears, enforced at the exact same claim boundary described in §10.2.
**Lock timeout and recovery both reuse the existing
`deployments:detect-stalled` scheduled command** (widened to also release
the Environment lock when it fails a stuck Deployment) — no new lock
service, no new dependency, and the lock survives an app-process crash
for free because it's just a database row, not an in-memory or external
lock.

### 10.5 Cancellation (D9)

Cancelling a `queued` task is immediate (never dispatched). Cancelling a
`running` task can only ever be a **request**: the Hub sets
`cancel_requested = true`; the agent notices it on its next poll/log
report and self-terminates its own running child process, then reports
`cancelled`. This is an explicit, accepted latency (bounded by the poll
interval) — not a shortcut, but the direct consequence of never allowing
an inbound connection to an agent, which remains non-negotiable.

### 10.6 Retry (D10)

Auto-retry is **off by default**, opt-in per Environment via
`max_attempts` (default 1). Rationale: unlike the Phase 1.5 webhook-
processing queue job (which is safely idempotent — creating the same
Deployment row twice is harmless because of DB constraints), a
deployment pipeline (`git reset --hard && migrate && ...`) is not
guaranteed safe to blindly re-run — a half-applied migration re-run
automatically could do real damage. Manual retry (an operator explicitly
re-triggering one failed task) is always available regardless of the
auto-retry setting and is the recommended default path.

### 10.7 Timeout (two independent controls)

- **No-activity timeout** (existing, Phase 1.5): fails a task if nothing
  has been reported for N minutes — catches a crashed/disconnected
  agent.
- **Max-duration timeout** (new): fails a task if it's been running
  longer than a configured ceiling **even while actively reporting** —
  catches a runaway step (e.g. an infinite-output build loop) that the
  no-activity check alone would never catch.

### 10.8 Log streaming (D12)

Today's step-boundary log posting stays, plus: for long-running steps,
the agent posts **incremental output chunks** periodically (time- or
size-based) instead of waiting for the step to finish, and the dashboard
short-polls (2–3s) the task's log endpoint for near-real-time tailing.
No websocket/SSE dependency is introduced; this is the same HTTP-only
shape the whole system already uses, just called more often for long
steps.

## 11. Security Review (of this design, against the unchanged model)

| Control | Effect of this design |
|---|---|
| Agent authentication | Unchanged mechanism, re-keyed from Server to Agent. No weakening. |
| Registration bootstrap | New, narrower-trust endpoint. Mitigations: single-use token (consumed atomically, replay rejected the same way nonces are today), short expiry (~30 min), hashed at rest, **and the registration request body is HMAC-signed using the registration token itself as the key** — reusing `RequestSigner`'s exact canonical-string scheme rather than falling back to a bare bearer check, so this endpoint isn't meaningfully weaker than steady-state calls, just shorter-lived. It can only ever create a `pending_approval` row — never anything privileged. |
| Token lifecycle | `agent_token`/`signing_secret` now minted at **approval**, not registration — a pending agent holds no long-lived credential at all. Disabling/retiring an Agent must be checked in the same auth middleware path that checks `is_active` today (extended to require `status = approved`). |
| Job signature | Unchanged `JobPayloadSigner` scheme; signing key now sourced from `Agent.signing_secret`; the signed identifier moves from `project_key` to `target_key`. |
| Replay protection | Unchanged (timestamp window + one-time nonce); nonce table keys on `agent_id` instead of `server_id`. |
| Authorization boundary | Same shape of check, one relation deeper: an Agent may only act on a `DeploymentTask` whose `deployment_target.server_id` equals its own `server_id`. |
| Secret handling | Unaffected; the new `registration_token` follows the exact same discipline as `agent_token` (hashed, never logged, shown once). |

No new vulnerability class is introduced. The one genuinely new trust
boundary (registration bootstrap) is deliberately narrowed to the
smallest possible privilege (create-a-pending-row-only) and held to the
same signing rigor as everything else, rather than being a weaker
bearer-only exception.

## 12. Agent Update Architecture (design only — no implementation)

- **Version check**: reported every heartbeat (already true); compared
  against a configured "current release" value. Purely informational —
  surfaced as an "outdated" badge in the dashboard (§13). No forced
  action in Phase 2/3.
- **Update availability**: a conceptual `GET /api/agent/latest-release`
  the agent *may* poll, returning `{version, download_url, checksum,
  signature}` — informational only; nothing in this phase acts on it
  automatically.
- **Package signature validation**: any future auto-update must verify a
  signature made with a **release-signing keypair that is entirely
  separate from any agent's `signing_secret`** (private key never leaves
  the release-build process). Checksum alone is insufficient — an
  attacker controlling the distribution channel could recompute a
  checksum for a malicious file; only a signature against a fixed,
  agent-embedded public key actually proves provenance.
- **Rollback version**: the agent (whenever this is built) must retain
  its previous binary/version on disk and revert to it automatically if
  the first heartbeat after updating fails — a local decision the agent
  makes itself, requiring no additional Hub round-trip.

No auto-update code is proposed or written in Phase 2 or 3 by default;
this section exists purely so the eventual implementation has a
signature-first design to build against instead of inventing one under
time pressure later.

## 13. Dashboard Architecture (design only)

- **Agent Management**: list (name, status chip incl. computed
  online/offline, version + "outdated" badge from §12, OS, architecture,
  capability chips, last heartbeat relative-time) · a dedicated
  **Pending Approvals** queue (the new operator workflow step from §5) ·
  detail view (full capability JSON, registration/approval audit trail,
  rotate credentials, disable/re-approve).
- **Server Management**: list (name, description, its current Agent +
  count of retired ones, which Environments/Targets reference it,
  active/decommissioned) · detail (cross-cutting deployment history
  across every Environment/Target that references this Server — useful
  precisely because one box can now serve more than one Project).
- **Deployment Dashboard**: active deployments (Environment, commit,
  aggregate status, "2/3 targets done" progress) · detail (per-target
  grid — each `DeploymentTarget` as its own row/card with its own
  status/current-step/log tail) · rollback action (reuses the §9 target
  checklist) · near-real-time log tail per §10.8.

No Blade/view code is written in this phase — this is the field/relationship
inventory Phase 3 implementation needs, at the same level of detail the
Phase 1 blueprint used successfully for this same purpose.

## 14. Testing Strategy

The existing 37 tests are untouched by this phase (no code changed) and
remain the regression baseline Phase 3 must keep green throughout
implementation.

**New tests required in Phase 3**, grouped as specified:

*Agent*
- Registration flow: valid token succeeds; expired/reused/invalid token
  rejected; registration request without a valid HMAC signature (using
  the registration token as key) rejected.
- Approval flow: pending → approved issues credentials exactly once;
  approving a new Agent for a Server retires the previous one; disabled/
  retired Agents fail authentication the same way an unknown one does.
- Heartbeat metadata: capabilities/os/architecture persisted verbatim;
  `ip_address` is taken from the request, not from any client-supplied
  field (a test asserting a spoofed `ip` in the body is ignored).
- Capability validation: Deployment creation blocked (with an audit
  entry) when any selected target's Agent is missing a step's `requires`
  entry; allowed when satisfied.
- Token lifecycle: rotation invalidates the previous credential
  immediately; disabling an Agent invalidates it immediately.

*Deployment*
- Multi-target deployment: one `DeploymentTask` per selected active
  target; aggregate status computed correctly for all-succeed/all-fail/
  mixed cases.
- Deployment locking: a second Deployment for a locked Environment isn't
  claimable until the first reaches a terminal state; stall-detection
  releases the lock.
- Job retry: manual retry re-attempts only the failed task, never its
  siblings; auto-retry (when enabled) respects `max_attempts` and
  backoff.
- Failed execution: one target's failure doesn't cancel or block
  siblings (§10.3) — explicit test for the fail-independent behavior.
- Rollback: defaults to the Environment's *current* active target list
  (not the original deployment's historical one); supports an explicit
  target subset, exercised the same way a manual deploy's checklist is.

## 15. Changed Files

Only this document was created in Phase 2:

```
docs/PHASE_2_AGENT_V2_REPORT.md
```

(`docs/PHASE_2_AUDIT.md` was produced and committed in the prior turn as
the pre-work audit; it is referenced, not modified, here.)

No migration, model, controller, route, view, or test was added or
changed. `saas-platform` was not read or touched. No dependency was
added to either `composer.json` or `package.json`.

## 16. Remaining Risks

- **Existing-data migration path**: `v0.1.0` already has real `Project`/
  `Server` rows with live webhook URLs and agent `config.php` files in
  the field. Phase 3's migration must backfill each existing Project into
  a default Environment (e.g. "production") and its `server_id` into a
  single `DeploymentTarget`, and should keep the *old* webhook URL
  pattern resolvable (alias/redirect) during a transition window so nobody's
  already-configured GitHub webhook breaks the day this ships.
- **Registration bootstrap remains the lowest-assurance step** in an
  otherwise fully-signed system, by necessity (the agent has no
  long-lived secret yet). It's narrowed as far as it reasonably can be
  (single-use, short-lived, signed, create-pending-only) but is not
  zero-risk, and should be documented as such for operators (don't leave
  a registration token lying around, don't skip reviewing what a pending
  agent claims to be before approving it).
- **Cancellation latency** (§10.5) is an accepted, bounded delay, not a
  bug — but it needs to be communicated clearly in the dashboard UI
  ("cancellation requested, waiting for agent to acknowledge") so an
  operator doesn't assume a click means instant termination.
- **Auto-retry, if an Environment ever opts in**, still assumes pipeline
  steps are reasonably idempotent; that assumption isn't verified by the
  system and can't be — it's a per-project operational responsibility to
  document for whoever writes that Environment's pipeline.
- **Capability matching is presence/equality only** — a project that
  genuinely needs a minimum version range (e.g. PHP ≥ 8.2) isn't
  protected by this v1; it either declares the exact value or accepts
  the gap until semver-range matching is built.
- **Log "streaming" is still polling under the hood** — acceptable for
  the 2–3s granularity proposed, but if a future requirement demands
  sub-second live output, this design would need revisiting (websocket/SSE),
  which was deliberately not introduced here to avoid a new dependency
  without a proven need for it.

## 17. Recommendation for Phase 3

Suggested implementation order, each stage keeping the existing 37 tests
green and adding its own before moving to the next:

1. **Agent entity + registration/approval + heartbeat metadata** —
   foundational; everything else depends on Agent existing and being
   trustworthy first.
2. **Environment + DeploymentTarget + the Project backfill migration** —
   including the webhook-URL transition plan from §16, since this is the
   riskiest migration against live data.
3. **Deployment/DeploymentTask split + claiming + capability matching** —
   the core multi-target execution path.
4. **Environment locking + stall-detection widening + retry/cancel/
   timeout refinements**.
5. **Dashboard views** (§13) — last, once the data model underneath it is
   stable, to avoid building UI against a shape that's still moving.
6. **Explicitly deferred past Phase 3**: agent auto-update implementation
   (§12) and true push-based log streaming (§10.8/§16) — both are
   designed above so they aren't blocked later, but neither is justified
   yet by a concrete, present need.
