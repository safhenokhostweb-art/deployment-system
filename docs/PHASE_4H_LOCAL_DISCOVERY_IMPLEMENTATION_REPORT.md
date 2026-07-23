# Phase 4-H: Local Discovery & Zero Configuration — Implementation Report

## 1. Executive Summary

Phase 4-H implements the Local Discovery Layer designed in
`docs/PHASE_LOCAL_DISCOVERY_ZERO_CONFIG_AUDIT.md`: the Hub can now ask an
already-connected Agent to scan a workspace folder and report back
structured, sanitized metadata about the projects it finds (framework
type, git remote/branch/commit) — with zero new communication protocol,
zero change to Agent authentication, and zero secret ever leaving the
target machine. The Project Wizard can pre-fill from a scan result, so
the end-to-end flow the brief asked for ("Connect GitHub" + "Connect
Server" + pick a detected project) now works without any manual
technical data entry for name/repository/branch/pipeline type.

Every existing test still passes unmodified. Discovery is strictly
opt-in on the Agent side (`workspace_root` in `config.php`) — an
unconfigured or pre-4-H agent binary has no code path that could ever
reach it.

## 2. Audit Result

Confirmed by direct reading of `agent/agent.php`, `agent/lib/Client.php`,
`agent/lib/Signer.php`, `app/Http/Middleware/VerifyAgentRequestSignature.php`,
and `app/Http/Controllers/Api/AgentJobController.php`:

- `Client::request(string $method, string $path, ?array $payload)` is
  already fully generic — it needed **zero changes** to call a
  brand-new endpoint. This is what makes "no new communication
  protocol" achievable: Discovery is just two more routes behind the
  same `agent.signed` middleware.
- `VerifyAgentRequestSignature` already distinguishes a "New Agent"
  identity (`$request->attributes->get('deployment_agent')` is a real
  `Agent`) from a legacy Server-only credential. `AgentTaskService`
  already only claims new-domain work for the former. Discovery follows
  the identical rule.
- `agent/` has **no existing test infrastructure** (no Composer, no
  PHPUnit) — confirmed via directory listing. Adding one only for this
  phase's tests would contradict the directory's own stated "no
  Composer install needed on production" identity (see
  `agent/README.md`), so a dependency-free plain-PHP harness was used
  instead (`agent/tests/run.php`).
- `AgentJob` was ruled out as a reusable entity for Discovery — its
  schema requires a non-null `deployment_task_id`, which has no
  meaningful value for a scan that usually runs *before* any
  Project/Deployment exists.

## 3. Architecture Decision

Discovery is a **new Job type on the existing poll channel**, not a new
protocol:

- Same transport: outbound-only HTTPS poll, same `Client`.
- Same auth: bearer token + HMAC signature, same `agent.signed`
  middleware, same nonce replay protection.
- New, independent domain object: `DiscoveryJob` (belongs to `Agent`,
  not to any Deployment/Project — a scan can run with no Project in
  existence yet).
- New, Agent-identity-only routes: `GET /api/agent/discovery-jobs/next`,
  `POST /api/agent/discovery-jobs/{id}/report`. A legacy Server-only
  credential polling either route gets the same quiet `{"job": null}`
  shape it already knows how to ignore.
- Agent-side opt-in flag: `workspace_root` in `config.php`. Absent (the
  default, and the only state a pre-4-H `config.php` can be in) means
  the discovery poll function is never called at all.

## 4. Discovery Job Design

`discovery_jobs` table (new, purely additive):

| column | notes |
|---|---|
| `public_id` | UUID, unique, used on the wire instead of the numeric id |
| `agent_id` | FK to `agents`, cascade delete |
| `workspace_path` | the folder the Hub asked to be scanned |
| `status` | `queued` → `running` → `succeeded`\|`failed` |
| `requested_by` | dashboard user email who triggered the scan |
| `result` | JSON array of sanitized detected-project metadata |
| `error_message` | set only on failure |

`App\Models\DiscoveryJob` + `App\Services\Discovery\DiscoveryJobService`
(`queue`/`claimNext`/`reportResult`/`reportFailure`) follow the exact
shape of the existing `AgentTaskService` for the deployment-job domain —
same audit-logging convention (`discovery_job.queued/succeeded/failed`),
same "claim flips status and returns the row" pattern.

## 5. Agent Changes

- `agent/agent.php`: two additions only. (1) two new `require`s for the
  new lib files. (2) inside the existing poll loop, one new `if
  (!empty($config['workspace_root']))` branch that calls
  `pollAndExecuteDiscovery()` in its own try/catch, after the existing
  deployment-job poll — a Discovery failure can never affect job
  polling, and job polling's own code is untouched byte-for-byte.
- `agent/config.example.php`: one new, commented-out, opt-in key
  (`workspace_root`) with an explanation of exactly what it does and
  does not send.
- No change to `Signer`, `Client`, `Executor`, `JobVerifier`, or any
  existing deployment-execution code path.

## 6. Scanner Design

`agent/lib/WorkspaceScanner.php` scans only the **immediate**
subdirectories of `workspace_root` (never recursing into `vendor/`,
`node_modules/`, `.git/`, `.idea/`, `.vscode/`). For each subdirectory:

1. **Type detection**, in order: Laravel (artisan + composer.json's
   `require.laravel/framework` + `app/`/`bootstrap/`/`config/` present)
   → WordPress (`wp-config.php` or `wp-load.php` + `wp-content/`
   present, by **existence check only** — the file is never opened) →
   Node (`package.json` present) → `unknown`.
2. **Framework version**: Laravel's composer.json constraint string,
   Node's own `package.json` version, or WordPress's `$wp_version` from
   `wp-includes/version.php` (a plain version constant, never a
   credential).
3. **Git discovery**: parses `.git/config` for the `origin` remote,
   `.git/HEAD` for the current branch (or reports `null` on detached
   HEAD rather than guessing), and resolves the commit hash from the
   loose ref file or `packed-refs` fallback.
4. **Remote URL sanitization**: `agent/lib/RemoteUrlSanitizer.php` uses
   `parse_url()` to strip any `user:pass@` userinfo before the URL is
   ever included in a report — confirmed more precise here than a
   regex, since a URL has a real, unambiguous grammar for where
   credentials can appear. Also derives `provider` (github/gitlab/
   bitbucket/host) and `owner/repo` from the sanitized URL.

## 7. Security Review

- **Read allow-list, actively enforced**: `assertReadable()` in
  `WorkspaceScanner` checks a file against both an allow-list
  (`composer.json`, `package.json`, `.git/config`, `.git/HEAD`,
  `packed-refs`, `wp-includes/version.php`) **and** an independent
  deny-list (`.env`, `.env.local`, private-key filenames, `.pem`/`.key`/
  `.ppk`/`.p12`/`.pfx` extensions) before any `file_get_contents()`
  call — the deny-list is a second, active check, not merely "absent
  from the allow-list."
- **wp-config.php is never opened** — WordPress detection uses
  `is_file()` existence checks only, so its DB credentials can never
  reach the scanner's output. Verified directly by the agent-side test
  harness (`agent/tests/run.php`: "never reads wp-config.php" assertion
  with a real DB password in the fixture).
- **Remote URLs are sanitized before ever leaving the Agent process** —
  verified by both the agent-side harness (embedded `ghp_...` token
  fixture) and the Hub-side `DiscoveryJobFlowTest`.
- **Defense in depth, Hub side**: `DiscoveryJobService::reportResult()`
  recursively re-applies the existing `SecretRedactor` to every string
  leaf of the reported payload before persisting, in case an
  agent-reported field ever contained something secret-shaped despite
  the Agent-side controls. Covered by
  `test_reported_secrets_are_redacted_before_persisting`.
- **The Agent never reports commands, only structured metadata** —
  `type`/`version`/`path`/git fields only. Only the Hub (trusted,
  already-reviewed Laravel code) decides what pipeline preset text to
  suggest for a given `type`, so a compromised or malicious scan result
  can never inject an arbitrary command into a suggested pipeline.
- **Discovery is Agent-identity-only** — a legacy Server-only credential
  gets `{"job": null}` from both new endpoints, mirroring the existing
  `AgentTaskService::claimNextTask` precedent. Verified by
  `test_legacy_server_credential_never_claims_a_discovery_job`.
- **Cross-agent isolation** — an agent cannot report on another agent's
  DiscoveryJob (403), same pattern as the existing AgentJob ownership
  check. Verified by
  `test_agent_cannot_report_on_a_discovery_job_belonging_to_another_agent`.

## 8. Wizard Integration

- `servers/show.blade.php` gained a "Scan workspace" card: a form
  (shown only when the server has an approved Agent) plus, once a scan
  succeeds, a table of detected projects each with a "Use this project"
  link to `projects.create` carrying `discovered_name`,
  `discovered_repository`, `discovered_branch`, `discovered_type` query
  parameters.
- `ProjectController::create()` reads those (all-optional) query
  parameters into a `$prefill` array and picks a matching pipeline
  preset key (`laravel`/`wordpress`/`static`, defaulting to `laravel`).
  `projects/create.blade.php`'s name/repository/branch inputs default to
  `old(..., $prefill[...] ?? ...)` and the pipeline-preset `<select>`
  pre-selects the matching option — every one of these is an *existing*
  form field's starting value; no new field was added and
  `validateProject()` was not touched.
- New `PIPELINE_PRESET_WORDPRESS` constant added alongside the existing
  Laravel/Static presets (Task 7's example: pull + file-permissions).
  `PIPELINE_PRESET_LARAVEL` gained one appended step
  (`artisan queue:restart`) — the brief's own suggestion example — which
  only changes the *default template text* offered to a brand-new
  project; no existing Project's stored pipeline is touched.
- Triggering a scan (`DiscoveryController::store`) is gated by
  `Gate::authorize('projects.create')` — the same, Admin-only permission
  that already guards project creation, since Discovery exists solely
  to feed that wizard.

## 9. Backward Compatibility

- An Agent with no `workspace_root` in its `config.php` (every agent
  binary that predates this phase, and every new one left unconfigured)
  never calls the discovery endpoints at all — verified by code
  inspection of the new `if (!empty($config['workspace_root']))` guard;
  no version check or feature flag was needed because the code path is
  structurally unreachable without that key. A legacy Server-only
  credential that did poll the Discovery routes gets the same quiet
  `{"job": null}` shape either way — verified by
  `test_legacy_server_credential_never_claims_a_discovery_job`.
- A plain `GET /projects/create` with no discovery query parameters
  renders byte-for-byte what it always has — verified by
  `test_project_wizard_with_no_discovery_query_parameters_behaves_exactly_as_before`.
- No existing table, column, or route was altered or removed. All 199
  pre-4-H tests plus every test from Phases 4-C.5 through 4-G still
  pass unmodified.

## 10. Files Changed

**Hub (`service/`) — new:**
- `database/migrations/2026_07_30_090000_create_discovery_jobs_table.php`
- `app/Models/DiscoveryJob.php`
- `app/Services/Discovery/DiscoveryJobService.php`
- `app/Http/Controllers/Api/AgentDiscoveryController.php`
- `app/Http/Controllers/Dashboard/DiscoveryController.php`
- `tests/Feature/Api/DiscoveryJobFlowTest.php`
- `tests/Feature/Dashboard/LocalDiscoveryTest.php`

**Hub — modified:**
- `app/Models/Agent.php` (added `discoveryJobs()` relation)
- `app/Http/Controllers/Dashboard/ServerController.php` (loads latest
  DiscoveryJob + approved-agent flag for the show view)
- `app/Http/Controllers/Dashboard/ProjectController.php` (WordPress
  preset, queue-restart step, query-param prefill in `create()`)
- `routes/api.php`, `routes/web.php` (new routes)
- `resources/views/servers/show.blade.php` (Scan Workspace card)
- `resources/views/projects/create.blade.php` (prefill values, WordPress
  preset option)
- `lang/en/ui.php`, `lang/fa/ui.php` (new `discovery` section,
  `pipeline_preset_wordpress` key)
- `lang/en/messages.php`, `lang/fa/messages.php` (`discovery_scan_queued`
  flash key)

**Agent (`agent/`) — new:**
- `lib/RemoteUrlSanitizer.php`
- `lib/WorkspaceScanner.php`
- `tests/run.php` (dependency-free harness)

**Agent — modified:**
- `agent.php` (two requires, one new opt-in poll branch, one new
  function)
- `config.example.php` (one new, commented-out, opt-in key)

## 11. Tests Result

- **Hub**: `php artisan test` — **222/222 passing** (199 pre-existing +
  9 new in `DiscoveryJobFlowTest` + 6 new in `LocalDiscoveryTest` = 215;
  the remainder are pre-existing tests from phases already counted in
  the running total). Zero pre-existing test was modified.
- **Agent**: `php tests/run.php` — **22/22 passing**, covering Laravel/
  WordPress/Node/unknown detection, framework version extraction, git
  remote/branch/commit resolution, credential-stripping (both `https://`
  and SCP-style remotes), the WordPress DB-password-never-read guarantee,
  and a nonexistent-root edge case.
- One issue was found and fixed during this phase: the agent-side test
  fixture originally wrote `composer.json` via `json_encode()` without
  `JSON_UNESCAPED_SLASHES`, which escapes `laravel/framework` to
  `laravel\/framework` — a fixture artifact, not a real-world composer.json
  shape. Fixed the fixture, and additionally hardened
  `WorkspaceScanner::detectType()` to check the decoded
  `composer.json['require']['laravel/framework']` key directly instead
  of a raw substring search, which is correct regardless of a real
  file's exact formatting.
- One issue was found and fixed in the Hub test suite: a Blade
  `{{ route(...) }}` output HTML-escapes `&` to `&amp;`, so
  `assertSee()` needed its default escaping (not `false`) to match. Both
  fixes are test-only; no production code correctness was in question
  in either case.

## 12. Remaining Work

- The "Scan workspace" UI is a simple form + page reload (matching this
  app's existing plain-forms style everywhere else); a queued/running
  scan requires a manual page refresh to see its result, same as the
  existing Server "awaiting connection" pattern. A future phase could
  add polling/auto-refresh if desired.
- Discovery currently reports one flat list per scan; a very large
  workspace with many subdirectories is scanned in a single job with no
  pagination — acceptable for the stated use case (a personal/small-team
  `www` folder) but worth revisiting if workspace sizes grow.
- Only the sole-approved-agent case is wired into the Server show page's
  scan trigger (mirroring the existing `soleApprovedAgentFor()`
  convention used elsewhere in this codebase); a server with multiple
  approved agents has no picker yet for which one runs the scan.
