# Phase 3-D — Production Safety & Operational Readiness

Real implementation. Every change is additive; the legacy single-target
flow and the Phase 3-C.2 multi-target flow are both verified unchanged by
the full pre-existing test suite passing unmodified (94/94), plus 23 new
tests (117/117 total). No real data migration was run, no legacy column
or model was removed, no already-deployed agent was invalidated, and no
new Composer/npm dependency was added (the health check provider reuses
Laravel's already-bundled HTTP client). `saas-platform` was not read or
touched.

## 1. Executive Summary

Phase 3-D closes the gap between "the domain works end-to-end" (Phase
3-C.2) and "this is safe to run against real production traffic and
real servers." Concretely: GitHub redeliveries can no longer create
duplicate deployments; a new-domain task can now opt into bounded,
audited auto-retry instead of failing on the first transient error;
per-target rollback — explicitly deferred in Phase 3-C.2 — is now real;
a basic health-check framework exists to verify a deploy actually worked,
not just that the pipeline exited zero; and a secret-handling audit
found and fixed a real gap (a webhook secret was being redisplayed in
full on every dashboard visit) before it became a problem rather than
after.

## 2. Pre Audit

Confirmed immediately before writing code:

- **`GitHubWebhookController`**: verifies signature, records
  `webhook.received`, filters ping/non-push/branch-mismatch, then
  dispatches `ProcessGitHubWebhookPush`. No delivery-level deduplication
  existed — a GitHub redelivery (same `X-GitHub-Delivery`, e.g. after a
  timeout) would have created a second `Deployment` for the same push.
- **`DeploymentDispatcher`/`AgentJobService`/`DeploymentTask`**: as left
  by Phase 3-C.2 — routing branch intact, `whereDoesntHave('tasks')` fix
  in place, `AgentJob` per-attempt rows already modeled but with no
  policy driving more than one attempt per task.
- **`RollbackService`**: legacy-only (`rollbackTo`/`rollbackToPrevious`),
  exactly as Phase 3-C.2 left it — per-target rollback was explicitly
  out of that phase's scope.
- **Secrets**: `Server`/`Agent` credentials already followed a correct
  "shown once, hashed/encrypted, never redisplayed" pattern (Phase 1.5/
  3-B). `Project.github_webhook_secret`, however, was found (§7) to be
  redisplayed in full, unmasked, on every visit to `projects.show` — the
  one real gap this audit surfaced, not previously flagged in any prior
  phase's security review.

## 3. Webhook Idempotency

New `webhook_deliveries` table: `delivery_id` (unique — GitHub's
`X-GitHub-Delivery`, identical across a redelivery of the same event),
`project_id`, `event`. `GitHubWebhookController::handle()` records the
delivery ID immediately after signature verification, before any
further processing; a unique-constraint violation (caught the same way
`AgentRequestNonce` already handles replay) means "already processed" —
audited as `webhook.duplicate_delivery_rejected` and answered with a
plain 200 (not an error — GitHub should not retry further). **A request
with no `X-GitHub-Delivery` header skips this check entirely and behaves
exactly as before** — every pre-existing `GitHubWebhookTest` request
omits this header and all of them still pass unmodified, which is the
concrete proof this is additive, not a behavior change for anything that
doesn't send it (in practice, only tests — real GitHub always sends it).

## 4. Retry Policy

`deployment.task_max_attempts` (default **1** — no auto-retry, matching
the Phase 2 design rationale that a deployment pipeline isn't guaranteed
idempotent) and `deployment.task_retry_backoff_seconds` (default
`30,120,300`). New `deployment_tasks.next_retry_at` column: on a failed
`AgentJob`, `AgentTaskService::handleFailedAttempt()` checks
`attempt < max_attempts` — if true, the task returns to `queued` with a
backoff delay (not claimable until it passes) instead of going straight
to `failed`; if false (including every deployment at the default config,
preserving today's exact behavior), it fails and the aggregate
recalculates exactly as Phase 3-C.2 built it. Every retry is a **new**
`AgentJob` row (never an update to a prior one), so the brief's example
— attempt 1 FAILED, attempt 2 FAILED, attempt 3 SUCCESS — is exactly
what querying a task's `agentJobs` now shows, verified directly
(`TaskRetryTest::test_full_example_two_failures_then_success_...`).
Legacy (`AgentJobService`) has no concept of attempts beyond what it
already had and was not touched.

## 5. Rollback Architecture

`RollbackService` gains two new methods — `rollbackTargetTo(DeploymentTask
$targetTask, ...)` and `rollbackTargetToPrevious(DeploymentTarget
$target, ...)` — additive to the class; `rollbackTo`/`rollbackToPrevious`
are untouched. A target rollback creates a new aggregate `Deployment`
with **exactly one** `DeploymentTask`, for the same `DeploymentTarget`
only — verified directly
(`TargetRollbackTest::test_rolling_back_one_target_does_not_touch_a_sibling_target`)
by asserting a sibling target's task/status/deployment_id are unchanged
after the rollback. It reuses every mechanism Phase 3-C.2 already built
(claiming, environment locking, aggregation) with zero special-casing —
the Environment lock is acquired/released for a target rollback exactly
as for any other deployment, which is correct: it still shouldn't run
concurrently with another in-flight deployment touching the same
Environment. Fully audited (`deployment_task.rollback_triggered`).

## 6. Health Check Design

Framework only, per the brief's explicit instruction — one concrete
provider, not a library. `HealthChecker` interface
(`check(DeploymentTask, array $config): HealthCheckResult`);
`HttpStatusHealthChecker` (fetches a URL, compares status code) as the
proof of concept, using Laravel's already-bundled HTTP client (no new
package); `HealthCheckService` resolves a checker by `type` string and
records a `DeploymentHealthCheck` row (`pending` → `success`/`failed`,
with `result_message`). **Deliberately not wired automatically into the
deploy flow** — it's manually triggered from the Task Detail dashboard
page in this phase. Rationale: automatic wiring needs a place to
configure "does this deployment want a health check and with what
config," which would mean adding a config surface to `Environment` (which
doesn't have one yet, per Phase 3-C's own scope decision) — doing that
properly is more than "framework," so it's named explicitly here and in
§13 as the natural next step rather than rushed into this phase.

## 7. Secret Handling Review

| Finding | Before | After |
|---|---|---|
| `Project.github_webhook_secret` on `projects.show` | Displayed in full, in plaintext, on every page load | Masked (first/last 4 characters only) by default; shown in full only immediately after creation or rotation (session-flashed, same pattern as Server/Agent credentials) |
| Webhook secret recovery if lost | None — the only way to see it again was reading the database directly | New `POST /projects/{project}/rotate-webhook-secret` — generates a fresh one, shown once, old one invalidated immediately, audited (`project.webhook_secret_rotated`) |
| Server/Agent credential serialization | Already correct (`$hidden` on both models) | Re-verified with an explicit regression test (`SecretHandlingTest::test_agent_and_server_credentials_are_never_serialized_in_json_responses`) rather than left as an unverified assumption |
| Log/audit redaction (`SecretRedactor`) | Already applied at write time (Phase 1.5/3-C.2) | Unchanged — reviewed, no gap found |

No exception message, audit log entry, or notification event payload
added in this phase includes a raw secret value — `DeploymentFailed`/
`DeploymentSucceeded`/`RollbackCompleted`/`DeploymentStarted` all carry
only the `Deployment` model reference (whose `$hidden`/`$fillable`
already exclude anything credential-shaped) and, for `DeploymentFailed`,
the agent-supplied failure message (already redacted before storage
where it originates from pipeline output).

## 8. Notification Foundation

Four events, no listener registered for any of them (Task 7's explicit
"Provider فعلاً لازم نیست"): `DeploymentStarted` (dispatched when a
deployment reaches `queued` — both immediately, for auto-approved
paths, and via `DeploymentDispatcher::approve()`), `DeploymentSucceeded`/
`DeploymentFailed` (legacy: `AgentJobService::updateStatus`; new domain:
`DeploymentStatusAggregator::recalculate`, with `partial_success` also
firing `DeploymentFailed` — a mixed-outcome rollout needs attention just
as much as a fully failed one), and `RollbackCompleted` (fires instead
of `DeploymentSucceeded` when the succeeding deployment's `action` is
`rollback`, in both the legacy and new-domain success paths). Verified
with `Event::fake()` (`NotificationFoundationTest`) rather than merely
asserted — including the specific rollback-vs-normal-success branch.
Dispatching an event with no listener is inert by construction, which is
why this was safe to add directly into the legacy `AgentJobService`
branch as well, not just the new-domain one.

## 9. Dashboard Changes

- **Deployment Detail**: now shows the Deployment ID (`public_id`)
  explicitly, a "Rollback status" row (links to whichever later
  deployment rolled this one back, if any), and the Targets/Tasks table
  gained an "Attempts" count column and an inline "retry scheduled
  <time>" badge when `next_retry_at` is set.
- **Task Detail**: gained a "Roll back this target to this commit"
  button (shown only when the task succeeded) and a "Health check"
  section — a small form to run an `http_status` check plus the history
  of prior checks for that task.
- **Project page**: webhook secret masked by default with a "Rotate"
  action (§7).
- **Agent Online Status / Last Heartbeat / Version**: already present
  since Phase 3-B — re-verified, not re-built.

## 10. Security Review

| Control | Status |
|---|---|
| Webhook idempotency | New table, unique-constrained; doesn't weaken signature verification (still the first check, unchanged) — idempotency is a second, later gate |
| Retry policy | Off by default; opting in is an operator config decision, not automatic; every attempt is independently audited |
| Per-target rollback | Requires the target's own task to have actually succeeded (`abort_unless`); fully audited; isolated to one target by construction (a new Deployment with exactly one Task) |
| Health checks | Manual-trigger only in this phase — no automatic code execution path was added; the one provider makes an outbound HTTP request only to an operator-supplied URL, at request time, under the authenticated dashboard session |
| Secret handling | Webhook secret gap (§7) found and fixed; credential serialization re-verified with a test, not just re-read |
| Notification events | Carry no raw secret in any payload (§7) |

No new vulnerability class was introduced. The webhook secret masking
fix is the one finding in this phase that falls squarely under "found a
real gap, not just reviewed and confirmed."

## 11. Files Changed

**New:**
```
service/database/migrations/2026_07_21_090000_create_production_readiness_tables.php
service/app/Models/WebhookDelivery.php
service/app/Models/DeploymentHealthCheck.php
service/app/Events/DeploymentStarted.php
service/app/Events/DeploymentSucceeded.php
service/app/Events/DeploymentFailed.php
service/app/Events/RollbackCompleted.php
service/app/Services/HealthCheck/HealthChecker.php
service/app/Services/HealthCheck/HealthCheckResult.php
service/app/Services/HealthCheck/HttpStatusHealthChecker.php
service/app/Services/HealthCheck/HealthCheckService.php
service/tests/Feature/Api/WebhookIdempotencyTest.php
service/tests/Feature/Deployment/TaskRetryTest.php
service/tests/Feature/Deployment/TargetRollbackTest.php
service/tests/Feature/Deployment/NotificationFoundationTest.php
service/tests/Feature/HealthCheck/HealthCheckServiceTest.php
service/tests/Feature/Dashboard/SecretHandlingTest.php
docs/PHASE_3D_PRODUCTION_READINESS_REPORT.md
```

**Modified:**
```
service/app/Http/Controllers/Api/GitHubWebhookController.php   (+idempotency check)
service/app/Models/DeploymentTask.php                           (+next_retry_at, +healthChecks())
service/app/Services/Deployment/AgentTaskService.php            (+retry policy, +next_retry_at handling)
service/app/Services/Deployment/RollbackService.php             (+rollbackTargetTo, +rollbackTargetToPrevious)
service/app/Services/Deployment/DeploymentDispatcher.php        (+DeploymentStarted dispatch at each queued transition)
service/app/Services/Deployment/AgentJobService.php             (+Succeeded/Failed/RollbackCompleted dispatch)
service/app/Services/Deployment/DeploymentStatusAggregator.php  (+Succeeded/Failed/RollbackCompleted dispatch)
service/app/Http/Controllers/Dashboard/ProjectController.php    (webhook secret masking + rotation)
service/app/Http/Controllers/Dashboard/DeploymentTaskController.php (+rollback, +runHealthCheck actions)
service/config/deployment.php                                   (+task_max_attempts, +task_retry_backoff_seconds)
service/routes/web.php                                          (+3 new dashboard routes)
service/resources/views/projects/show.blade.php                (masked secret, rotate button, shown-once flash)
service/resources/views/deployments/show.blade.php              (Deployment ID, rollback status, attempts column)
service/resources/views/deployment-tasks/show.blade.php         (rollback button, health check section)
```

No file under `Project` (model), `Server`, `Agent`, `Environment`,
`DeploymentTarget`, `VerifyAgentRequestSignature`, or the PHP agent
itself (`agent/`) was touched.

## 12. Tests Result

```
tests: 117, passed: 117, assertions: 293
```

94 pre-existing tests, unmodified, still passing. 23 new tests, all
passing on the first full run after implementation:

- `WebhookIdempotencyTest` (3): same delivery ID → one deployment;
  different delivery IDs → separate deployments; missing header →
  unchanged behavior.
- `TaskRetryTest` (3): default config fails immediately (regression
  guard); opted-in retry schedules a new attempt and respects the
  backoff window; the brief's exact three-attempt example.
- `TargetRollbackTest` (4): sibling-target isolation; rejection of
  rolling back a target that never succeeded; audit logging; legacy
  rollback unaffected.
- `HealthCheckServiceTest` (5): success, failure, connection exception
  handling, unknown type rejection, result persistence/audit.
- `SecretHandlingTest` (4): masking on ordinary loads, full display
  once after creation, rotation behavior, credential serialization
  regression guard.
- `NotificationFoundationTest` (4): `DeploymentStarted` on queue,
  `DeploymentSucceeded`/`DeploymentFailed` on legacy terminal status,
  `RollbackCompleted` firing instead of `DeploymentSucceeded` for a
  rollback action.

## 13. Remaining Risks

- **Health checks are manual-trigger only** (§6) — genuinely useful
  today, but "automatically run after every successful deploy" requires
  an `Environment`-level (or `DeploymentTarget`-level) configuration
  surface that doesn't exist yet. Flagged, not silently deferred.
- **Retry policy is global, not per-Environment** — `task_max_attempts`/
  `task_retry_backoff_seconds` apply to every new-domain task
  system-wide. A per-Environment override is the natural next increment,
  same shape as the pipeline/branch/auto_deploy limitation Phase 3-C
  already flagged.
- **Notification events have no listener** — by design in this phase,
  but worth remembering they are currently unobservable outside the
  audit log until a real provider (email/Slack/webhook) is built.
- **Webhook idempotency is delivery-ID based only** — it protects
  against GitHub's own redelivery mechanism, not against a determined
  actor replaying a captured, validly-signed request body with a new
  delivery ID (out of scope: GitHub controls delivery IDs, an attacker
  replaying a webhook still needs the project's real secret to produce
  a valid signature in the first place, which this phase's threat model
  already covers).
- **`deployments.server_id` non-authoritative on multi-target rows**
  (carried over from Phase 3-C.2, unchanged here, still applies to
  target-rollback deployments too since they're multi-target-shaped).

## 14. Phase 4 Recommendation

Per this phase's title, Phase 3-D was explicitly the prerequisite for
**Phase 4 (Persian Admin UX + Onboarding Wizard)**. With idempotency,
retry, per-target rollback, a health-check foundation, and a closed
secret-handling gap now in place, Phase 4 can focus purely on UX without
needing to revisit operational safety concurrently. Suggested order
within Phase 4 or immediately after:

- Localize the dashboard (Task 8 of this phase explicitly deferred
  full localization — "نیازی به فارسی‌سازی کامل در این Phase نیست").
- Build the onboarding wizard on top of an already-safe system (no
  wizard step should be able to create a deployment that bypasses
  idempotency/locking/retry, since none of them were bypassable by
  construction to begin with).
- Consider wiring a first real notification provider (§13) once there's
  a concrete channel requirement (email, Slack, etc.) — the foundation
  is ready and tested; only the provider is missing.
- Consider per-Environment retry and health-check configuration once
  Environment gains its own config surface (a recurring, consistently
  deferred item across Phase 3-C, 3-C.2, and now 3-D — worth addressing
  as its own focused piece rather than deferring a fourth time).
