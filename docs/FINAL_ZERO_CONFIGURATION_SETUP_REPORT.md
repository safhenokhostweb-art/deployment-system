# Phase 4-L: Zero Configuration Setup Assistant

## 1. Executive Summary

Phase 4-L does not add a new DevOps capability and does not touch the
Deployment Engine, the Agent poll protocol, or Authentication — per the
brief's explicit constraints. It only reduces the manual work required
to get a real GitHub connection and a real Server connection into a
"ready" state, and adds one new read-only page (`/readiness`) that
answers a single question at a glance: **can a real deployment actually
run right now?** All pre-existing tests pass unmodified (298/298 after
this phase, full suite); 6 new tests were added for the Readiness page.
No changes were made to `saas-platform` — every change in this phase is
inside `deployment-system`.

## 2. Pre-Implementation Audit (Task 1)

**What GitHub connection state existed before this phase:**
`GitHubAccountController::index()` listed connected accounts, but gave
no indication of whether the required `github_app.*` env vars
(`GITHUB_APP_ID`/`SLUG`/`CLIENT_ID`/`CLIENT_SECRET`/`PRIVATE_KEY`) were
actually present — an operator with a misconfigured `.env` would only
discover the problem when the GitHub OAuth redirect failed with a raw
error. Separately, `Project::github_account_id` did not exist at all:
`tryAutoConfigureWebhook()` ran once at project-creation time and threw
its result away, so there was no way to know afterward whether a given
project's webhook was actually connected, and no way to fix it
retroactively without re-running project creation.

**What Server connection state existed before this phase:**
`ServerController::show()` already generated a one-time `config.php`
download (Phase 4-D) and showed a step tracker (`x-wizard-steps`) — but
only immediately after registration, before the page was ever reloaded.
Reloading the page, or coming back later to a still-unconnected server,
dropped the operator back to a bare "not connected yet" message with no
memory of which step they were on, no ready-made systemd unit file (the
five prose install steps assumed the operator would hand-write one),
and no way to re-check connection status except leaving and returning
to the Servers list.

Both gaps — "no persistent status, no generated artifact, no
retroactive fix" — are structural UX gaps, not missing features; fixing
them requires no new domain concept, consistent with the brief's "no
architecture change" constraint.

## 3. GitHub Setup Wizard (Task 2)

- **New column:** `projects.github_account_id` (nullable FK to
  `github_accounts`, `nullOnDelete()`) — the only schema change in this
  phase, purely additive, migrated successfully against both the test
  and the real `database.sqlite`.
  `Project::hasGithubWebhookConfigured()` reads it;
  `Project::githubAccount()` is the new relation.
- **`tryAutoConfigureWebhook()`** now persists
  `github_account_id` on success (previously computed and discarded).
- **`GitHubAccountController::index()`** gained an `$appConfigChecklist`
  (three booleans: App configured / Account connected / Projects
  connected) rendered as a status card at the top of
  `github/accounts/index.blade.php`, plus a list of the specific
  missing env var names when `$appConfigured` is false — turning a
  silent misconfiguration into a page an operator can act on directly.
- **Retroactive connect action:** a project with no
  `github_account_id` now shows up in a "Projects needing GitHub
  connection" list (on both the GitHub accounts page and the project's
  own page) with a one-field form — a dropdown only when more than one
  connected account exists, otherwise a hidden input (mirrors the
  existing "sole X unambiguous" convention from
  `ProjectController::soleApprovedAgentFor()` /
  `Server::soleProject()`). Submitting it calls the new
  `ProjectController::connectGithub()`, which reuses
  `tryAutoConfigureWebhook()` unchanged — no new connection logic, only
  a new way to trigger the existing one. Repository/branch are never
  re-asked for.

## 4. Server Setup Wizard (Task 3)

- **Persistent step tracker:** `setupStep` is now computed on every
  `ServerController::show()` call from the server's actual state
  (`isOnline()` → step 5, `last_seen_at !== null` → step 4, else step
  3) instead of only existing in the one-time post-registration flash.
  The wizard steps card is shown on every visit until the server comes
  online, not just immediately after creation.
- **Generated install artifact:** a new
  `buildAgentServiceFileContent()` produces a ready
  `deployment-agent.service` systemd unit (server name filled in,
  `CHANGE_ME`-style placeholders only for the one thing the Hub
  genuinely cannot know — the absolute path on the operator's own
  server layout), downloadable via the same hidden-`<pre>` + `Blob`
  pattern already used for `config.php` (Phase 4-D) — no new download
  mechanism invented.
- **Ready install command:** a literal 3-line copy-paste block
  (`sudo mv … && sudo systemctl daemon-reload && sudo systemctl enable
  --now deployment-agent`) sits next to the download button, so the
  only manual step left is moving the file to its real path.
- **"Check connection now":** since the Hub is strictly poll-based and
  can never reach out to an Agent, a real connection test can only mean
  "has the Agent already polled in" — implemented as a plain link that
  reloads the same page, deliberately not a new AJAX/JSON status
  endpoint. No change to the Agent protocol.

## 5. Final Readiness Page (Task 4)

New `GET /readiness` (`readiness.index`), gated by `projects.view` —
the same broad audience as the Dashboard, since "is this ready" is a
read-only question every role from Viewer up should be able to answer
without needing management permissions on the underlying resources.

`ReadinessController` computes five status values, all derived from
data that already existed — nothing new is stored:

| Status | Derived from |
|---|---|
| GitHub | `GitHubAccount::where('status', STATUS_CONNECTED)->exists()` |
| Server (per row) | `Server::isOnline()` (existing method, unchanged) |
| Agent (per server) | its agents' `STATUS_APPROVED`/`STATUS_PENDING_APPROVAL` |
| Project — Configured | active, and has `repository`/`branch`/`pipeline` |
| Project — Deployment ready | Configured, and its server is active + online |

Rendered as three cards (GitHub summary, Servers table, Projects
table) in `resources/views/readiness/index.blade.php`, reusing the
existing `<x-card>`/`<x-badge>`/`<x-empty-state>` components. Seven new
badge/status variants (`connected`/`not_connected`/`ready`/
`not_ready`/`configured`/`not_configured`/`no_agent`) were added to
`status.php` (en/fa) and given CSS colors in `layouts/app.blade.php`,
reusing the existing green/gray badge palette rather than introducing
new colors. A `nav.readiness` sidebar link (gated by the same
`projects.view` check) was added between Operations and Users.

## 6. Data Audit and Cleanup (Task 5)

Re-ran the same read-only audit used at the start of the prior "Real
Environment Setup" phase against the real `database.sqlite`, to confirm
this phase's development only touched schema:

| Table | Before this phase | After this phase |
|---|---|---|
| Users | 1 (Admin) | 1 (Admin) — unchanged |
| Servers | 1 (parsvds) | 1 (parsvds) — unchanged |
| Projects | 1 (saassf) | 1 (saassf) — unchanged |
| GitHubAccounts | 0 | 0 — unchanged |
| Agents | 0 | 0 — unchanged |
| Deployments | 0 | 0 — unchanged |
| OperationJobs | 0 | 0 — unchanged |
| Notifications | 0 | 0 — unchanged |

The real Project's `github_account_id` is `NULL` and the real Server's
`last_seen_at` is `NULL`, exactly as before — confirming the new
nullable-FK migration ran cleanly with no side effect on existing rows,
and that no test data was introduced into the real database during this
phase's development. No deletions were necessary.

## 7. Tests

- New: `tests/Feature/Dashboard/ReadinessPageTest.php` — 6 tests /
  25 assertions: page loads for a Viewer; GitHub section reflects a
  connected account and ignores a disconnected one; a server row shows
  online/offline and approved/pending/no-agent correctly; a project row
  shows configured/not-configured and ready/not-ready correctly; empty
  states render when nothing exists.
- Full suite (`vendor/bin/phpunit`, Feature + Unit): **298/298
  passing**, 808 assertions — includes the 6 new Readiness tests and
  all pre-existing Phase 4-A through 4-K tests, unmodified.
- Dashboard feature subset re-verified in isolation
  (`tests/Feature/Dashboard/*`): **192/192 passing**.

## 8. Constraint Compliance

- **No new DevOps feature** — every change either exposes existing
  state more clearly (checklists, the Readiness page) or generates an
  artifact from existing data (the service file); no new connection
  mechanism, job type, or execution path was added.
- **No architecture change** — the only schema change is one nullable
  FK column, the same category as prior phases' tracking columns
  (`discovery_jobs`, `operation_jobs`, health columns).
- **Deployment Engine untouched** — no file under
  `app/Services/Deployment/` was modified.
- **Agent Protocol untouched** — no change to `agent/agent.php`, the
  signed-channel middleware, or any `/api/agent/*` route; "Check
  connection now" is a plain page reload, not a new endpoint.
- **Authentication untouched** — no change to `AuthController`, login,
  or session handling.
- **Legacy flow still works** — project creation's original
  `tryAutoConfigureWebhook()` call path is unchanged in behavior, only
  now also persists its result; a server registered before this phase
  behaves identically, just with a persistent (rather than one-time)
  status view.

## 9. Files Changed

**New:**
- `service/database/migrations/2026_08_03_090000_add_github_account_id_to_projects_table.php`
- `service/app/Http/Controllers/Dashboard/ReadinessController.php`
- `service/resources/views/readiness/index.blade.php`
- `service/tests/Feature/Dashboard/ReadinessPageTest.php`
- `docs/FINAL_ZERO_CONFIGURATION_SETUP_REPORT.md` (this file)

**Modified:**
- `service/app/Models/Project.php` (`github_account_id` fillable,
  `githubAccount()`, `hasGithubWebhookConfigured()`)
- `service/app/Http/Controllers/Dashboard/ProjectController.php`
  (`tryAutoConfigureWebhook()` persists; `show()` passes
  `connectableGithubAccounts`; new `connectGithub()`)
- `service/app/Http/Controllers/Dashboard/GitHubAccountController.php`
  (`index()` checklist + `projectsNeedingGithub`)
- `service/app/Http/Controllers/Dashboard/ServerController.php`
  (`agentServiceFileContent`, `setupStep`,
  `buildAgentServiceFileContent()`)
- `service/resources/views/github/accounts/index.blade.php` (checklist
  card, projects-needing-connection card)
- `service/resources/views/projects/show.blade.php` (connection status
  block / connect form)
- `service/resources/views/servers/show.blade.php` (persistent step
  tracker, ready-install-command card, check-connection link)
- `service/resources/views/layouts/app.blade.php` (`nav.readiness`
  link, 7 new badge CSS rules)
- `service/routes/web.php` (`projects.connect-github`,
  `readiness.index`)
- `service/lang/{en,fa}/nav.php` (`readiness`)
- `service/lang/{en,fa}/ui.php` (`servers.*`/`github.*`/`projects.*`
  additions, new `readiness` section)
- `service/lang/{en,fa}/messages.php`
  (`flash.github_webhook_connected`)
- `service/lang/{en,fa}/status.php` (7 new status keys)

## 10. Remaining Work

- The real GitHub App connection and real Agent installation on the
  operator's `parsvds` server are still the operator's own actions to
  perform (per the prior phase's explicit hand-off) — Phase 4-L makes
  that process shorter, it does not perform it. `/readiness` will show
  GitHub as "Not connected" and the server's Agent as "No agent" until
  those real-world steps are completed.
- No automatic retry or reminder if an operator abandons the Server
  Setup Wizard mid-way — the persistent step tracker shows the current
  state on return, but nothing proactively notifies them to finish.
- The "Check connection now" link is a full page reload by design (no
  new protocol surface); a future phase could add lightweight
  auto-refresh without changing the underlying poll model.
