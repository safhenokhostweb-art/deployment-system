# Final Phase — Local Import & Auto Configuration: Pre-Implementation Audit

## 1. Purpose

This is the last phase of DevOps Hub work. Nothing new is being built —
every capability this phase touches (Local Discovery, the Project
Wizard, GitHub App integration, pipeline presets, the Server Wizard)
already exists from Phases 4-D through 4-K. The only remaining problem,
stated directly in the brief, is that Discovery's output and the
Project Wizard's input aren't fully connected yet. This document
records exactly what already works, what's missing, and what genuinely
needs user review versus what can be filled automatically.

## 2. Current Local Discovery Output

`DiscoveryJob::result` (JSON, one entry per detected local project) —
confirmed by reading `agent/lib/WorkspaceScanner.php` and
`app/Services/Discovery/DiscoveryJobService.php` directly, unchanged
since Phase 4-H:

```json
{
  "name": "saas-platform",
  "path": "/home/example/www/saas-platform",
  "type": "laravel",
  "framework_version": "^11.0",
  "git": {
    "remote_url": "https://github.com/acme/saas-platform.git",
    "provider": "github",
    "repository": "acme/saas-platform",
    "branch": "main",
    "commit_hash": "a1b2c3..."
  }
}
```

Every field here is sanitized metadata only — confirmed by re-reading
`WorkspaceScanner`'s `ALLOWED_READ_FILENAMES`/`FORBIDDEN_FILENAMES`/
`FORBIDDEN_EXTENSIONS` constants (unchanged since Phase 4-H) and
`RemoteUrlSanitizer` (strips `user:pass@` from `remote_url` before it's
ever included). No file content, no `.env`, no key material is ever
part of this structure.

**What Local Discovery already extracts, in full:** name, absolute
local path, framework type, framework version, git remote URL
(sanitized), provider, `owner/repo`, branch, commit hash.

**What was already connected before this phase (`servers/show.blade.php`
→ `route('projects.create', [...])`):** `discovered_name`,
`discovered_repository`, `discovered_branch`, `discovered_type` — read
by `ProjectController::create()` into a `$prefill` array, used by
`projects/create.blade.php` to pre-populate the *manual-entry*
repository/branch inputs and Step 1's name field, and to auto-select
the matching pipeline preset (Task 5 — already fully working).

**What was detected but never surfaced past the Discovery results
table:** `path` (local filesystem path) and `commit_hash` — the
Discovery results table on `servers/show.blade.php` only ever showed
Name/Repository/Branch/Action, not Path or Last Commit, even though
`WorkspaceScanner` already reports both. This is a real gap against
Task 2's explicit field list.

## 3. Project Wizard — What's Auto-Fillable vs What Needs Review

| Wizard field | Auto-fillable from Discovery? | Notes |
|---|---|---|
| Name (Step 1) | Yes — already wired | `discovered_name` |
| Repository (Step 2, manual entry) | Yes — already wired | `discovered_repository` |
| Branch (Step 2, manual entry) | Yes — already wired | `discovered_branch` |
| **Repository/branch when a GitHub account is connected (Step 2, picker)** | **No — this is the real gap** | See §5 |
| Pipeline preset (Step 4) | Yes — already wired | `discovered_type` picks laravel/wordpress/static |
| Server (Step 3) | **No, and it shouldn't be** | A local scan has no concept of *which* registered Server the code will be deployed to — that's the whole point of the wizard's own Server step; auto-selecting one would be guessing, not discovery |
| Environment name (Step 3) | **No, and it shouldn't be** | Same reasoning — an environment name ("Production", "Staging") is an operator decision, not something a local filesystem scan can know |
| project_key | Already auto-derived from Name (Phase 4-E, unrelated to Discovery) | No change needed |

**Local Path and Framework Version — informational only, deliberately
never stored on `Project`.** The `Project` model has no
path/working_directory column, by design since Phase 1: a server's
filesystem layout lives only in that server's own `agent/config.php`
`projects` map, never centralized in the Hub's database (this is the
same principle that already keeps `DeploymentTarget` free of any path
column). Task 3 asking for "Local Path" and "Framework" to auto-fill
the wizard is satisfied by *displaying* them for the user's review
(so they know what was detected and why a given preset was chosen),
not by adding new persisted fields — doing the latter would be a new
architecture decision this phase is explicitly forbidden from making.

## 4. GitHub Connection Flow — Audit Result

`projects/create.blade.php` Step 2 has always had two mutually-exclusive
modes: a GitHub picker (shown when at least one GitHub account is
connected) and manual entry (shown otherwise, or toggled to manually).
Phase 4-H's prefill only ever populated the **manual-entry** inputs.
When a GitHub account is already connected — the common case once
onboarding is complete — the picker mode is shown by default instead,
and it starts completely empty: no account pre-selected, no repository
list loaded, no branch chosen. A user coming from "Use this project"
would need to notice the "enter manually instead" toggle to see their
discovered repository/branch at all, or otherwise re-pick the same
repository from the picker by hand. This is precisely the gap Task 4
names: *"سیستم نباید دوباره Repository و Branch را دستی بخواهد"* (the
system must not ask for repository/branch manually again).

**Confirmed reusable as-is, no changes needed:** `GitHubAccountController::
repositories()`/`branches()` (the two JSON endpoints the picker already
calls), their response shapes (`{name, full_name, owner, private,
default_branch}` / `string[]`), the GitHub App connection flow itself
(`connect()`/`callback()`), and the webhook auto-configuration in
`ProjectController::tryAutoConfigureWebhook()`.

## 5. Deployment Configuration Suggestion — Audit Result

Already fully working, confirmed by re-reading `ProjectController`:
`PIPELINE_PRESET_LARAVEL` (git pull, composer install, migrate, cache
clear, asset build, queue restart), `PIPELINE_PRESET_WORDPRESS`,
`PIPELINE_PRESET_STATIC`, and `PIPELINE_PRESET_CUSTOM`, auto-selected
via `discovered_type` in `create()`, editable by the user in Step 4's
textarea before submission, and never executed until a real deployment
is later triggered by an explicit user action. No change needed —
matches Task 5's rules exactly already.

## 6. Server Connection Flow — Audit Result

`ServerController` (register → issue token/secret → download
`config.php` → manual agent install on the target machine → confirm
online) has been unchanged in shape since Phase 4-D. It already is
exactly "Server Connection + Agent Connection + Test Connection" with
no provisioning step of any kind — the Hub never touches the target
machine directly. No change needed; Task 6 is satisfied by leaving
this alone.

## 7. Conclusion — What This Phase Actually Implements

Given the above, the only real, justified connecting work is:

1. **Task 2/3:** add Path and Last Commit to the Discovery results
   table (display only), and carry two more query parameters
   (`discovered_path`, `discovered_commit`) through to the Project
   Wizard so Step 1 can show a small "detected from local discovery"
   notice for user awareness before they review/confirm.
2. **Task 4:** teach the wizard's existing GitHub picker to
   auto-select the discovered repository (and its matching branch)
   when exactly one GitHub account is connected — the same
   "unambiguous, otherwise don't guess" convention already used
   elsewhere in this codebase (e.g. `Server::soleProject()`,
   `soleApprovedAgentFor()`). With multiple connected accounts, the
   picker still starts empty (no way to know which account owns the
   repository without trying each one) but the manual-entry fallback
   keeps the discovered values pre-filled exactly as before.

Everything else audited above is already correct and is left
untouched.
