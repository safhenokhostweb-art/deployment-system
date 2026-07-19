# Phase 3-B Implementation — Agent Identity Foundation

Real implementation of the architecture confirmed in
`docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md`. No real data migration was run,
no existing Server was invalidated, no webhook or Deployment behavior
changed, and every one of the 37 pre-existing tests passes unmodified.
`saas-platform` was not read or touched, and no new Composer/npm
dependency was added.

## 1. Pre Audit

Confirmed immediately before writing any code (unchanged since
`PHASE_3B_AGENT_IDENTITY_AUDIT.md` — nothing in the codebase had moved):

- **Server structure**: `agent_token_hash` (hashed), `signing_secret`
  (encrypted), `agent_version`, `last_seen_at`, `is_active` — the sole
  credential holder.
- **Middleware**: `VerifyAgentRequestSignature` resolved identity via
  `Server::where('public_id', $header)` only; single code path.
- **Routes**: `agent.signed`-protected group had `heartbeat`,
  `jobs/next`, `jobs/{deployment}/status`, `jobs/{deployment}/log` — no
  registration endpoint existed.
- **Heartbeat**: accepted and stored `agent_version` only.
- **Token storage**: `agent_token` hashed (SHA-256, one-way);
  `signing_secret` Laravel-encrypted (reversible, used as an HMAC key).
- **config.php compatibility**: flat `server_id`/`agent_token`/
  `signing_secret` — no "agent" concept on disk anywhere.
- **Test coverage**: 37 tests, including explicit HMAC/replay/
  authorization-boundary coverage in `AgentJobFlowTest` and
  `AgentHeartbeatTest` — none of which construct or reference any
  Agent-shaped entity, confirming they were all, structurally, tests of
  a single-path system.

## 2. Architecture Implemented

```
Server (1) ──< (N) Agent
```

- **`Agent`** (`app/Models/Agent.php`): `public_id` (independent UUID —
  can be seeded from a Server's own `public_id` for a future migrated
  agent, but never auto-derived), `server_id`, `status`
  (`pending_approval`/`approved`/`disabled`), `name`, `agent_version`,
  `os`, `architecture`, `ip_address` (server-captured only),
  `capabilities` (json), `agent_token_hash`, `signing_secret`
  (encrypted), `registration_token` (**encrypted, not hashed** — it's
  used as an HMAC key during verification, the same role
  `signing_secret` plays), `registration_token_expires_at`,
  `last_seen_at`, `approved_by`/`approved_at`.
- **Lifecycle implemented exactly as designed**: an admin issues a
  one-time registration token for a Server → the agent installer calls
  `POST /api/agent/register` (signed with that token, own header names,
  own nonce-based replay protection) → the Agent row fills in
  name/os/architecture/capabilities, stays `pending_approval` → an admin
  reviews and approves (issuing the real `agent_token`/`signing_secret`,
  shown once) or rejects (deletes the row) → approved agents heartbeat,
  poll, execute, and report exactly like a legacy Server does today.
- **Dual-lookup resolution** (`VerifyAgentRequestSignature`): the header
  value is looked up against `Agent` first; if no row matches at all,
  the code falls through to **exactly today's Server lookup, unchanged
  line-for-line**. If an Agent row *does* match but isn't `approved`,
  the request is rejected outright — it never falls through to Server,
  even if a Server with the same `public_id` exists (tested explicitly,
  §7).
- **One claim path, unmodified**: because the middleware resolves
  `deployment_agent_server` to the Agent's own `server` relation on the
  new path, every existing controller method (`next`, `updateStatus`,
  `appendLog`) required **zero code changes** — they only ever consumed
  that one attribute, and now transparently receive the right Server
  either way.

## 3. Files Changed

**New:**
```
service/database/migrations/2026_07_19_090000_create_agents_table.php
service/app/Models/Agent.php
service/database/factories/AgentFactory.php
service/app/Http/Controllers/Api/AgentRegistrationController.php
service/app/Http/Controllers/Dashboard/AgentController.php
service/resources/views/agents/index.blade.php
service/resources/views/agents/pending.blade.php
service/resources/views/agents/show.blade.php
service/tests/Feature/Api/AgentRegistrationTest.php
service/tests/Feature/Api/AgentAuthResolutionTest.php
service/tests/Feature/Dashboard/AgentApprovalTest.php
docs/PHASE_3B_IMPLEMENTATION_REPORT.md
```

**Modified:**
```
service/app/Models/Server.php                        (+agents() relation only)
service/app/Models/AgentRequestNonce.php              (+agent_id fillable only)
service/app/Services/Security/AgentCredentialService.php (+generateRegistrationToken())
service/app/Http/Middleware/VerifyAgentRequestSignature.php (dual-lookup — see §2)
service/app/Http/Controllers/Api/AgentJobController.php  (heartbeat: agent-aware branch added, legacy branch untouched)
service/routes/api.php                                (+POST /api/agent/register)
service/routes/web.php                                (+agent dashboard routes)
service/resources/views/layouts/app.blade.php         (+"Agents" nav link)
service/resources/views/servers/show.blade.php        (+additive "Agents on this server" card)
service/config/deployment.php                         (+agent_registration_token_ttl_minutes)
service/tests/Support/SignsAgentRequests.php          (+registrationHeaders() helper)
service/tests/Feature/Api/AgentHeartbeatTest.php       (extended, no existing test changed)
```

No file under `Project`, `Deployment`, `DeploymentDispatcher`,
`RollbackService`, `GitHubWebhookController`, or any webhook-related code
was touched.

## 4. Database Changes

- **`agents`** (new table) — see §2 for columns. `server_id` →
  `restrictOnDelete()` (consistent with the rest of the schema's
  no-implicit-cascade convention).
- **`agent_request_nonces`**: one new nullable `agent_id` column (FK,
  `nullOnDelete()`). **The existing `unique(server_id, nonce)` constraint
  was not touched.** It already provides correct replay protection for
  both paths unmodified, because `server_id` is always populated in
  either case (an agent-authenticated request resolves it via
  `$agent->server_id`) — `agent_id` is purely for audit traceability
  (which rows came from which path), not load-bearing for the replay
  guarantee itself. This is why no `->change()`/column-alteration was
  needed anywhere, avoiding any dependency on `doctrine/dbal`.
- **No column was added to, removed from, or altered on `servers`,
  `projects`, or `deployments`.**

## 5. Compatibility Strategy (as built)

| | Old Agent | New Agent |
|---|---|---|
| Identity | `Server.public_id`, untouched | `Agent.public_id`, freshly minted at registration |
| Credentials | `Server.agent_token_hash`/`signing_secret`, untouched | `Agent.agent_token_hash`/`signing_secret`, issued at approval |
| Resolution | Middleware's fallback branch — today's exact code | Middleware's primary branch |
| `config.php` | **Zero changes required** | Same flat shape, values from registration+approval instead |
| Wire header | `X-Deployment-Server-Id` (name unchanged) | Identical header, carrying the Agent's own `public_id` |

Verified, not just asserted: `AgentAuthResolutionTest::
test_legacy_server_with_no_agent_row_still_authenticates_exactly_as_before`
exercises a bare Server with zero Agent rows in existence — the same
shape every one of the 37 pre-existing tests already used — and the
full pre-existing suite passing unmodified is itself the regression
proof that nothing about the Old Agent path changed.

## 6. Security Review

| Control | Implementation |
|---|---|
| Registration token | Stored via Laravel's `encrypted` cast (reversible — confirmed necessary because it's used as an HMAC signing key, not compared as a hash). Single-use: cleared (`registration_token = null`) the instant registration succeeds — a replayed attempt has nothing left to verify against (tested: `test_registration_token_cannot_be_replayed`). Expiring (default 30 min, `deployment.agent_registration_token_ttl_minutes`; tested: `test_expired_registration_token_is_rejected`). Fully audited: `agent.registration_token_issued`, `agent.registered`, `agent.registration_rejected` all write `AuditLogEntry` rows. |
| Credential issuance | `agent_token`/`signing_secret` are minted only at **approval**, never at registration — a pending agent holds no working credential at all, confirmed by the registration endpoint never touching those columns. |
| Token rotation | Not modified in this phase; `ServerController::rotateToken` continues to serve legacy Server rotation. Agent-side rotation UI was not built in Phase 3-B (out of original scope — flagged in §9). |
| Authentication (HMAC + replay) | Identical `RequestSigner` algorithm on both paths; `hash_equals` throughout; the registration handshake reuses the exact same algorithm keyed by the registration token, on its own header names, with its own nonce entries in the same `agent_request_nonces` table (tested: `test_replay_protection_applies_identically_on_the_agent_path`, plus dedicated registration-nonce coverage). |
| Authorization ("only Admin can approve") | Every dashboard Agent route sits behind the existing `auth` middleware group — identical authorization model to Server/Project management today (there is no separate admin-vs-user role anywhere in this codebase; "only Admin" is satisfied at the level of "only an authenticated dashboard user," consistent with every other privileged action in the system). Verified: `test_guest_cannot_approve_agents` (redirects to login, status unchanged). |
| Disabled-agent bypass | **Explicitly tested**, not just designed: `test_disabled_agent_is_rejected_even_if_a_server_shares_its_public_id` creates an `Agent` (disabled) and a `Server` with the *same* `public_id` and confirms **both** the agent's own (disabled) credentials **and** the server's still-valid legacy credentials are rejected — proving disabling an Agent can never be bypassed via a same-`public_id` Server fallback, which was the one identified risk in the original audit. |
| Approval-before-registration guard | A gap not called out in the original audit, found and fixed during implementation: approving an Agent before it has ever called `/register` would issue credentials to an entity with no way to receive them (its own `/register` call would subsequently fail, since it only matches `status = pending_approval` rows, and approval already moved it to `approved`). Added `abort_if($agent->name === null, 409, ...)` in `AgentController::approve()`, with a corresponding test (`test_cannot_approve_an_agent_that_has_not_completed_registration`). |

No new vulnerability class was introduced. No existing security test's
assertions were weakened or removed.

## 7. Tests Result

```
tests: 57, passed: 57, assertions: 138
```

37 pre-existing tests, unmodified, still passing. 20 new tests added:

- `AgentRegistrationTest` (6): valid registration; replay rejection;
  expiry rejection; unknown registration id; invalid signature; an
  already-approved agent can't be "registered" again.
- `AgentAuthResolutionTest` (5): agent-path authentication and successful
  job claim; pending agent rejected outright; disabled agent rejected
  even with a same-`public_id` Server fallback available; legacy Server
  with no Agent row authenticates exactly as before; replay protection
  on the agent path.
- `AgentHeartbeatTest` (+3 new alongside the 3 pre-existing): legacy
  heartbeat ignores unexpected new fields; new-agent heartbeat records
  full metadata (os/architecture/capabilities/ip); agent `isOnline()`
  threshold behavior.
- `AgentApprovalTest` (6): registration-token issuance; approval issues
  credentials; approval blocked before registration completes; rejection
  deletes the row; disabling changes status; guest cannot approve.

## 8. Migration Notes (Future Only)

No real data migration is part of this phase. For a future phase to
*promote* an existing Server's credentials into a real Agent (fully
optional, never required — see `PHASE_3A_DOMAIN_MIGRATION_AUDIT.md` §5B
and `PHASE_3B_AGENT_IDENTITY_AUDIT.md` §6 step 2), the schema built here
already supports it without any structural change:

- `Agent.public_id` has no uniqueness relationship enforced against
  `Server.public_id` — it is free to be seeded with a Server's existing
  `public_id` value at promotion time, exactly as designed.
- `Agent.agent_token_hash`/`signing_secret` can be copied verbatim from
  the Server being promoted (never regenerated), so the already-deployed
  agent's `config.php` keeps authenticating without modification.
- The recommended promotion action (a dashboard button or console
  command, per the original audit's 3-B.3 slice) was **not built in this
  implementation phase** — it wasn't in this phase's task list, and the
  schema/middleware above is already sufficient to support it whenever
  it is built.

## 9. Next Phase Recommendation

- Build the optional "promote this Server into an Agent" tool (§8) and
  Agent-side credential rotation UI parity with `ServerController::
  rotateToken` — both were explicitly out of this phase's task list but
  are natural, low-risk additions given the schema already supports
  them.
- Phase 3-C (`PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md`) can now begin its own
  implementation: it depended specifically on a real `Agent` existing for
  `DeploymentTarget → Server → Agent` resolution to mean anything, and
  that dependency is now satisfied.
- Consider surfacing "outdated agent version" as a dashboard badge once
  a notion of "current recommended version" exists (Phase 2 §12) — the
  data (`Agent.agent_version`) is already being collected and displayed,
  only the comparison-and-badge logic is missing.
- The approval-before-registration guard found during this
  implementation (§6) is the kind of edge case worth specifically
  checking for again in Phase 3-C's own review, since the same "can this
  state transition happen out of order" question will recur for
  DeploymentTarget/Environment lifecycle states.
