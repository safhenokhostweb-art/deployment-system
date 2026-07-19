# Phase 3-C Implementation — Deployment Domain Foundation

Real implementation of the architecture confirmed in
`docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md`, scoped strictly to
*foundation*: new domain tables, models, and the compatibility adapter —
no orchestrator, no webhook cutover, no locking (those remain later
stages, per that document's own roadmap). No real data migration was
run, no legacy model was removed, no `Deployment`/`Project`/webhook
behavior changed, and every one of the 57 pre-existing tests passes
unmodified. `saas-platform` was not read or touched, and no new
Composer/npm dependency was added.

> **Note on the source message**: this phase's instructions cut off
> mid-Task-3 (the `DeploymentTargetResolver` diagram wasn't finished, and
> no Task 4+ or closing rules section followed). The cut-off portion —
> the "new/real target" branch of the resolver, and the overall
> foundation-only scope — was already fully specified in the previously
> committed `PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md` §4, which this message
> explicitly cited as the basis for implementation. I built against that
> existing, already-approved design rather than pausing, since nothing
> in the missing text represented an open decision — flagging this here
> for visibility rather than treating it silently.

## 1. Pre Implementation Audit

Confirmed immediately before writing code: schema and test baseline
unchanged since the Phase 3-B implementation commit — 57/57 tests
passing, `Project` still has only `server()`/`deployments()`/
`latestSuccessfulDeployment()`, no `Job`/`Pipeline` model exists (pipeline
is still inline JSON + inline validation), and no
`DeploymentService`/`JobService`/`PipelineService`/`WebhookService`
classes exist under those literal names (as already noted in
`PHASE_3A_DOMAIN_MIGRATION_AUDIT.md` §2 — the real classes are
`DeploymentDispatcher`, `AgentJobService`, `GitHubWebhookController`).
None of this needed rediscovery; this phase's job was to build on top of
it without disturbing it, which is what §2 below does.

## 2. New Deployment Domain Models (as built)

```
Project ──< Environment ──< DeploymentTarget ──< DeploymentTask ──< AgentJob
                                  │                     │
                                Server                Deployment (existing)
                                  │
                                Agent (nullable)
```

- **`Environment`**: `project_id`, `name`, `key` (agent/webhook-facing
  identifier, unique per project), `status` (`active`/`disabled`).
- **`DeploymentTarget`**: `environment_id`, `server_id`, `agent_id`
  (nullable — a target can exist before an agent is assigned/approved
  for it), `name` (unique per environment), `is_active`.
- **`DeploymentTask`**: `deployment_id`, `deployment_target_id`,
  `status` (`queued`/`running`/`succeeded`/`failed`/`cancelled`).
- **`AgentJob`**: `deployment_task_id`, `agent_id`, `attempt` (unique per
  task — every retry is a new row, never an update to a prior one, per
  the brief), `status`, `started_at`, `finished_at`.

**One simplification found during implementation, not in the original
audit**: `DeploymentTask.deployment_id` references the **existing**
`App\Models\Deployment` directly, rather than introducing a second
"Deployment" entity as `PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md` §3 had
flagged as a naming risk to resolve. Task 2's literal field list here —
just `deployment_id`, `deployment_target_id`, `status` — doesn't call for
a new aggregate at all; it reads naturally as "one legacy-shaped
`Deployment` row can now optionally fan out to N tasks." This is simpler,
avoids the naming-collision problem entirely (there is only ever one
`Deployment` class), and required no import of a duplicate concept. All
four new models were added purely additively: `Project::environments()`,
`Deployment::tasks()`, `Server::deploymentTargets()`, and
`Agent::deploymentTargets()`/`agentJobs()` are new relation methods only
— nothing existing on any of those four models was touched.

## 3. DeploymentTargetResolver (the adapter)

```php
DeploymentTargetResolver::resolveTargetsFor(Project $project): array<TargetView>
```

- **No `Environment` row at all for the Project** (true for every
  Project that exists today): returns exactly one **synthesized,
  non-persisted** `TargetView`, built from `$project->server_id` and
  `$project->project_key` — nothing is written to `environments`/
  `deployment_targets`. `targetKey` reuses `project_key` verbatim, so an
  already-installed agent's `config.php` mapping needs no change even if
  this project is promoted to a real Environment later.
- **A real `Environment` row exists**: returns the actual `is_active`
  `DeploymentTarget` rows for it — even if that set is empty. Presence of
  the Environment row, not target count, decides the branch, so a
  half-configured new project resolves to an empty list rather than
  silently falling back to legacy behavior (which would mask the
  misconfiguration).
- `TargetView` is a plain, non-Eloquent, `readonly`-property DTO
  specifically so it's structurally impossible to `->save()` a
  synthesized view by mistake (per the audit's own risk note).

Nothing in `GitHubWebhookController`, `DeploymentDispatcher`, or
`AgentJobService` calls this resolver yet — wiring it into the actual
dispatch/claim path is explicitly out of this foundation phase's scope
(it's Phase 3-C.2 territory per the original audit's roadmap), and doing
so wasn't requested in this message's task list.

## 4. Files Changed

**New:**
```
service/database/migrations/2026_07_19_100000_create_deployment_domain_tables.php
service/app/Models/Environment.php
service/app/Models/DeploymentTarget.php
service/app/Models/DeploymentTask.php
service/app/Models/AgentJob.php
service/app/Services/Deployment/TargetView.php
service/app/Services/Deployment/DeploymentTargetResolver.php
service/database/factories/EnvironmentFactory.php
service/database/factories/DeploymentTargetFactory.php
service/database/factories/DeploymentTaskFactory.php
service/database/factories/AgentJobFactory.php
service/database/factories/DeploymentFactory.php   (was missing entirely — see §6)
service/tests/Feature/Models/DeploymentDomainRelationshipsTest.php
service/tests/Feature/Deployment/DeploymentTargetResolverTest.php
docs/PHASE_3C_IMPLEMENTATION_REPORT.md
```

**Modified (additive only in every case):**
```
service/app/Models/Project.php    (+environments() relation)
service/app/Models/Deployment.php (+tasks() relation)
service/app/Models/Server.php     (+deploymentTargets() relation)
service/app/Models/Agent.php      (+deploymentTargets(), +agentJobs() relations)
```

No file under `DeploymentDispatcher`, `RollbackService`,
`GitHubWebhookController`, `AgentJobController`,
`VerifyAgentRequestSignature`, or any route/webhook/dashboard file was
touched.

## 5. Database Changes

Four new tables only — `environments`, `deployment_targets`,
`deployment_tasks`, `agent_jobs`. `deployment_tasks.deployment_id` is a
foreign key onto the **existing** `deployments` table
(`cascadeOnDelete()` — a task has no meaning once its parent deployment
is gone). No column was added to, removed from, or altered on
`projects`, `servers`, `deployments`, or `deployment_log_entries`.

## 6. Compatibility Verification

- `test_legacy_project_with_no_environment_resolves_to_one_synthesized_target`
  and `test_target_key_continuity_if_a_project_is_later_promoted` directly
  verify the §3 behavior against real (factory-created) `Project` rows —
  not just asserted in prose.
- The full pre-existing 57-test suite passing unmodified is itself the
  regression proof that `Deployment`, `Project`, webhook handling, and
  Agent auth are byte-for-byte unaffected.
- Found and fixed incidentally: **no `DeploymentFactory` existed at all**
  before this phase (every prior test built `Deployment` rows via
  explicit `Deployment::create([...])`, never `Deployment::factory()`).
  `DeploymentTaskFactory`'s natural default (`'deployment_id' =>
  Deployment::factory()`) needed one to exist, so a minimal one was added
  — test-only, no production behavior affected, and it doesn't change
  how any existing test builds its fixtures (they still use the explicit
  form, unmodified).

## 7. Tests Result

```
tests: 69, passed: 69, assertions: 171
```

57 pre-existing tests, unmodified, still passing. 12 new tests:

- `DeploymentDomainRelationshipsTest` (8): Project↔Environment,
  Environment↔DeploymentTarget↔Server, DeploymentTask↔Deployment
  (confirming it's the *existing* model, not a new one),
  AgentJob↔DeploymentTask/Agent with multi-attempt history, three unique-
  constraint tests (`environments.key` per project, `deployment_targets.name`
  per environment, `agent_jobs.attempt` per task), and nullable
  `DeploymentTarget.agent_id`.
- `DeploymentTargetResolverTest` (4): legacy synthesis, real-target
  resolution with inactive-target exclusion, the empty-list-not-fallback
  edge case, and `target_key` continuity across a simulated promotion.

## 8. Next Phase Recommendation

Per the original `PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md` roadmap, this
implementation covers exactly the 3-C.1 slice. Remaining, explicitly
deferred:

- **3-C.2**: `MultiTargetDeploymentOrchestrator`, the webhook
  `{projectKey}` → `Environment.key` adapter switch (still `Project`-only
  today — §3's resolver exists but nothing calls it yet), `AgentTaskService`
  for claiming `DeploymentTask`/`AgentJob` rows, and the extended single
  poll-endpoint handler checking both legacy and new sources.
- **3-C.3**: Environment-scoped locking, extended stalled-detection
  covering `AgentJob` rows, per-target rollback.

Both remain no-ops today by construction (nothing calls the new tables
or the resolver from any live request path yet), so there is no urgency
pressure on sequencing beyond what the original roadmap already
recommended.
