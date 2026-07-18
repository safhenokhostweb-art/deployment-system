# Phase 1.5 — Production Hardening Report

Scope: `c:\laragon\www\deployment-system` only. No file outside this
directory was read for modification purposes, and `saas-platform` was not
touched. No new Composer/npm dependency was added — every change below
uses functionality already built into Laravel or plain PHP.

## 1. Audit — state before this pass

| Area | Before | Verdict |
|---|---|---|
| Git | No repository — the MVP existed only as working-tree files | Needed baselining |
| Queue | `QUEUE_CONNECTION=database`, `jobs`/`failed_jobs`/`job_batches` tables present (Laravel defaults) | Configured but **unused** — nothing was ever dispatched to it; GitHub webhook processing ran fully inline |
| Scheduler | `routes/console.php` had only the default `inspire` command | **Empty** — no recurring maintenance existed at all |
| Agent liveness | `last_seen_at` updated on every authenticated call (via middleware) | Partial — no dedicated heartbeat, no agent version visibility, no offline signal in the UI |
| Logging | `DeploymentLogEntry` already structured (step/command/output/exit_code/duration); agent already truncates to last 50k chars before sending | Structure was fine; **no secret redaction, no retention/pruning** — tables grow forever |
| Security | HMAC request signing, job-payload signing, nonce replay protection, token hashing, cross-server authorization all already implemented and covered by tests | Re-verified, no defects found (§7) |

Nothing above was a regression risk in itself — the MVP was internally
consistent — but "queue configured with nothing using it" and "no
scheduler at all" meant the service had no real resilience against a
crashed agent, a stuck deployment, or unbounded table growth. That's what
this pass closes.

## 2. Git baseline

- `git init` at `deployment-system/` (confirmed no parent repo — this is
  its own history, independent of `saas-platform`).
- Root `.gitignore` added (OS/editor cruft + a defense-in-depth backstop
  for `.env`/`config.php`/`*.key` patterns; `service/.gitignore` and
  `agent/.gitignore` already existed and do the detailed work).
- First commit: 131 files, everything reviewed to confirm no `vendor/`,
  `node_modules/`, `.env`, `database.sqlite`, or `agent/config.php` leaked
  into the index before committing.
- Annotated tag `v0.1.0-mvp-complete`.

## 3. Queue infrastructure

- **Reviewed**: the database queue driver was configured but every write
  path (including GitHub webhook → deployment creation) ran synchronously
  inline. That means a transient DB hiccup during a webhook delivery had
  no retry — it just failed the request.
- **Change**: webhook signature verification, event filtering, and branch
  matching stay synchronous (cheap, and GitHub's delivery UI benefits from
  an immediate, specific response). Only the deployment-creation write is
  now dispatched as `App\Jobs\ProcessGitHubWebhookPush` (`ShouldQueue`).
  The webhook endpoint now responds **202 Accepted** instead of 201, since
  the deployment row is created asynchronously in production (the `sync`
  queue driver used in tests still creates it immediately, so existing
  assertions needed only a status-code update, not new setup).
- **Retry strategy**: `$tries = 3`, `$backoff = [30, 120, 300]` (30s → 2m
  → 5m).
- **Timeout handling**: `$timeout = 30` on the job (well under the
  database queue's `retry_after = 90s` default, so a worker can't
  double-process a job that's merely slow).
- **Failed job handling**: `failed_jobs` table already existed (Laravel
  default migration). Added `failed(Throwable $exception)` on the job,
  which writes an `AuditLogEntry` (`webhook.deployment_dispatch_failed`)
  so a permanent failure is visible in the dashboard's audit trail, not
  just in `php artisan queue:failed`.
- **Worker configuration**: `docs/ops/queue-worker.supervisor.conf.example`
  + `docs/ops/README.md` — supervisor-managed `queue:work` with
  `--max-time=3600` (auto-restart hourly to bound memory growth),
  `stopasgroup`/`killasgroup` so a stuck job's child process doesn't
  orphan.

## 4. Scheduler

Three scheduled commands, wired in `routes/console.php` via the
`Schedule` facade, each with `withoutOverlapping()` (a slow run can't
double-execute) and `onOneServer()` (safe if this app is ever deployed to
more than one node — uses the existing `database` cache store's atomic
locks, no new infra):

| Command | Cadence | Purpose |
|---|---|---|
| `agent-nonces:prune` | hourly | Deletes replay-protection nonces past the retention window (default 2h — well past the 5-minute signature timestamp window) |
| `deployments:detect-stalled` | every 5 min | Fails a `running` deployment with no activity for 30+ min (agent crashed/lost connectivity mid-job) |
| `deployment-system:prune-logs` | daily 03:00 | Retention policy, see §6 |

`docs/ops/scheduler.crontab.example` + `docs/ops/README.md` document the
single required cron line (`schedule:run` every minute).

## 5. Agent hardening

- **Heartbeat mechanism**: new `POST /api/agent/heartbeat` endpoint
  (same `agent.signed` middleware — full bearer+HMAC+replay protection,
  no weaker than any other agent call). The agent now sends it once per
  poll cycle, before checking for jobs, and a failure to heartbeat never
  blocks job polling (wrapped in its own try/catch in `agent.php`).
- **Agent version tracking**: `servers.agent_version` column; the agent
  reports a version constant (`AGENT_VERSION = '1.1.0'` in `agent.php`)
  on every heartbeat; shown in both `servers/index` and `servers/show`.
- **last_seen status**: unchanged mechanism (updated on every
  authenticated call), now also visibly surfaced next to agent version.
- **Offline detection**: `Server::isOnline()` — computed from
  `last_seen_at` against a configurable threshold
  (`deployment.server_offline_threshold_seconds`, default 90s — 6× the
  agent's default 15s poll interval). Deliberately computed on read, not
  a stored/scheduled flag: always accurate, no extra writes, no race
  between a scheduled check and the dashboard being viewed. Shown as an
  online/offline badge in both server views.

## 6. Logging

- **Standardization**: the existing `DeploymentLogEntry` shape (sequence,
  step_name, command, output, exit_code, duration_ms, timed_out) was
  already consistent across every code path; no schema change was needed
  here.
- **Secret redaction**: new `SecretRedactor` service, applied in
  `AgentJobService::recordLog()` to both `command` and `output` **before**
  they're written to the database — not just at display time, so a
  redacted secret never reaches storage or backups. Catches `key=value`
  / `key: value` assignments naming an obvious secret (api_key, token,
  password, secret, access_key, private_key — keeping the key name,
  masking only the value), GitHub tokens (`ghp_`/`gho_`/`ghu_`/`ghs_`/
  `ghr_`), AWS access key IDs (`AKIA...`), and `Bearer <token>` values.
  Documented as **defense in depth, not a guarantee** — pipeline steps
  only ever receive `{branch}`/`{commit}`/`{working_directory}`/
  `{php_binary}` placeholders (no vault credential is ever interpolated
  into a command in this architecture), so real secret exposure risk was
  already low; this catches the case of a project author pasting one in
  by mistake, or a build tool echoing one to stdout.
- **Retention policy**: `deployment-system:prune-logs` (daily) purges the
  **output text only** of `DeploymentLogEntry` rows belonging to
  finished deployments older than `deployment_log_output_retention_days`
  (default 90) — step name, exit code, and duration are kept indefinitely
  for history. `AuditLogEntry` rows are pruned separately, much later
  (`audit_log_retention_days`, default 365), since they're the
  compliance/forensic trail rather than operational noise.

## 7. Security review

Re-examined with fresh eyes; the existing implementation was already
correct going in. Findings:

| Control | Verdict | Notes |
|---|---|---|
| HMAC request validation | **Confirmed correct** | Canonical string (`METHOD\npath\ntimestamp\nnonce\nsha256(body)`) identical on both sides; `hash_equals` throughout (constant-time); path normalized to a leading slash on both the agent (`Client.php`) and the service (`VerifyAgentRequestSignature`) |
| Replay protection | **Confirmed correct** | 300s timestamp window + one-time nonce enforced by a DB unique constraint (`(server_id, nonce)`), so it's atomic even under concurrent requests — not just an application-level check |
| Token storage | **Confirmed correct, one note** | `agent_token` stored only as a SHA-256 hash (irreversible, Sanctum-style). `signing_secret` and `github_webhook_secret` are necessarily stored *encrypted* (not hashed) — HMAC verification requires the plaintext, unlike a login credential. This is architecturally required, not a defect; it makes `APP_KEY` confidentiality and backup a hard operational requirement (already called out in `service/README.md`) |
| Authorization boundaries | **Confirmed correct** | `deployment.server_id === authenticated_server.id` enforced (403) before any status/log write; agent-settable statuses allowlisted to `running`/`succeeded`/`failed` (can't set `queued`/`cancelled`/etc.) |
| New surface (heartbeat) | **No gap introduced** | Sits behind the same `agent.signed` middleware as every other agent endpoint — full bearer+HMAC+replay protection, verified by test |
| Queued job payload | **No gap introduced** | `ProcessGitHubWebhookPush` serializes the `Project` via Laravel's model-reference serialization (class + key), not its encrypted `github_webhook_secret` value — the secret never sits in the `jobs`/`failed_jobs` table text |

No new vulnerability was introduced by this pass, and no previously
undetected one was found.

## 8. Tests

```
tests: 37, passed: 37, assertions: 79
```

12 new tests added this pass (heartbeat auth + `isOnline()` threshold
behavior, all three scheduled commands, secret redaction — both as a
focused unit test and as an integration test through the real log-append
endpoint, and the queued job's handle/retry-config/failed-audit behavior)
on top of the 25 pre-existing ones. No existing test's *assertions*
changed — only the two webhook tests' expected HTTP status moved from
`assertCreated()` (201) to `assertAccepted()` (202), which is the direct,
intended consequence of §3, not a weakening of what's being verified.

## 9. Files changed

**New:**
```
.gitignore
docs/ops/README.md
docs/ops/queue-worker.supervisor.conf.example
docs/ops/scheduler.crontab.example
service/config/deployment.php
service/database/migrations/2026_07_25_090000_add_hardening_columns.php
service/app/Services/Security/SecretRedactor.php
service/app/Jobs/ProcessGitHubWebhookPush.php
service/app/Console/Commands/PruneAgentNonces.php
service/app/Console/Commands/DetectStalledDeployments.php
service/app/Console/Commands/PruneDeploymentData.php
service/tests/Feature/Api/AgentHeartbeatTest.php
service/tests/Feature/Console/PruneAgentNoncesTest.php
service/tests/Feature/Console/DetectStalledDeploymentsTest.php
service/tests/Feature/Console/PruneDeploymentDataTest.php
service/tests/Unit/Services/Security/SecretRedactorTest.php
```

**Modified:**
```
agent/agent.php
service/app/Models/Server.php
service/app/Models/Deployment.php
service/app/Services/Deployment/AgentJobService.php
service/app/Http/Controllers/Api/AgentJobController.php
service/app/Http/Controllers/Api/GitHubWebhookController.php
service/routes/api.php
service/routes/console.php
service/resources/views/servers/index.blade.php
service/resources/views/servers/show.blade.php
service/tests/Feature/Api/GitHubWebhookTest.php
service/tests/Feature/Api/AgentJobFlowTest.php
```

**Not touched:** anything under `c:\laragon\www\saas-platform`, and no
`composer.json`/`package.json` dependency was added or upgraded anywhere
in `deployment-system`.
