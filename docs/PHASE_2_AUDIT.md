# Phase 2 Audit — Agent Lifecycle & Multi-Server Architecture

Scope: `c:\laragon\www\deployment-system` only, read-only review — no code
changed for this document. Baseline: `v0.1.0-mvp-complete` plus the
Phase 1.5 hardening commit.

## 1. Agent lifecycle (as it exists today)

```
Admin creates Server row (dashboard)
        │
        ▼
agent_token + signing_secret generated, shown once
        │
        ▼
Admin manually pastes them into agent/config.php on the target box
        │
        ▼
Agent starts polling — fully trusted from its very first request
```

There is no step where the **agent itself** contacts the Hub to say "a
new agent exists, please register me." Trust is established entirely
out-of-band: an admin creates the `Server` row first, and whoever holds
the resulting credentials *is* the agent, permanently, until credentials
are rotated or the server is deactivated. Creating the row **is** the
approval — there is no separate pending → approved transition.

This is materially different from the lifecycle in the Phase 2 brief
(`Register → Approve → Heartbeat → Receive Job → Execute → Report`),
where the agent initiates registration and a human approves it
afterward.

## 2. Registration flow

- `POST /servers` (dashboard, human-authenticated) is the only way a
  `Server` row is created. `App\Http\Controllers\Dashboard\ServerController::store`.
- No agent-facing registration endpoint exists in `routes/api.php` today.
- No pending/unapproved state: `is_active` defaults to `true` at creation
  — a registered server can authenticate immediately.

## 3. Authentication

Unchanged from Phase 1.5 and still sound (re-confirmed, not re-litigated
here — see `docs/PHASE_1.5_HARDENING_REPORT.md` §7):

- Bearer token, stored only as a SHA-256 hash.
- HMAC request signature over `method + path + timestamp + nonce + body`,
  keyed by a second, separately-encrypted `signing_secret`.
- 300s timestamp window + one-time nonce (DB unique constraint on
  `(server_id, nonce)` — atomic under concurrency).
- Identity carried per-request via the `X-Deployment-Server-Id` header
  (the server's `public_id` UUID). This is already a solid foundation to
  build "Agent Identity" on — the UUID requirement in task 2 is already
  satisfied by `public_id`.

## 4. Heartbeat

- `POST /api/agent/heartbeat` (added in Phase 1.5), behind the same
  signed-request middleware as every other agent call.
- Current payload: `{agent_version}` only.
- **Not currently collected or stored**: OS, architecture, IP address,
  or any capability declaration.
- `last_seen_at` is updated on *any* authenticated call (poll, heartbeat,
  log, status) — this part already matches what a real fleet needs and
  should be kept as-is.

## 5. Job execution

- `AgentJobService::claimNextJob(Server $server)` atomically claims the
  single oldest `queued` `Deployment` where `server_id` matches, inside a
  row-locked transaction — race-free for concurrent polls, already
  tested.
- No concept of agent **capabilities** exists anywhere in the claim path:
  a job is handed to whichever server a `Project` happens to be pinned
  to, with no check for "can this agent actually do what this pipeline
  needs" (e.g. a step invoking `docker` with no verification the target
  agent has Docker at all).
- No explicit concurrency/backlog limit per agent — not a correctness
  issue (an agent only ever holds one job at a time between polls), but
  there's no visibility into, or control over, how many jobs are queued
  up behind a given server.

## 6. Deployment flow

- `Project.server_id` is a **single, mandatory** foreign key —
  `belongsTo(Server::class)`. One `Project` deploys to exactly **one**
  `Server`.
- Webhook, manual, and rollback-triggered deployments all inherit this
  same 1:1 pinning; nothing downstream (`DeploymentDispatcher`,
  `RollbackService`, `AgentJobService`) assumes anything other than a
  single target server per project.
- **This is the central structural gap for "Multi Server Deployment"**:
  there is currently no way to express "deploy this Project to servers
  A, B, and C." The only existing workaround — creating three separate
  `Project` rows pointed at the same repository — duplicates pipeline
  definition, webhook URL/secret, and branch config three times and
  produces three independent (not coordinated) deployment histories. It
  is not multi-server deployment, just repetition.

## 7. Architectural limitations — summary

| Gap | Why it matters |
|---|---|
| No agent-initiated registration / approval step | Every server needs manual admin provisioning before it can ever authenticate; doesn't match the declared `Register → Approve` trust model |
| Only `is_active` (bool) + computed `isOnline()` | Can't distinguish "explicitly disabled by an operator" from "transiently unreachable" from "mid self-update" — three operationally different situations that look identical today |
| No agent identity metadata beyond name/description/version | No OS, architecture, IP, or capability data anywhere — can't answer "what does my fleet actually look like" or make any compatibility decision |
| No capability declaration or matching | Every agent is assumed able to run every pipeline step; nothing stops a job requiring Docker from being handed to an agent without it |
| `Project` → single `Server` (1:1) | Real multi-server/fan-out deployment isn't representable in the data model at all today |
| No per-agent concurrency/backlog visibility | Not a correctness bug, but no control surface if one server's queue backs up |

None of these are security regressions or defects in what already exists
— Phase 1.5's security posture (HMAC, replay protection, token storage,
authorization boundaries) is unaffected and remains sound. These are
scope gaps relative to the Agent v2 / multi-server ambition, which is
exactly what Phase 2 is meant to close.
