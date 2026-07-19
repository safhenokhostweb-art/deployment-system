# Phase 3-B — Agent Identity & Lifecycle Foundation (Audit + Design Only)

Audit and design only — this document is the entire Phase 3-B
deliverable. No migration was written or run, no model/controller/route
changed, no currently-deployed agent is affected in any way described
below. `saas-platform` was not read or touched. Builds directly on
`docs/PHASE_2_AGENT_V2_REPORT.md` §5/§6 and
`docs/PHASE_3A_DOMAIN_MIGRATION_AUDIT.md` §5(B)/§6 — this report makes
those two design points fully concrete and adds the one thing they
didn't yet specify: **how the authentication code path itself transitions
without a cutover moment.**

## 1. Executive Summary

Phase 3-A answered "what data moves from Server to Agent." It didn't yet
answer "what does the *auth middleware* do on the day the `agents` table
exists but most agents haven't been migrated into it yet." That gap is
what this document closes.

The answer, in one sentence: **the middleware tries an Agent lookup
first and falls back to today's Server lookup, unchanged, if no Agent
row matches** — so shipping the Agent table and the new registration
flow is, by construction, a no-op for every currently-deployed agent
until an operator deliberately opts one in. Nothing is cut over on a
schedule; nothing is invalidated by default; the system runs both models
side by side indefinitely if that's ever what's needed.

## 2. Task 1 — Audit: Current Agent/Server Authentication

Re-verified directly against the repository for this document (full
domain-wide audit already on record in `PHASE_3A_DOMAIN_MIGRATION_AUDIT.md`
§2–§3; this is the narrower, auth-specific slice):

- **Server token**: `Server.agent_token_hash` (SHA-256, one-way — bearer
  comparison only) and `Server.signing_secret` (Laravel `encrypted` cast
  — reversible, because it's used as an HMAC *key*, not compared as a
  hash). Both minted together at `ServerController::store`/`rotateToken`,
  shown exactly once.
- **HMAC flow**: `RequestSigner::sign()`/`verify()` — canonical string
  `METHOD\npath\ntimestamp\nnonce\nsha256(body)`, HMAC-SHA256 keyed by
  `signing_secret`, checked with `hash_equals`. Path is normalized to a
  leading slash on both sides before signing/verifying.
- **Agent request signing**: `agent/lib/Signer.php` mirrors
  `RequestSigner` byte-for-byte — this equivalence is a hard invariant
  any change here must preserve exactly, on both sides at once.
- **Wire shape today** (`agent/lib/Client.php`): `Authorization: Bearer
  <agent_token>`, `X-Deployment-Server-Id: <server public_id>`,
  `X-Deployment-Timestamp`, `X-Deployment-Nonce`, `X-Deployment-Signature`.
  Verified in `VerifyAgentRequestSignature`, which resolves identity via
  `Server::where('public_id', $header)->where('is_active', true)`.
- **`config.php` agent setup**: flat `server_id` / `agent_token` /
  `signing_secret` fields — no concept of "agent" distinct from "server"
  exists on disk for any already-installed agent. This exact shape, on
  whatever boxes already run it, is what must keep working.
- **Webhook compatibility**: independent concern (`project_key`-routed),
  unaffected by anything in this document — Phase 3-A's "reuse existing
  identifier values" principle applies there separately and isn't
  revisited here.

## 3. Task 2 — Agent Identity Design (no final migration)

### 3.1 Shape

`Server hasMany Agent` (unchanged decision from Phase 2 §6.2). **In this
phase specifically, `Server` is not modified at all** — its existing
credential columns (`agent_token_hash`, `signing_secret`, `agent_version`,
`last_seen_at`) stay exactly as they are, untouched and unread-only,
because Task rules forbid invalidating any current agent and this is the
most conservative way to guarantee that: nothing about `Server` changes,
so nothing that already depends on it can break. `Agent` is purely
additive (see 3-B.1, §7).

### 3.2 The dual-lookup resolution rule (the actual transition mechanism)

This is what Phase 3-A did not yet specify. Proposed middleware logic
(for 3-B.1, not built now):

```
resolve(header_public_id):
    agent = Agent.where(public_id = header_public_id).first()
    if agent exists:
        return AuthenticateAs(agent)        # "New Agent" path
    server = Server.where(public_id = header_public_id, is_active = true).first()
    if server exists:
        return AuthenticateAs(server)       # "Old Agent" path — today's exact code, untouched
    return Unauthorized
```

Both branches then run through the *same* HMAC verification and replay
protection they do today — only which table supplies the token
hash/signing secret differs. Because the second branch is today's
existing, unmodified logic, and the first branch simply doesn't match
anything until an Agent row is deliberately created, **shipping this
change is provably inert for every agent that exists the day it ships.**

### 3.3 Public_id namespace

Both `Agent.public_id` and `Server.public_id` are UUIDs drawn from the
same practically-collision-free space; a genuinely new registration
mints a fresh UUID (§4), and a *promoted* old agent (§5, §7's 3-B.3)
deliberately reuses its Server's existing UUID by design, not by
accident. No new uniqueness machinery is needed — this is a validated
invariant, not an open risk.

### 3.4 Gradual credential ownership transfer

"Gradual" is answered structurally, not procedurally: ownership of a
given Server's credentials moves from `Server` to `Agent` at the exact
moment (if ever) an `Agent` row referencing it is created — a per-server,
operator-initiated, fully reversible action (delete the Agent row to
revert), never a global switch, never on a schedule. §5 and §7 (3-B.3)
describe the optional tooling for that; this section only establishes
that the *mechanism* (§3.2) is what makes "gradual" actually safe rather
than aspirational.

## 4. Task 3 — Registration Flow Design

```
Admin creates Server row (unchanged, existing flow — still just a label)
        │
        ▼
Admin clicks "Generate registration token" for that Server (new)
        │
        ▼
Agent row created immediately: status = pending_approval,
server_id set, name/os/architecture/capabilities = null,
registration_token generated, encrypted at rest (see below),
registration_token_expires_at = now + 30 min (configurable)
        │  {registration_id, registration_token} shown once,
        │  handed to whoever provisions the box (out-of-band, same
        │  trust model as today's credential hand-off)
        ▼
Agent installer calls POST /api/agent/register
{
  "registration_id": "…",       // = the Agent row's public_id
  "name": "web-04-agent",
  "os": "linux",
  "architecture": "x86_64",
  "capabilities": { "php": "8.4", "composer": true, "docker": true,
                     "queue": true, "extensions": ["pdo", "redis"] }
}
signed with registration_token as the HMAC key, same canonical-string
scheme as RequestSigner, distinct header names
(X-Deployment-Registration-Timestamp/-Nonce/-Signature) so this can
never be confused with a steady-state signed call
        │
        ▼
Hub looks up the pending Agent by registration_id, verifies the
signature against its stored (encrypted, reversible) registration_token,
checks not-expired, checks not-already-consumed
        │
        ▼
Agent row updated: name/os/architecture/capabilities filled in from the
request; registration_token cleared immediately (single-use — a cleared
token can never verify again, there's nothing left to HMAC against);
status stays pending_approval
        │  response: {"status": "pending_approval"}
        ▼
┌───────────────────────────────────────────────────────────┐
│ Dashboard: Pending Approvals queue — operator reviews the  │
│ self-reported name/OS/arch/capabilities                    │
└───────────────────────────────────────────────────────────┘
        │  Approve                              │  Reject
        ▼                                        ▼
agent_token + signing_secret generated,     Agent row deleted,
shown once, status = approved,              audit-logged
approved_by/approved_at recorded
        │
        ▼
Steady-state heartbeat / job-poll / log / status — identical mechanics
to today, resolved via §3.2's Agent branch
```

**Corrected detail versus a naive design**: `registration_token` must be
stored **encrypted (reversible)**, not hashed — unlike `agent_token`
(which is only ever *compared*), the registration token is used as an
HMAC signing key during verification, so the Hub needs the plaintext
back. This mirrors exactly the existing `agent_token` (hashed, bearer) vs
`signing_secret` (encrypted, HMAC key) split already in the codebase —
the registration token behaves like a short-lived `signing_secret`, not
like `agent_token`.

**Expiry/failure handling**: an expired or already-consumed token is
rejected with a specific error; regenerating a token for the same
pending Agent row simply overwrites its `registration_token`/expiry in
place — no need to create a new row or discard self-reported metadata
that hasn't arrived yet.

## 5. Task 4 — Heartbeat Evolution (target shape, no schema change yet)

Current payload (unchanged in this phase): `{agent_version}`, stored on
`Server.agent_version`.

**Target payload**, to be implemented in 3-B.2 once the Agent table
exists:

```json
{ "agent_version": "1.2.0", "os": "linux", "architecture": "x86_64",
  "capabilities": { "php": "8.4", "composer": true, "docker": true,
                     "queue": true, "extensions": ["pdo", "redis"] } }
```

`ip_address` is deliberately **not** part of the request body — it is
captured server-side from the connection itself (`$request->ip()`),
never trusted from client-supplied data, exactly per Phase 2 §6.2's
identity design. `last_seen_at` continues to update on *any*
authenticated call (unchanged mechanism, just now also true for the
Agent branch of §3.2).

## 6. Task 5 — Backward Compatibility: Old Agent + New Agent, No Downtime

| | Old Agent | New Agent |
|---|---|---|
| Identity source | `Server.public_id`, reused as-is | `Agent.public_id`, freshly minted at registration |
| Credentials | `Server.agent_token_hash`/`signing_secret`, untouched | `Agent.agent_token_hash`/`signing_secret`, issued at approval |
| Resolution path | §3.2's fallback branch — today's exact, unmodified code | §3.2's primary branch |
| Config on disk | Today's flat `config.php` (`server_id`/`agent_token`/`signing_secret`) — **zero changes required, ever** | Same shape, values sourced from the registration+approval flow instead of a Server row |
| Wire header | `X-Deployment-Server-Id` (name unchanged, per Phase 3-A's recommendation not to rename it) | Identical header name, carrying the Agent's own public_id |

**Rollout order that requires no maintenance window**:

1. Ship §3.2's dual-lookup + an empty `agents` table. Every existing
   agent is still 100% "Old Agent"; behavior is byte-for-byte identical
   to today, verified by construction (fallback branch = today's only
   branch).
2. *Optionally*, per server, an operator may "promote" an old agent by
   running a one-off backfill for that one Server (creates its `Agent`
   row reusing its `public_id`/credentials verbatim, per Phase 3-A §5B)
   — entirely reversible, entirely optional, never required.
3. Brand-new servers, from this point forward, are provisioned through
   `Register → Pending Approval → Approved` exclusively.

At no point is a coordinated, simultaneous action across every deployed
agent required — the two paths coexist indefinitely in the same
middleware, which is what makes "no downtime" a structural property of
this design rather than a scheduling promise.

## 7. Task 6 — Security Review

| Control | Design |
|---|---|
| Registration token | Encrypted at rest (reversible, needed as an HMAC key — see §4's corrected detail), single-use (cleared atomically on first successful use, same DB-transaction discipline already proven for nonce consumption — preventing a race between two near-simultaneous registration attempts), short-lived (default 30 min, configurable). Its endpoint can only ever move one Agent row into `pending_approval` — no path to `approved`, no path to touching any other row, no read access to anything. A leaked token's worst case is a fabricated pending-approval entry a human reviews before it ever gains a real credential — never a direct path to production access. |
| Credential issuance | Unchanged discipline: shown once, hashed (`agent_token`)/encrypted (`signing_secret`) at rest, minted only at human approval — a pending, never-approved Agent holds no working credential at all. |
| Token rotation | Existing per-Server rotation (Phase 1.5) stays available for Old Agents; per-Agent rotation is added for New Agents. Both must coexist during the transition — another concrete instance of §6's dual-model design, not a special case. |
| Replay protection | The registration handshake reuses the *same* mechanism as steady-state calls — no new primitive. A registration nonce is tracked in the existing `agent_request_nonces` table, keyed by the pending Agent's id, so a captured registration request is exactly as replay-proof as a captured heartbeat. |

No new vulnerability class is introduced. The one genuinely new surface
(registration) is deliberately the narrowest-privilege, shortest-lived,
most heavily-audited entry point in the system — consistent with, and
now fully concretizing, Phase 2 §11's original intent.

## 8. Task 7 — Implementation Plan

| Stage | Scope | Why this order |
|---|---|---|
| **3-B.1** | Additive `agents` table (Server untouched); dual-lookup middleware (§3.2). Zero registered Agents at ship time. | Provably a no-op for every current agent — the safest possible first slice, verifiable in production before anything depends on it. |
| **3-B.2** | `POST /api/agent/register`, Pending Approvals dashboard queue, approve/reject actions, credential issuance, expanded heartbeat payload (§5). | Only brand-new or deliberately-promoted agents touch this path; every Old Agent remains entirely on 3-B.1's fallback, unaffected. |
| **3-B.3** | Optional one-off "promote this Server into an Agent" tooling (§6, step 2); rotation-UI parity for Agent-based credentials. | Gives operators the *ability* to migrate individual servers at their own pace — still optional, still zero-downtime, still not the full domain cutover (that remains Phase 3-A's Phase 3-D, out of scope here). |

Each stage keeps the existing 37 tests green and adds its own; none of
the three stages touch `Deployment`, `Project`, or webhook code at all —
satisfying "هیچ Deployment تغییر نکند" by scope, not just by discipline.

## 9. Risks and Recommendations

- The dual-lookup rule (§3.2) is the load-bearing piece of this entire
  design — it must be the *first* thing implemented and tested in 3-B.1,
  with an explicit regression test proving every existing
  Security/Agent-flow test still passes unmodified once it ships, before
  any registration/approval code is written on top of it.
- Storing `registration_token` encrypted (not hashed) is a deliberate,
  necessary deviation from the `agent_token` pattern — flagging it
  explicitly here so it isn't "corrected" to a hash by habit during
  implementation and silently broken.
- Recommend the "promote this Server" tooling (3-B.3) log an audit entry
  distinct from ordinary Agent approval (e.g. `agent.promoted_from_server`)
  so the operational history of *which* agents were grandfathered versus
  registered fresh stays visible long after the fact.
- This document intentionally does not revisit Phase 3-A's Phase 3-D
  (the eventual full cutover + deprecated-column cleanup) — that remains
  a separate, later decision, gated on Phase 3-B and Phase 3-C (from
  Phase 3-A's roadmap) both being complete and stable first.
