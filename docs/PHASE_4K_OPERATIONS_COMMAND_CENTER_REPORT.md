# Phase 4-K: Operations Command Center + Server Health Foundation

## 1. Executive Summary

Phase 4-K turns "SSH into the server and run commands by hand" into
"pick a pre-approved operation from the panel" — a new Operations
Command Center (`/operations`), a per-server Operations section, and an
operation detail/timeline page, backed by a strictly registry-limited
set of maintenance commands (cache clear, config/route cache, queue
restart, disk check, service status, system health) executed through
the *existing* Agent poll channel. No new trust boundary was
introduced: an approved Agent could already execute arbitrary commands
from a Project's own `pipeline` column for every deployment — the
Operations Registry is a strictly narrower, pre-approved subset of that
same capability, never a raw command string off the network. A Server
Health Foundation (CPU/memory/disk, best-effort) was added on the
*existing* heartbeat call, no new endpoint. All 265 pre-existing tests
pass unmodified; 19 new Hub tests + 7 new agent-side checks were added
(284/284 Hub, 29/29 agent).

## 2. Pre-Implementation Audit

**Command execution capability — did it already exist?** Yes, but only
inside the deployment pipeline: `agent/lib/Executor.php` already runs
an arbitrary shell command (`proc_open`, with a timeout, in a working
directory) for each step of a Project's stored `pipeline` JSON. This is
the *only* command-execution code in the Agent. Confirmed no other
execution path exists anywhere in `agent/` or `app/Services`.

**Job handling patterns available to reuse:** `AgentJobController`
(legacy `Deployment`/new-domain `AgentJob`), `AgentDiscoveryController`
(Phase 4-H's `DiscoveryJob` — scan-and-report, no execution). The
Discovery pattern is the closer match: a job with no
commit/branch/pipeline concept, claimed and reported over the same
signed channel, Agent-identity-only. `DiscoveryJobService`'s
queue/claimNext/reportResult/reportFailure shape was mirrored directly
for `OperationJobService`.

**Scheduler:** `DetectStalledDeployments` (deployment) and, as of Phase
4-J's hardening pass, no operation-equivalent existed — confirmed no
prior art for "detect a state change and act" beyond that one command,
which this phase's new `agents:detect-offline`-adjacent concern (Server
Health) does *not* need, since health is reported proactively on
heartbeat, not detected by a sweep.

**Notification System (Phase 4-J):** `NotificationEventSubscriber`
(`Event::subscribe()` in `AppServiceProvider`), `NotificationService`
(`notify`/`notifyAdmins`), `Notification` model with `TYPE_*`/
`SEVERITY_*` constants, `NotificationVisibilityFilter` (re-checks
current Gate/Policy state before display) — all reused as-is, extended
with three new types and one new `match` arm.

**Best model for an Operation Job:** a new, independent entity
(`OperationJob`), not a repurposed `AgentJob` or `DiscoveryJob` — same
reasoning as Discovery before it: an operation has no
commit/branch/pipeline/deployment_task_id, and forcing it into either
existing shape would mean nullable columns that don't apply. Purely
additive migration.

**How to add this without a new security path:** don't add one — reuse
`agent.signed` middleware, `VerifyAgentRequestSignature`'s existing
Agent-identity resolution, `RequestSigner`'s HMAC scheme, and
`AgentRequestNonce`'s replay protection completely unmodified. The only
new thing on the wire is an `operation_type` string (a registry key,
validated independently on both ends) — never a command.

## 3. Architecture Decision

**Option A (Command Job on the existing Agent channel) — decided
without ambiguity.** The brief's own criteria (Agent-Based Execution,
No Central SSH Trust, Security-First) and final rule ("SSH به عنوان
مسیر اصلی اضافه نشود") rule out Option B (SSH Executor) outright, and
Option C (Hybrid) would mean building and maintaining a second
credential/trust model for zero benefit — the Agent already runs *on*
the target server and already executes commands for deployments, so a
second execution path (SSH) would be redundant surface area, not a
capability gain. Option A also required zero new infrastructure: same
signed channel, same claim/report shape as Discovery, same Executor
class reused unmodified.

## 4. Operation Job Design

New `operation_jobs` table (migration
`2026_08_02_090000_create_operation_jobs_table.php`):

| column | purpose |
|---|---|
| `server_id` / `agent_id` | which server, and which specific Agent must run it |
| `project_id` | nullable — only Laravel-group operations need one (see §5) |
| `operation_type` | a registry key (e.g. `cache_clear`) — never a command |
| `status` | `queued`\|`running`\|`succeeded`\|`failed` |
| `requested_by` | dashboard user email (mirrors `deployments.triggered_by` / `discovery_jobs.requested_by`) |
| `output` / `error_message` | sanitized (SecretRedactor) result |
| `started_at` / `finished_at` | for the timeline (Task 8) and duration display |

`App\Models\OperationJob` holds `STATUS_*` constants,
`durationSeconds()`. `App\Services\Operations\OperationJobService`
(`queue`/`claimNext`/`reportResult`/`reportFailure`) is the only writer,
dispatches `OperationStarted`/`OperationSucceeded`/`OperationFailed`,
and re-validates `operation_type` against the registry at `queue()`
time (defense in depth — the Dashboard controller already checked it
too).

A Laravel-group operation (needs a project's `working_directory`/
`php_binary`) can only run when a Server hosts **exactly one** Project
— new `Server::soleProject()` mirrors `ProjectController::
soleApprovedAgentFor()`'s existing "only offer this when it's
unambiguous" convention, checked across both the legacy direct link
and the new-domain Environment/DeploymentTarget path. Zero or multiple
candidates means the Command Center simply doesn't offer that
operation for that server — no error, no guessing.

## 5. Allowed Operations Registry

`App\Support\Operations\OperationRegistry` (Hub) and
`agent/lib/OperationRegistry.php` (Agent — a separate runtime, no
shared autoload, so it mirrors the Hub's keys rather than importing
them) are the **only** two places an `operation_type` maps to anything:

| type | group | needs project |
|---|---|---|
| `cache_clear`, `config_cache`, `route_cache`, `queue_restart` | Laravel | yes |
| `disk_check`, `service_status`, `system_health` | Server | no |

The Hub-side registry never holds a command string at all — only
metadata (label/group/requires_project) used for the UI and for
`queue()`'s validation. The **Agent-side** registry is the only place
an `operation_type` ever resolves to an actual shell command template
(e.g. `cache_clear` → `{php_binary} artisan cache:clear`), exactly
mirroring how `SecretRedactor`/`RemoteUrlSanitizer` needed independent
copies on each side before. This means: (1) the wire only ever carries
a `type` key, never a command (2) even a compromised or buggy Hub could
never make an up-to-date Agent run anything outside this fixed list,
since the Agent decides the command, not the Hub (3) an unrecognized
type is rejected independently by both sides before anything executes.

## 6. Agent Execution Flow

```
Hub (OperationController::store)
  → OperationJobService::queue() [validates operation_type against registry]
  → Agent polls GET /api/agent/operation-jobs/next  [same agent.signed middleware]
  → claimNext() flips status to running, dispatches OperationStarted
  → Agent validates operation_type against ITS OWN registry (defense in depth)
  → Agent resolves {working_directory}/{php_binary} from config.php's existing
    'projects' map (Laravel-group) or uses a neutral temp dir (Server-group)
  → Executor::run() [same class deployment pipeline steps already use]
  → POST /api/agent/operation-jobs/{id}/report
  → reportResult()/reportFailure() redacts output (SecretRedactor), dispatches
    OperationSucceeded/OperationFailed
```

Zero changes to `VerifyAgentRequestSignature`, `RequestSigner`,
`AgentRequestNonce`, or `Signer`/`JobVerifier` on the Agent side.
Polling is **not** opt-in (unlike Discovery's `workspace_root` flag) —
an operation only ever executes when an authenticated Admin/Operator
explicitly queued it; the poll itself is a no-op when nothing is
queued, exactly like the primary deployment-job poll. A legacy agent
binary that predates this phase simply has no code path for it at all
— polling this endpoint requires the updated `agent.php`.

**Server Health Foundation (Task 11):** `agent/lib/HealthCollector.php`
— best-effort, every metric independently degrades to `null` (never
fatal) on an unsupported platform. Reads only OS-exposed aggregates
(`sys_getloadavg()`, `/proc/meminfo`, `disk_total_space()`/
`disk_free_space()`) — never a file listing, never anything
process/user-specific. Sent as three new **optional** fields on the
*existing* `POST /api/agent/heartbeat` call — no new endpoint, no new
poll cycle. Three new nullable columns on `agents`
(`cpu_load_percent`/`memory_usage_percent`/`disk_usage_percent`/
`health_reported_at`), populated only when an Agent actually sends
them. A pre-4-K agent binary that never sends these fields leaves them
`null` forever, exactly as if this phase had never run.

## 7. Command Center UI

- `GET /operations` — one card per Server: online/offline badge, its
  sole project (if any), its latest operation, and (gated by
  `operations.execute`) one button per available operation type —
  Server-group always, Laravel-group only when `soleProject()`
  resolves.
- `POST /servers/{server}/operations` — queues a job; `abort_unless`
  (not Laravel's `validate()` `in:` rule — see the bug this avoided in
  §13) keeps every failure mode in this method a direct, immediate HTTP
  status rather than mixing that with a redirect-based
  `ValidationException` for one field only.
- `GET /operations/{operationJob}` — detail + timeline (Task 8) + full
  (already-redacted) output.
- **Server Detail (Task 7):** a new "Recent operations" card on
  `servers/show.blade.php` — the trigger form (when an approved Agent
  exists) plus the 10 most recent operations with a link to each
  detail page. The existing Agents table also gained a compact Health
  column (Task 11's display surface).

## 8. Permission Integration

Three new dot-notation abilities, resolved by the existing
`Gate::before()` hook — no new Gate/Policy code needed:

| ability | Admin | Operator | Developer | Viewer |
|---|---|---|---|---|
| `operations.view` | ✓ (wildcard) | ✓ | ✓ | ✓ |
| `operations.execute` | ✓ (wildcard) | ✓ | — | — |
| `operations.manage` | ✓ (wildcard) | — | — | — |

Matches Task 9's example exactly (Viewer: مشاهده, Operator: اجرا,
Admin: مدیریت). Developer isn't named in Task 9 at all; given its
existing "can view everything" role description, `operations.view`
without `.execute` was the consistent choice (mirrors how Developer
already gets `deployments.view` without `deployments.rollback`).
`operations.manage` has no distinct UI action in this foundation phase
beyond what Admin's `'*'` wildcard already covers — documented here
rather than inventing an artificial manage-only feature just to
exercise the string.

## 9. Notification Integration

Three new types (`operation.started`/`operation.success`/
`operation.failed`, `Notification::TYPE_OPERATION_*`) wired into the
existing `NotificationEventSubscriber` via three new events
(`OperationStarted`/`OperationSucceeded`/`OperationFailed`), dispatched
from `OperationJobService`'s own claim/report methods — not from a
controller. Audience mirrors the existing deployment-notification
pattern exactly: every Admin, plus the requesting user (by
`requested_by` email) if resolvable. `NotificationVisibilityFilter`
gained one new `match` arm (`OperationJob::class => Gate::forUser($user)
->allows('operations.view')`), the same role-only pattern already used
for Server/Agent/GitHubAccount (no per-resource Policy exists for any
of those either — Phase 4-G's deliberate choice, matched here rather
than invented anew).

## 10. Health Foundation

Covered in full in §6. Deliberately **not** a monitoring system — no
history, no charts, no alerting thresholds, no scheduled polling beyond
the existing heartbeat cadence. Exactly what Task 11 asked for: Agent
can report OS/CPU/memory/disk/status, nothing more, laying the
groundwork for a future Monitoring phase without building any of its
UI or alerting logic prematurely.

## 11. Security Review

- **No free command execution, by construction, twice over.** The wire
  only ever carries an `operation_type` string. `OperationController::
  store()` and `OperationJobService::queue()` both reject an unknown
  type before a job is even created; the Agent independently rejects
  an unknown type again before resolving any command. Neither side
  ever receives or forwards a command string from the other.
- **No direct shell input from a user, ever.** The only user-supplied
  value in this entire feature is a dropdown-selected `operation_type`
  key — never interpolated into a command string; the actual command
  templates are hardcoded PHP constants on the Agent, with only
  `{working_directory}`/`{php_binary}` substituted from the Agent's own
  local `config.php` (the same substitution mechanism deployment
  pipeline steps already use, unchanged).
- **No password ever stored.** `OperationJob` has no credential column
  of any kind.
- **No credential ever displayed.** Operation `output`/`error_message`
  are passed through `SecretRedactor::redact()` before persisting —
  verified by `test_reported_output_is_redacted_before_persisting`
  (a fake GitHub-token-shaped string in agent-reported output never
  survives to the stored row). `CredentialRotated` (Phase 4-J,
  unmodified) still carries no secret value.
- **Signature validation reused unchanged** — `VerifyAgentRequestSignature`,
  `RequestSigner`, `AgentRequestNonce` untouched; verified by
  `test_invalid_signature_is_rejected` (a tampered signature on the new
  `/operation-jobs/next` endpoint gets 401, same as every other
  agent-signed route).
- **Permission checks enforced and verified** — `test_viewer_can_see_
  the_command_center_but_not_execute`, `test_operator_can_execute_a_
  server_group_operation`, `test_developer_cannot_execute_an_operation`.
- **Audit log integration** — `OperationJobService` calls
  `AuditLogEntry::record()` on queue/succeed/fail (actions
  `operation_job.queued`/`succeeded`/`failed`), mirroring
  `DiscoveryJobService`'s exact convention — "what caused this, and
  who/what agent" is always answerable from the audit log the same way
  it already is for every other job type.
- **Cross-agent isolation** — an agent cannot report on another agent's
  OperationJob (403), verified by test, same ownership check pattern
  as `AgentJobController`/`AgentDiscoveryController`.

## 12. Files Changed

**New:**
- `database/migrations/2026_08_02_090000_create_operation_jobs_table.php`
- `database/migrations/2026_08_02_090100_add_health_columns_to_agents_table.php`
- `app/Models/OperationJob.php`
- `database/factories/OperationJobFactory.php`
- `app/Support/Operations/OperationRegistry.php`
- `app/Services/Operations/OperationJobService.php`
- `app/Events/{OperationStarted,OperationSucceeded,OperationFailed}.php`
- `app/Http/Controllers/Api/AgentOperationController.php`
- `app/Http/Controllers/Dashboard/OperationController.php`
- `resources/views/operations/{index,show}.blade.php`
- `agent/lib/{OperationRegistry,HealthCollector}.php`
- `tests/Feature/Api/OperationJobFlowTest.php`
- `tests/Feature/Dashboard/OperationsCommandCenterTest.php`

**Modified:**
- `app/Models/{Agent,Server}.php` (health columns/fillable, `operationJobs()`, `soleProject()`)
- `app/Models/Notification.php` (`TYPE_OPERATION_*`)
- `app/Listeners/NotificationEventSubscriber.php` (3 new event handlers)
- `app/Support/Notification/NotificationVisibilityFilter.php` (`OperationJob::class` match arm)
- `app/Support/Authorization/PermissionMatrix.php` (`operations.view`/`.execute`)
- `app/Http/Controllers/Api/AgentJobController.php` (heartbeat accepts optional health fields)
- `routes/api.php` (`/operation-jobs/next`, `/operation-jobs/{id}/report`), `routes/web.php` (`/operations` routes)
- `resources/views/servers/show.blade.php` (Operations section, Agent health column)
- `resources/views/layouts/app.blade.php` (sidebar `nav.operations` link)
- `agent/agent.php` (operation poll + execution, heartbeat health collection)
- `agent/tests/run.php` (7 new checks)
- `lang/{en,fa}/{ui,nav,notifications,messages}.php`

## 13. Tests Result

- Hub: `php artisan test` — **284/284 passing** (265 pre-existing,
  unmodified, + 19 new across `OperationJobFlowTest` and
  `OperationsCommandCenterTest`).
- Agent: `php tests/run.php` — **29/29 passing** (22 pre-existing + 7
  new covering `OperationRegistry`'s allow/deny behavior and
  `HealthCollector`'s always-safe return shape).
- One real bug was found and fixed during testing:
  `OperationController::store()` originally validated `operation_type`
  with Laravel's `'in:...'` validation rule. For a non-JSON request,
  a failed `in:` rule throws `ValidationException`, which Laravel
  converts to a `302` redirect-back-with-errors — not the `422` every
  other guard in that same method returns via `abort_if`. The mismatch
  surfaced as a rendering error when the test framework tried to
  process the unexpected redirect chain. Fixed by switching to a plain
  `abort_unless(OperationRegistry::isAllowed(...), 422, ...)` check,
  which is also more consistent with Task 4/12's "never trust a
  validated-then-forgotten value" posture — the type is checked and
  used immediately, not passed through a generic validator.

## 14. Remaining Work

- No per-operation-type audit of *who can run which type* beyond the
  blanket `operations.execute` — e.g. there's no way to allow Operator
  to run `disk_check` but not `queue_restart`.
- The Command Center has no live/auto-refreshing status for a
  currently-running operation (matches this app's existing zero-JS,
  refresh-based pattern everywhere else).
- Server Health has no history or trend — only the latest reported
  value per Agent, overwritten on every heartbeat.
- Laravel-group operations are only offered when a server has exactly
  one resolvable project — a server hosting multiple projects has no
  picker to choose which one.

## 15. Next Phase Recommendation

The natural next step is the Monitoring phase Task 11 explicitly
prepared for: historical health data (a `agent_health_readings` table
or similar, sampled on a schedule rather than overwritten in place),
threshold-based alerting feeding into the existing Notification Center
(a new `agent.health_critical` type would slot in exactly like
`agent.offline` did), and a chart on the Server detail page. A second,
independent follow-up would be a project picker for Laravel-group
operations on multi-project servers, once that scenario is common
enough to justify the added UI.
