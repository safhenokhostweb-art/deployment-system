# Phase 3-C.2 — Deployment Domain Execution Integration

Real implementation. Legacy flow preserved by construction (its methods
are extracted, not rewritten) and verified by regression (94/94 tests
passing, all 69 pre-existing tests unmodified). No real data migration
was run, no legacy model or column was removed, no GitHub webhook
behavior changed, and no already-deployed agent needs any change.
`saas-platform` was not read or touched, and no new dependency was added.

## 1. Executive Summary

Phase 3-C.2 connects the additive domain built in Phase 3-C to real
execution, end to end: a webhook or manual trigger on a Project with a
real `Environment`/`DeploymentTarget` now actually fans out into
`DeploymentTask` + `AgentJob` rows, gets claimed by an Agent through the
*same* `/api/agent/jobs/*` endpoints legacy agents already use, executes,
reports back, and rolls the per-target results up into one aggregate
`Deployment` status — including a genuinely new `partial_success` state
for the "two servers updated, one didn't" case the brief's example asks
for.

The single most important finding from this phase (§3, §7) is a gap the
audit itself couldn't have caught by inspection: **a multi-target
`Deployment` aggregate reaching `status = queued` was also directly
claimable by the unmodified legacy claim query**, since that query only
ever checked `server_id` + `status`, with no way to know a "queued"
`Deployment` might actually be a new-domain container whose real work
lives in its `DeploymentTask` children. Left unfixed, a new-domain
deployment could have been claimed and executed *twice*, once correctly
per-target and once incorrectly as a whole. The fix — excluding
deployments that have any tasks from the legacy claim query — is
additive and provably inert for every deployment that existed before
this phase (all of which have zero tasks, always), and is detailed in
§7.

## 2. Pre Implementation Audit

Confirmed immediately before writing code:

- **Controllers**: `GitHubWebhookController` (signature verification,
  event/branch filtering, unchanged in this phase — it only ever calls
  `DeploymentDispatcher::createFromWebhook`, and never needed to know
  about targets itself). `DeploymentController` (dashboard;
  `approve`/`cancel`/`rollback` already just delegate to services).
  `AgentJobController` (the integration point for claiming/reporting).
- **Services**: no `DeploymentService`/`PipelineService`/`WebhookService`
  exist under those names (confirmed again, consistent with every prior
  phase's audit) — the real classes are `DeploymentDispatcher` and
  `AgentJobService`, both from the original MVP.
- **Models**: `Deployment` (legacy, untouched shape), `DeploymentTask`/
  `AgentJob` (Phase 3-C, additive, previously unused by any live path),
  `Project` (untouched).
- **Best integration point**: `DeploymentDispatcher`'s two creation
  entry points (`createFromWebhook`, `createManual`) — both already
  funnel every trigger source through one place, making them the correct
  (and only) point to branch legacy vs. new-domain routing.
- **Which services need to change**: `DeploymentDispatcher` (routing),
  `AgentJobService` (the one-line `whereDoesntHave('tasks')` fix, §7),
  `AgentJobController` (dual resolution), `deployments:detect-stalled`
  (new-domain coverage). `RollbackService` and `GitHubWebhookController`
  do not need to change at all for this phase's scope (§10).
- **How Legacy Flow stays unchanged**: `DeploymentDispatcher`'s legacy
  bodies are *extracted*, not rewritten (§3) — verified by the full
  pre-existing test suite passing without a single modified assertion.

## 3. Legacy Flow Preservation

`createLegacyFromWebhook()`/`createLegacyManual()` in `DeploymentDispatcher`
are the exact, byte-identical bodies of the pre-3-C.2 `createFromWebhook`/
`createManual` methods — only moved into private methods, never edited.
`DeploymentDispatcher::approve()`/`cancel()` gained new-domain-only side
effects (task creation on approval; cascading cancellation) that are
no-ops whenever `$deployment->tasks()` is empty, which is every legacy
deployment, always. `AgentJobController`'s legacy branches
(`AgentJobService::claimNextJob`, `updateStatus`, `recordLog`) are called
exactly as before; the new-domain branch is only ever reached when the
legacy lookup finds nothing. The one required routing-layer change —
`{deployment:public_id}` implicit binding becoming a plain `{id}` string
resolved manually — reproduces Laravel's default 404 behavior exactly
(`AgentJob::where(...)->firstOrFail()` throws the same
`ModelNotFoundException` implicit binding would have), which the full
pre-existing `AgentJobFlowTest` suite passing unmodified directly
verifies.

## 4. New Execution Flow

```
GitHub / manual trigger
        │
        ▼
DeploymentDispatcher.createFromWebhook / createManual
        │
        ▼
DeploymentTargetResolver.resolveTargetsFor(project)
        │
   ┌────┴─────┐
   ▼          ▼
[synthesized] [real targets, possibly empty]
   │              │
   ▼              ▼
legacy path   Deployment (queued or pending_approval)
                   │  (tasks created when it becomes queued —
                   │   immediately if auto_deploy, or at approve())
                   ▼
             DeploymentTask × N (one per active target, status=queued)
                   │
                   ▼   (agent polls /api/agent/jobs/next)
             AgentTaskService.claimNextTask
                   │  (Environment lock check/acquire)
                   ▼
             AgentJob (attempt N, status=running)
                   │
                   ▼   (agent executes, reports back on the SAME endpoints)
             updateStatus → DeploymentTask.status → DeploymentStatusAggregator
                   │
                   ▼
             Deployment.status (succeeded / failed / partial_success)
                   │  (lock released once terminal)
```

## 5. Resolver Integration

`DeploymentTargetResolver` (built in Phase 3-C, previously unused by any
live path) is now called at the start of both `createFromWebhook` and
`createManual`. Its contract is honored exactly as designed and tested
in Phase 3-C: **presence of an `Environment` row, not target count,**
decides the branch (Task 3's "must not fall back to legacy" rule) — a
project with an Environment but zero active targets fails closed
(`abort(422, ...)`, audit-logged as `deployment.rejected_no_active_targets`)
rather than silently creating a legacy-shaped deployment or a
deployment with nothing to run. Inactive targets are excluded by the
resolver itself (unchanged from Phase 3-C), re-verified here through the
dispatcher (`test_inactive_targets_are_never_included`).

## 6. DeploymentTask Creation

One `DeploymentTask` per resolved active target, created at the moment
the parent `Deployment` becomes `queued` — either immediately
(auto_deploy) or inside `approve()` (manual approval) — never at
`pending_approval` time, so nothing is claimable before a human (or
auto_deploy) has actually authorized it. The Phase 3-C unique constraint
on `(deployment_id, deployment_target_id)` is the enforced backstop
against duplicates; application logic never attempts to create a task
twice for the same pair (each creation path runs exactly once per
deployment, guarded by the existing `approve()` status check). Each
task's status is independent and its full `AgentJob` attempt history is
preserved (untouched from Phase 3-C's design).

## 7. AgentJob Integration

**The claim-collision bug and its fix** (found during this
implementation, not anticipated in the audit): a multi-target
`Deployment`'s aggregate `status` can legitimately be `queued` — but
`AgentJobService::claimNextJob` (unmodified from the MVP) only ever
checked `server_id` + `status`, so it would also match and directly
claim that aggregate row, executing it once, wholesale, against
whichever server its (non-authoritative — see §12) `server_id` happens
to hold. **Fix**: added `->whereDoesntHave('tasks')` to that query. Every
Deployment created before this phase, and every legacy deployment
created after it, has zero tasks — so this excludes nothing that isn't
new-domain, and is provably inert for legacy. This was caught by a
failing integration test (`AgentTaskClaimTest`), not by inspection —
worth calling out plainly rather than presenting the design as having
been correct from the start.

**Claiming** (`AgentTaskService::claimNextTask`): scoped to
Agent-authenticated callers only (a legacy Server credential with no
Agent row can never claim a new-domain task — verified explicitly,
`test_legacy_server_credential_never_claims_a_new_domain_task`).
Candidates are locked (`lockForUpdate`), filtered to the polling Agent's
`server_id`, and skipped (not blocked) if their Environment is locked by
a *different* Deployment (§8). The job payload uses the **exact same
wire shape as a legacy job**, including the `project_key` field name —
populated with the `DeploymentTarget`'s `name` — so the existing,
unmodified `agent.php` needs no changes at all to execute a new-domain
job (Task 5's "Legacy AgentJob flow نباید شکسته شود" satisfied by literal
protocol reuse, not just non-interference).

**Retry**: every claim creates a *new* `AgentJob` row
(`attempt = previous count + 1`), never updates a prior one — full
per-attempt history, exactly as Phase 3-C specified.

**Reporting**: `updateStatus`/`recordLog` mirror `AgentJobService`'s
shape; a terminal status additionally updates the parent `DeploymentTask`
and triggers `DeploymentStatusAggregator::recalculate` (§9).

## 8. Lock Integration

Scope: Environment, exactly as designed in Phase 2/3-A. Acquired the
moment the *first* task of a Deployment is claimed
(`environment.locked_by_deployment_id`); a second target of the *same*
Deployment is never blocked by its own lock
(`test_a_second_target_of_the_same_deployment_is_still_claimable`); a
different Deployment's task for the same Environment is skipped, not
errored, so the poll can still find other eligible work
(`test_a_second_deployments_task_is_not_claimable_while_the_environment_is_locked`).
Released the moment the Deployment reaches any terminal aggregate status
(succeeded/failed/partial_success), inside `DeploymentStatusAggregator`.
**Recovery reuses the existing `deployments:detect-stalled` command**,
extended (not duplicated) to also scan `AgentJob` rows for the same
no-activity timeout policy, fail them, propagate to their `DeploymentTask`,
recalculate the aggregate, and thereby release the lock — verified
end-to-end in `DetectStalledAgentJobsTest`, while the original,
legacy-only `DetectStalledDeploymentsTest` continues to pass unmodified.

## 9. Status Aggregation

`DeploymentStatusAggregator::recalculate()`: all tasks succeeded →
`succeeded`; all failed → `failed`; any still `queued`/`running` →
`running`; otherwise (every task terminal, but not uniformly) →
**`partial_success`** (new constant, additive — no existing status value
changed or removed). A Deployment with zero tasks (every legacy one) is
never touched by this method at all. `finished_at` is set once, on the
transition into a terminal aggregate state, and recalculating an
already-correct status is a no-op (verified,
`test_recalculating_an_already_correct_status_does_not_touch_finished_at_again`).

## 10. Rollback Preparation

Per Task 8's explicit instruction, full multi-target rollback was **not**
implemented this phase. `RollbackService` was not touched at all — it
continues to serve only legacy, single-target rollback exactly as
before. The future shape sketched in the brief (`Deployment →
DeploymentTask → Target Selection → Rollback AgentJob`) is a natural
extension of what now exists: a rollback would create a new aggregate
`Deployment` (`action = rollback`) plus `DeploymentTask` rows for an
operator-selected target subset, reusing every mechanism built in this
phase (claiming, locking, aggregation) unchanged. No code was written
toward this; it's recorded here as the concrete starting point for
whichever future phase implements it.

## 11. Security Review

| Control | Status |
|---|---|
| Agent authentication (HMAC, replay protection) | Unchanged — new-domain claiming reuses `VerifyAgentRequestSignature` and `JobPayloadSigner` exactly as built in Phase 3-B, just applied to a new payload source. |
| Authorization boundary | Extended, same shape: `authorizeServerOwnsAgentJob` checks `deploymentTask.deploymentTarget.server_id === authenticated_server.id`, mirroring `authorizeServerOwnsDeployment` exactly. Verified: an agent cannot report status/logs for a task belonging to another server (403). |
| New-domain claiming requires Agent, not just Server | Verified explicitly (§7) — closes a potential gap where a bare legacy credential could otherwise reach new-domain data merely by sharing a `server_id`. |
| Fail-closed on misconfiguration | A new-domain project with zero active targets never creates a deployment at all, rather than creating one with nothing to execute (§5). |
| Secret redaction | `AgentTaskService::recordLog` calls the same `SecretRedactor` as the legacy path — verified with the same live-endpoint redaction test pattern used in Phase 1.5/3-B. |
| Route change (`{deployment:public_id}` → `{id}`) | The manual lookup reproduces Laravel's implicit-binding 404 behavior exactly; no authorization check was weakened — both legacy and new branches still gate on server ownership before any read or write. |

No new vulnerability class was introduced. The claim-collision bug (§7)
was a correctness/isolation defect, not an authentication or
authorization gap — it would have caused a deployment to run twice, not
allowed an unauthorized party to run anything.

## 12. Files Changed

**New:**
```
service/database/migrations/2026_07_20_090000_add_execution_integration_columns.php
service/app/Models/AgentJobLogEntry.php
service/app/Services/Deployment/DeploymentStatusAggregator.php
service/app/Services/Deployment/AgentTaskService.php
service/app/Http/Controllers/Dashboard/DeploymentTaskController.php
service/resources/views/deployment-tasks/show.blade.php
service/tests/Feature/Deployment/MultiTargetDispatchTest.php
service/tests/Feature/Api/AgentTaskClaimTest.php
service/tests/Feature/Deployment/EnvironmentLockingTest.php
service/tests/Feature/Deployment/DeploymentStatusAggregationTest.php
service/tests/Feature/Console/DetectStalledAgentJobsTest.php
docs/PHASE_3C2_EXECUTION_INTEGRATION_REPORT.md
```

**Modified:**
```
service/app/Models/AgentJob.php             (+public_id, +last_activity_at, +logEntries(), +isTerminal())
service/app/Models/Environment.php          (+lock columns, +lockedByDeployment(), +isLockedByAnotherDeployment())
service/app/Models/Deployment.php           (+STATUS_PARTIAL_SUCCESS, isTerminal() includes it)
service/app/Services/Deployment/DeploymentDispatcher.php (routing branch; legacy bodies extracted unmodified)
service/app/Services/Deployment/AgentJobService.php      (+whereDoesntHave('tasks') — see §7)
service/app/Http/Controllers/Api/AgentJobController.php  (dual resolution for next/updateStatus/appendLog)
service/app/Http/Controllers/Dashboard/DeploymentController.php (eager-load tasks/targets/agents for the show view)
service/app/Console/Commands/DetectStalledDeployments.php (extended for AgentJob, existing legacy behavior unchanged)
service/routes/api.php                       ({deployment:public_id} → {id} for status/log)
service/routes/web.php                       (+deployment-tasks.show route)
service/resources/views/layouts/app.blade.php (+.badge-partial_success CSS)
service/resources/views/deployments/show.blade.php (+Targets card, shown only when tasks exist)
```

No file under `Project`, `GitHubWebhookController`, `ProcessGitHubWebhookPush`,
`RollbackService`, `VerifyAgentRequestSignature`, or `agent/` (the PHP
agent itself) was touched.

## 13. Tests Result

```
tests: 94, passed: 94, assertions: 230
```

69 pre-existing tests, unmodified, still passing. 25 new tests:

- `MultiTargetDispatchTest` (7): legacy zero-tasks behavior, auto_deploy
  immediate task creation, deferred creation until approval, fail-closed
  on zero active targets, inactive-target exclusion, DB-level duplicate
  task prevention, cascading cancellation.
- `AgentTaskClaimTest` (6): full claim → status → log flow through the
  shared endpoints, legacy-job priority when both sources have eligible
  work, cross-server authorization rejection, legacy credential never
  reaching new-domain data.
- `EnvironmentLockingTest` (4): lock acquired on first claim, a second
  Deployment's task blocked while locked, a second target of the *same*
  Deployment unblocked, lock released on terminal aggregate status.
- `DeploymentStatusAggregationTest` (6): all-succeeded, all-failed,
  mixed → partial_success, any-running → running, legacy (task-less)
  deployment untouched, idempotent recalculation.
- `DetectStalledAgentJobsTest` (2): stalled AgentJob → task → aggregate
  → lock release chain; a healthy job left alone.

Two test-authoring bugs were found and fixed while getting to green
(wrong exception class expected for `abort()`; a static factory-property
captured after being overwritten by a later factory call) — both are
noted here rather than silently corrected, since they're a useful
reminder for whoever writes the next batch of tests against these same
factories.

## 14. Remaining Risks

- **`deployments.server_id` on a multi-target aggregate is not
  authoritative** — populated only to satisfy the column's existing
  `NOT NULL` constraint (arbitrarily, the first resolved target's
  server). Deliberately *not* fixed by making the column nullable in
  this phase, to avoid a `->change()`-based migration and its
  driver-dependent `doctrine/dbal` requirement (consistent with the
  no-unnecessary-dependency rule and the same reasoning applied in
  Phase 1.5). Anything reading `Deployment.server_id` directly for a
  multi-target row will see a misleading single value — nothing in this
  codebase does that today outside the (now-guarded) legacy claim query,
  but it's a sharp edge for future code to be aware of.
- **Webhook-delivery-level deduplication was not implemented.** Task
  11's "duplicate webhook rejection" is interpreted and tested here as
  the DB-level duplicate-task constraint (§6) and the existing
  signature/branch-matching rejections already covered by the untouched
  `GitHubWebhookTest` suite — true idempotency against GitHub redelivering
  the identical event (e.g. keyed on `X-GitHub-Delivery`) was not built,
  since it wasn't architected in any prior phase and would be new scope.
  Flagging explicitly rather than silently narrowing the requirement.
- **Environment doesn't yet have its own pipeline/branch/auto_deploy.**
  New-domain deployments still read `Project.pipeline`/`.branch`/
  `.auto_deploy` (Phase 3-C's own simplification, unchanged here) — every
  Environment of a Project currently runs the identical pipeline. Fine
  for this phase's scope; a real per-environment pipeline override is a
  reasonable next addition.
- **A failed webhook-triggered fail-closed rejection (§5) retries up to
  3 times** before the queue job's own failure handling logs it (reusing
  `ProcessGitHubWebhookPush`'s existing Phase-1.5 retry/backoff
  infrastructure) — harmless (the condition is deterministic, retries
  are wasted, not damaging) but slightly inefficient; not worth
  suppressing retries for a single edge case in this phase.
- **Claim-fairness between legacy and new-domain sources is still
  "legacy first, always"** (Phase 3-C's own audit flagged this as an
  open question, not resolved here) — under heavy load with both sources
  busy, new-domain jobs could be starved behind a legacy backlog on the
  same server. Not a problem at today's scale; worth revisiting if/when
  it is.

## 15. Phase 4 Recommendation

- Implement multi-target rollback (§10) now that claiming, locking, and
  aggregation all exist and are tested — the remaining work is
  target-selection UI/API plus reusing `DeploymentDispatcher`'s
  new-domain creation path with `action = rollback`.
- Give `Environment` its own `branch`/`pipeline`/`auto_deploy` (with
  `Project`'s values as the fallback/default) so different environments
  of one project can genuinely diverge.
- Revisit the `deployments.server_id` non-authoritative value (§14) —
  either accept it long-term with clear documentation, or plan the
  nullable-column migration deliberately (with its `doctrine/dbal`
  implications assessed up front) as part of Phase 3-A's eventual
  Phase 3-D cleanup.
- Consider real webhook-delivery deduplication (§14) if GitHub redelivery
  in practice ever produces an observed duplicate deployment — not
  worth building speculatively before that's a real, observed problem.
- Dashboard polish (real-time task status polling, capability display
  alongside targets) — this phase's UI is functional but intentionally
  minimal per Task 9's own instruction.
