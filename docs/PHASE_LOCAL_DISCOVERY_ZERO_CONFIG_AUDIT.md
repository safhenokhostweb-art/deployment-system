# Local Discovery & Zero Configuration Setup — Architecture Audit

**Status: design/audit only, per this requirement's own framing — no code was written for it.** Every task in the request is phrased as investigation or design ("بررسی کن", "طراحی کن"), and its own closing rule asks for a complete report at the end, not a test run. This document is that report. (Phase 4-G, which was mid-implementation when this requirement arrived, was finished first — deliberately — since it was already in-flight and this requirement's own Task 10 references it as an existing thing to reconcile with.)

## 1. Executive Summary

The ask is real and the instinct behind it is sound: today's Project Wizard (Phase 4-E) still requires a human to type a repository name, pick a branch, and hand-author a pipeline — exactly the "technical user" friction every Phase 4 sub-phase has been chipping away at. Local Discovery would let an already-running Agent read its own workspace and hand the Wizard pre-filled, verified answers instead.

**The good news, found during this audit: the requirement's own stated principle — "DevOps Hub نباید دسترسی مستقیم به سیستم لوکال داشته باشد" / "Local Agent مسئول Discovery است، نه Hub" — is not a new constraint to design around. It is *already* this system's existing architecture.** Since Phase 1, the Hub has never opened a connection to any Agent; every Agent poll is outbound-only, authenticated with its own bearer token and HMAC-signed per request (`agent/README.md`, `AgentTaskService`, `VerifyAgentRequestSignature`). Local Discovery is not a new trust model — it is a new *capability* layered onto a trust model that was built correctly four phases ago.

**The one real gap:** the actual Agent binary (`agent/agent.php` + `agent/lib/`) has zero filesystem-scanning code today — confirmed by the same audit method Phase 4-D used (`grep` across the whole agent tree). Building Discovery means writing new Agent-side code for the first time since Phase 1. Unlike Phase 4-D's finding (which would have meant new *authentication* code — registering never-before-seen agents), Discovery is read-only reporting over the Agent's *already-authenticated* channel, which is a materially smaller and safer addition. This audit's recommendation (§12) reflects that distinction.

## 2. Current Architecture Review

**Agent (`deployment-system/agent/`):** `agent.php` polls `GET /api/agent/jobs/next`, executes pipeline steps via `Executor` (`proc_open`), and reports status/logs back. `lib/Client.php` is hard-wired to bearer-token + HMAC-signed requests (`Signer.php`) against one `service_base_url`. `config.php` (never committed) holds `server_id`/`agent_token`/`signing_secret` and a `projects` map of `working_directory` per `project_key` — this map is the one place today where the Agent already knows local filesystem paths, and it's entirely manual today (an operator edits `config.php` by hand, per Phase 4-D's wizard-generated file).

**Hub (`deployment-system/service/`):** `AgentJobService`/`AgentTaskService` are the two existing "give the polling Agent something to do, then record what it reports" pipelines (legacy single-target and Phase 3-C new-domain, respectively) — both queue-and-poll, never Hub-initiated. `AgentRegistrationController` (Phase 3-B) is the only place an Agent identity is created from an inbound Agent-initiated call.

**Project Wizard (Phase 4-E):** `ProjectController::create()`/`store()` — repository/branch come from either manual text input or the GitHub App repository/branch pickers (`GitHubAccountController`); `pipeline` comes from one of three hardcoded presets (Laravel/Static/Custom) the operator picks and can hand-edit. Nothing about the Wizard today reads anything from a filesystem, local or remote, beyond what GitHub's API reports.

**GitHub Integration (Phase 4-E):** a GitHub App installation token, minted per-request, never persisted (`GitHubAppClient`). Entirely separate concern from local filesystem discovery — a repository being connected via GitHub App and a local working copy being discovered are two different data sources that would need to be reconciled (e.g. by matching the discovered `.git/config` remote URL against a connected installation's repositories), not merged into one.

**Server Wizard (Phase 4-D):** generates `agent_token`/`signing_secret`, offers a pre-filled `config.php` download built from those one-time session-flashed values (never re-fetchable) — the operator still uploads it and starts the Agent manually. Confirmed (again) in this audit: no Agent self-registration or auto-discovery code exists on the Agent side.

**Phase 4-G (Resource Authorization):** `projects.create` is Admin-only (Phase 4-F matrix, unchanged by 4-G); a newly created Project has zero `ProjectMember` rows until an Admin explicitly adds someone via the new Members section. This is unconditional — it doesn't matter whether the Project was created by hand or from a Discovery suggestion.

## 3. Discovery Design

**Where it runs:** entirely inside the existing Agent process, on the machine that already has real filesystem access — never inside the Hub, and never via a new inbound channel to the Agent (that would violate the very principle this requirement opens with).

**How it's triggered:** modeled as a new *job type* the Agent already knows how to ask for and report on — the same poll/claim/report shape `AgentTaskService` already implements for deployments. A Discovery request is queued in the Hub (operator clicks "Scan Workspace" in the Wizard), the Agent's next poll picks it up like any other job, scans locally, and reports a structured result back over the exact same authenticated channel a deployment status report already uses. No new protocol, no new trust boundary — a new *job payload shape* on rails that already exist.

**Project detection** (Task 2) — file-presence heuristics, cheapest-and-most-specific-first:
| Type | Signals |
|---|---|
| Laravel | `artisan` (executable file) + `composer.json` containing `"laravel/framework"` + `app/`, `bootstrap/`, `config/` directories |
| WordPress | `wp-config.php` **or** `wp-load.php` at the root + `wp-content/` directory |
| Node / static | `package.json` at the root (framework refined by its `dependencies`/`devDependencies` — e.g. `next`, `vite`, `react-scripts`) |
| Generic / unknown | none of the above matched — surfaced as "detected, type unknown," never silently dropped |

Extracted per project: name (directory name, or `composer.json`/`package.json` `"name"` field if present), absolute path, detected type, framework guess, runtime version *if trivially available* (e.g. a `.php-version`/`composer.json` `"require".php` constraint) — never by executing anything, only by reading text files.

## 4. Agent Impact

This is new code, stated plainly: a `DiscoveryScanner` (or similarly named) component added to `agent/lib/`, invoked when the poll response is a Discovery job instead of a deployment job. It needs:
- A configured **workspace root** (new `config.php` key, e.g. `workspace_root`) — the Agent must be told what directory to scan; it must never infer or guess a root that wasn't explicitly configured, and must never traverse above it.
- A **read-only allowlist** of filenames it will open and report content/metadata from (Task 4): `composer.json`, `package.json`, `docker-compose.yml`, `.env.example` (not `.env`), a project's own deployment-config file if one exists, `README.md`. Everything else is presence-checked (does this file exist) but never read.
- A **hard denylist**, enforced in code, not just convention: never open, read, or transmit `.env`, any `*.pem`/`*.key`/SSH key file, anything matching common credential filename patterns. This isn't a policy note — it has to be an explicit check in the scanner before any file read, the same way `AgentTaskService`'s claim query is an explicit `whereHas` rather than a comment saying "don't claim locked environments."
- **Git metadata** (Task 3) via reading `.git/config` (a plain-text file, no `git` binary shell-out needed) for the remote URL, plus `.git/HEAD` for the current branch and the ref it points at for the latest commit hash. The remote URL requires one specific sanitization step this audit flags explicitly: a `.git/config` remote URL can itself embed a credential (`https://user:ghp_xxx@github.com/...` is a real, common pattern from a manually-configured remote) — the scanner must strip any embedded userinfo segment before ever including the URL in a report. This is exactly the class of risk `app/Services/Security/SecretRedactor.php` already exists to catch on the Hub side for deployment log output; the Agent-side scanner needs its own equivalent (the two codebases don't share a vendor autoload, so this would be parallel logic, not a shared class) applied *before* anything leaves the Agent process, not after.

**None of this touches the Agent's existing deployment-execution code path** (`Executor`, the job-claim loop, `Client`'s signing) — it's a new, additive branch in the same poll loop, reusing the same authenticated `Client` to send its report.

## 5. Hub Impact

- A new **Discovery job** concept, parallel to (not replacing) `AgentJobService`/`AgentTaskService` — queue a scan request, receive a scan report. Given the existing split between legacy (`AgentJobService`) and new-domain (`AgentTaskService`) job handling, a Discovery job is closer in spirit to the new-domain shape (Agent-identity-authenticated, not Server-only) — it should be built as its own service rather than bolted onto either existing one, mirroring how Phase 3-C.2 kept the two domains' claim logic in separate classes rather than branching one class two ways.
- A place to **store scan results** pending the operator's review — likely a new table (e.g. `workspace_scans`) holding one row per scan with a JSON payload of detected projects, tied to the `Agent`/`Server` that produced it. This is a genuine new migration, flagged here for the same "report before executing" treatment every prior phase's migrations got — not run in this audit, since this document is design-only.
- **Project Wizard integration** (Task 6): Step "GitHub" and Step "Deployment" of the existing 6-step wizard (Phase 4-E) would gain a preceding "Scan Workspace → Detected Projects → Select Project" stage that pre-fills name/description (Step 1), and *suggests* (never silently sets) repository/branch (Step 2, cross-referenced against the operator's already-connected GitHub accounts by matching the discovered remote URL's owner/repo against `GET /installation/repositories`) and a pipeline preset (Step 4, per Task 5 — a suggestion the operator reviews and can fully edit before Step 6's create, exactly like today's three hardcoded presets already work, just with a fourth "detected" option pre-selected).
- **Server Wizard integration** (Task 7): once "Connect Server" (Phase 4-D) succeeds and the Agent's first poll confirms it's online, a "Scan this server's workspace" action becomes available — reusing the exact same online/offline signal the Server detail page already computes (`Server::isOnline()`), no new status concept needed.

## 6. Security Analysis

Task 8's forbidden list (no full-file upload, no secret storage, no real `.env` transmission, no credential extraction) is achievable given the design in §4 — the mechanism enforcing it is entirely the Agent-side allowlist/denylist and the git-remote sanitization step, since the Hub has no way to ask for more than what the Agent's scanner chooses to send back. This is the same shape of guarantee the existing pipeline execution model already relies on: the Hub cannot compel the Agent to run anything outside its configured pipeline steps, and under this design it similarly cannot compel it to read anything outside the scanner's own allowlist.

Two risks worth naming explicitly, neither of which changes the recommendation but both of which need to be true on day one of implementation, not retrofitted:
- **The denylist must be enforced in code as a first-class check**, not as "the allowlist just doesn't happen to include secrets" — an allowlist that's merely *incomplete* rather than actively excluding known-sensitive patterns is one missed file extension away from a real leak. Given `.env.example` is allowed and `.env` is not, the scanner needs an explicit `.env` (exact name) exclusion, not a fuzzy "files starting with .env" allowlist that would also match `.env.example` and be technically correct only by accident.
- **Discovered configuration content (composer.json, docker-compose.yml, etc.) can itself contain secrets** an operator pasted somewhere they shouldn't have (an API key hardcoded in a `docker-compose.yml` environment block is a real, common mistake) — the redaction pattern in §4 needs to apply to the *content* of allowlisted files too, not just to the git remote URL. This is exactly `SecretRedactor`'s existing job on the Hub side for pipeline log output; Discovery needs the equivalent applied to file content before it ever leaves the Agent.

## 7. Wizard Integration

See §5's Hub Impact for the concrete flow change. The key UX principle from the brief worth restating precisely: Discovery *replaces typing*, not *review* — every existing field in the current 6-step Wizard stays exactly where it is and stays fully editable; Discovery only changes what arrives pre-filled in it. This means **zero change to `ProjectController::validateProject()`** — the validation rules that exist today (repository regex, branch required, pipeline must be a non-empty JSON array of `{name, command}` steps) apply identically whether a field was typed by a human or pre-filled from a scan report, since the Controller has no way to tell the difference and doesn't need one.

## 8. Database Impact

Anticipated (not created — this is a design document): one new table (`workspace_scans` or similar) holding scan results pending operator review, and one new `config.php` key (`workspace_root`) on the Agent side, which isn't a Hub-side migration at all. No existing table needs a column added or changed — a Project created from a Discovery suggestion is created through the exact same `ProjectController::store()` path, with the exact same columns, as one typed by hand today.

## 9. Backward Compatibility

Every one of Task 9's five requirements is a direct consequence of the design in §4-5, not something that needs separate engineering: Discovery is additive (a new job type, a new optional Agent config key, a new optional Wizard stage) layered on top of code paths this audit confirmed are otherwise untouched. An Agent whose `config.php` has no `workspace_root` key simply never receives (or never acts on) a Discovery job — it keeps polling and executing deployments exactly as it does today. A Project created by typing (the only way that exists today) is byte-for-byte the same kind of row as one created via Discovery. The GitHub webhook path (Phase 1/4-E) and the legacy single-target deploy path (Phase 1/3-A) have no dependency on how a Project's `repository`/`branch`/`pipeline` fields were originally populated.

## 10. Implementation Roadmap

1. Agent-side `DiscoveryScanner` + denylist/allowlist + git-remote sanitization (§4) — the highest-risk, most security-sensitive piece, deserving its own audit-and-sign-off cycle the way Phase 4-D's agent-registration gap was deliberately *not* built without one.
2. Hub-side Discovery job service + `workspace_scans` storage (§5) — mechanically similar to existing `AgentTaskService`, lower risk once (1) is trusted.
3. Wizard's "Scan Workspace" stage (§7) — purely additive UI, lowest risk, blocked only on (1) and (2) existing.
4. Server Wizard's "Scan this server" trigger (§5) — smallest piece, depends on (1)-(3).

## 11. Risks

- **Scope creep into "the Hub can ask the Agent to read arbitrary files"** if the allowlist isn't kept genuinely fixed and small — every new allowlisted filename is a new thing to security-review, not a configuration toggle to flip casually.
- **False-positive project detection** (e.g. a `node_modules/some-package/package.json` nested inside a Laravel project misidentified as a top-level Node project) — needs an explicit "don't recurse into known dependency directories" rule (`vendor/`, `node_modules/`, `.git/` itself) or Discovery will surface noise instead of the two or three real projects in a workspace.
- **Credential-bearing git remotes** (§4/§6) are a real, not theoretical, pattern — this needs a test written *against real examples of the pattern* before shipping, not assumed away.
- **Divergence between GitHub-App-connected repositories and discovered local git remotes** — a discovered project's remote might point at a repository the connected GitHub App installation was never granted access to; the Wizard needs to handle "detected, but no matching connected repository" as a normal case (falling back to Phase 4-E's existing manual-entry path), not an error.

## 12. Recommendation

Build this as a genuinely separate, security-reviewed phase — not folded into Project Wizard polish — for the same reason Phase 4-D's agent-registration gap was named as needing its own future phase rather than being rushed into a UX-focused one: the highest-risk piece (§4's Agent-side scanner, denylist, and remote-URL sanitization) is exactly the kind of code that needs adversarial review of its exclusion rules before it ever runs against a real workspace, and that review is a different kind of work than wiring up a new Wizard step. The Hub-side and Wizard-side pieces (§5, §7) are low-risk and can follow immediately once the scanner itself is trusted. Phase 4-G's resource authorization needs no changes at all to support this (§2/§9's integration point: Discovery-created projects are created through the existing Admin-only `projects.create` path, with zero membership rows, exactly like every project today) — that was the one open question this audit set out to answer, and the answer is that the two systems don't actually intersect.
