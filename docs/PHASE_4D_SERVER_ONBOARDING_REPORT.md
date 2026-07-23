# Phase 4-D — Server Onboarding Wizard Implementation Report

## 1. Executive Summary

The brief's diagram (add server → registration token → download installer → agent connects → admin approves → server ready) describes the Agent-entity lifecycle built in Phase 3-B. **The audit found that lifecycle only exists on the Service side** — the dashboard and `POST /api/agent/register` fully implement it, but the standalone Agent script (`deployment-system/agent/agent.php` + `lib/`) has zero code that ever calls that endpoint, and there is no mechanism at all for an agent to retrieve its `agent_token`/`signing_secret` after an admin approves it (those are shown only to the human, once, today). Making the brief's exact diagram real end-to-end would mean writing new HMAC-signing client code plus a brand-new credential-retrieval API endpoint — real Security-Model-adjacent surface, which the brief explicitly says this phase should not touch.

**Presented to you directly, you chose the option this report implements**: build the Wizard around the Server-level static-credential flow, which is 100% already-working end-to-end with zero new agent-side or API code. "Registration Token" in the brief maps onto the Server's `agent_token`/`signing_secret` pair; "Agent Register" maps onto the agent's first successful poll with those credentials; "Pending Approval" has no equivalent in this flow (credentials work immediately) — instead, "awaiting connection" is shown via the server's already-existing online/offline state, which is a more honest signal anyway (it reflects whether the agent is *actually* running, not just whether someone clicked Approve). The separate Agent sub-entity registration flow (Phase 3-B, still real and useful for multiple agents on one server) is untouched and still available from the Server detail page exactly as before.

163/163 tests pass (149 from before this phase, unmodified, + 14 new). One additive migration was run (nullable `servers.type` column) — reported before executing, per §2.

## 2. Pre-Implementation Audit

**Models:** `Server` had no `type` column (confirmed via its migration) — needed for Step 1's server-type picker. `Agent` already has the full `pending_approval → approved/disabled` lifecycle with `registration_token`/`registration_token_expires_at` (Phase 3-B). No separate "Registration Token" model exists — it's a column on `Agent`. `User` is unchanged and irrelevant beyond `auth`.

**Controllers:** `ServerController::store()` already generates `agent_token`/`signing_secret` immediately on creation and flashes them once via session (Phase 1.5's "shown once" pattern) — this is the complete, working "registration" step for the Server flow. `AgentController` already has the full pending/approve/reject/disable/reenable lifecycle (Phase 3-B/4-B), already Persian-translated. `AgentRegistrationController` (`POST /api/agent/register`) is real and correctly implemented — the gap is entirely on the client side.

**Routes:** `servers.create`/`servers.store`/`servers.show`/`servers.rotate-token` and `agents.*` all already existed and needed no new routes for the chosen flow — the entire "installer" experience is delivered through content already computed in `ServerController::show()`, not a new endpoint (§4).

**Views:** `servers/create.blade.php` was a single flat form (name, description). `servers/show.blade.php` showed credentials-once, server info, agents-on-server, projects-on-server — no server type, no deployment targets, no wizard framing at all.

**The registration-execution gap** (reported to you before any implementation, §1): confirmed via `grep -rli register agent/` returning nothing outside `README.md`/`config.example.php`, and reading `agent/lib/Client.php` — its constructor is hard-wired to `server_id` + `agent_token` + `signing_secret`, with no concept of the separate registration-token protocol `AgentRegistrationController` expects (different header names, different signing key). This is not a bug to fix — Phase 3-B built the Agent sub-entity as an *additive, optional* parallel identity system, and its own client was never part of that phase's scope either.

## 3. Current Server Flow (unchanged by this phase)

```
ServerController::store()
  → generates agent_token (hashed at rest) + signing_secret (encrypted at rest)
  → flashes both plaintext, once, to session
  → redirects to servers.show
ServerController::show()
  → session flash still present → credentials box renders (Phase 1.5)
  → agent operator copies server_id/agent_token/signing_secret into agent/config.php
  → agent.php runs (systemd/cron), polls with those static credentials
  → last_seen_at updates on every authenticated call → isOnline() becomes true
```

This was already fully correct and already tested (`SecretHandlingTest`, `AgentJobFlowTest`) — nothing about the mechanics changed. Phase 4-D's job was making this flow legible to a non-DevOps operator without they themselves knowing the words "server_id", "config.php", or "HMAC".

## 4. Wizard Architecture

Five steps, rendered via a new shared `<x-wizard-steps>` component (Phase 4-B/4-C component conventions — anonymous Blade component, no PHP class):

| Step | What the brief asked for | What was built |
|---|---|---|
| 1 | Server name, type, description | `servers/create.blade.php` — unchanged fields plus a new `type` select (VPS/cPanel/Shared Hosting/Docker) |
| 2 | "Create Registration", shown-once, expiring | Already-existing `ServerController::store()` credential generation — literally the same request as Step 1's form submit, no separate action needed |
| 3 | Download an installer instead of hand-building config.php | A real, pre-filled `config.php` — see §5 |
| 4 | Upload / run / wait | Static Persian instructions (`ui.servers.install_step_1..5`) plus the server's live online/offline badge |
| 5 | Confirm success | The same badge, now showing "Online" — plus a success `<x-alert>` when already connected on that same page load |

Steps 2-4 all render inside one `<x-card>` block that **only exists when `ServerController::show()` has fresh session-flashed credentials** — i.e., exactly the one page load right after `store()` or `rotateToken()` redirects there. On every later visit that block is gone entirely (§9's `test_downloadable_config_content_appears_only_on_the_one_time_redirect_after_creation`); a server that's never connected instead shows a small, credential-free reminder card (§8).

## 5. Registration Token / Installer Design

Rather than a `GET /servers/{server}/download-config` endpoint (which would need its own auth/expiry story), the config content is computed once in `ServerController::show()` from the **same session-flashed plaintext** the existing credentials box already displays, rendered into a hidden `<pre>` on the page, and turned into a browser download via a few lines of vanilla JS (`Blob` + synthetic `<a download>` click) — no new route, no new server-side storage, no new way to ever recover these values after this one page load. This is strictly narrower than a download endpoint would have been: there is no URL that could be requested a second time, bookmarked, or hit outside this exact request.

The generated file mirrors `agent/config.example.php`'s exact shape (`service_base_url`, `server_id`, `agent_token`, `signing_secret`, poll/timeout defaults, an empty `projects` map with inline comments) so it can be renamed straight to `config.php` on the target server with no manual editing beyond adding the project's `working_directory`. No permanent secret is newly introduced into any log, file, or database column — it's the exact same one-time value, just offered as a file instead of four separate copy-paste fields.

## 6. Approval Flow (Agent sub-entity — verified, not rebuilt)

Tasks 5-6 describe the Agent sub-entity's pending-approval UX, which Phase 3-B/4-B already built in full: `agents/pending.blade.php` shows name, server, OS, architecture, capabilities, and connection time, with Approve/Reject/Discard actions, all already Persian via the Phase 4-B translation layer. The one gap found: no "Agent Version" column, since a pending agent's `agent_version` is genuinely never collected (`AgentRegistrationController::register()`'s validated fields don't include it — a pre-existing, unrelated gap, out of scope to fix since it would mean changing the registration API contract). The column was added anyway for structural completeness with `agents/show.blade.php` (which already has it) — it will show "—" until a future phase decides to collect it, which is honest rather than fabricated.

Approve/Reject/Disable/Re-enable were re-verified against the current `AgentController` and found already fully translated — no code changed there.

## 7. Security Review

- **No credential is stored beyond what already existed.** The config content lives only in a request-scoped Blade variable built from session flash data that Laravel itself will age out after this one request — no new session key, no new cache entry, no new database column holding plaintext.
- **Nothing is printed to any log.** No new logging call was added anywhere in this phase.
- **Registration token behavior is completely unchanged** — `AgentRegistrationController` (single-use, HMAC-verified, timestamp/nonce replay-protected) was not touched; existing `AgentRegistrationTest` coverage still applies unmodified.
- **No existing Agent or Server was invalidated.** Nothing in this phase calls `rotateToken`, deletes a token hash, or otherwise touches a `*_token*`/`*_secret*` column outside the two pre-existing, unmodified actions (`store`, `rotateToken`) that already did so.
- `test_config_content_never_leaks_a_different_servers_credentials` and `test_server_detail_never_exposes_agent_token_hash_or_signing_secret_on_a_later_visit` directly assert the two failure modes this review cared about most.

## 8. Server Detail Changes

`servers/show.blade.php` now shows, in Task 7's order: server info (name, **type** — new, status, last heartbeat), an **Agent** sub-section (version — OS/architecture live on the separate `agents/show` page for a server's registered Agent sub-entities, so weren't duplicated here to avoid two conflicting "OS/architecture" answers on one page for servers with multiple Agents), a new **Deployment Targets** section (every `DeploymentTarget` across every project/environment that references this server — real data from Phase 4-C.5, never surfaced on this page before), and the existing Agents-on-server / Projects-on-server tables, unchanged. `agent_token`/`signing_secret` are never rendered outside the one-time-flash block, exactly as before this phase.

## 9. Files Changed

**New:** `database/migrations/2026_07_26_090000_add_type_to_servers_table.php`; `resources/views/components/wizard-steps.blade.php`; `tests/Feature/Dashboard/ServerOnboardingWizardTest.php`.

**Modified:** `app/Models/Server.php` (`type` in `$fillable` + `TYPE_*` constants); `app/Http/Controllers/Dashboard/ServerController.php` (`store()` validates/persists `type`; `show()` builds the one-time config content and eager-loads `deploymentTargets`); `resources/views/servers/create.blade.php` (type field + wizard step indicator); `resources/views/servers/show.blade.php` (wizard steps 2-5, type row, Deployment Targets section); `resources/views/agents/pending.blade.php` (Agent Version column); `resources/views/layouts/app.blade.php` (new CSS rules only); `lang/en/ui.php`, `lang/fa/ui.php` (new `servers.*` keys).

**Untouched:** `deployment-system/agent/` in its entirety (confirmed via `git status`); `AgentRegistrationController`; `AgentController`; every Agent-lifecycle Model/column; every route; every other Controller.

## 10. Tests Result

```
Full suite: 163/163 passed, 433 assertions (149 pre-existing + 14 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/ServerOnboardingWizardTest.php`):

1. Server type is persisted when provided.
2. Server type is optional (nullable, legacy-compatible).
3. An invalid type value is rejected by validation.
4. A legacy server with `type = null` still renders its detail page correctly.
5. **The one-time scoping test**: config content appears on the redirect-landing page, and is completely absent on a second, later visit to the same page.
6. Config content contains this server's own real generated `server_id`.
7. Config content never contains a *different* server's secret.
8. A later visit to any server's page never exposes its `signing_secret` or `agent_token_hash`.
9. A never-connected server shows the credential-free reminder, not the credentials block.
10. Server detail lists the `DeploymentTarget`s that reference it.
11. Server detail shows the correct empty state when none do.
12. Pending agent list renders the new Version column without exposing any secret.
13. The wizard step indicator renders on the create-server page.
14. Full create → rotate end-to-end flow still works and is still audit-logged.

## 11. Remaining Work

- **The brief's exact "Agent connects automatically" diagram is still not real end-to-end** — that requires the agent-side registration client and a credential-retrieval endpoint flagged in §1, deliberately not built this phase per your decision. This is the single most consequential open item from this phase.
- Server `type` is informational only (as `deployment_targets.agent_id` already was from Phase 4-C.5) — nothing in the deploy/execution path reads it, and none was asked to.
- The Agent sub-entity's "no version at registration" gap (§6) is pre-existing and unrelated to this phase's scope; fixing it means changing `AgentRegistrationController`'s validated fields, a real API contract change.
- No installer *file* download exists for the Agent sub-entity flow (only the Server flow got one) — consistent with this phase's chosen scope, but worth naming as the natural next increment if that flow is ever prioritized.

## 12. Phase 4-E Recommendation

The real gap this phase surfaced — no way for an agent to self-register and no way for it to retrieve post-approval credentials — is the actual blocker on ever making the brief's original diagram true, and it's now precisely scoped (§1) rather than assumed to already work. A natural Phase 4-E would be exactly that: agent-side HMAC registration client code plus a narrow, single-purpose "fetch my credentials once approved" endpoint, treated explicitly as a Security-Model change requiring its own audit and sign-off — not bundled into a UX phase again.
