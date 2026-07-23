# Phase 4-I: Deployment Experience & Release Management + Persian Font Integration

## 1. Executive Summary

Phase 4-I turns the Deployment detail page from a flat data dump into a
professional release-management view — an overview card in the exact
shape the brief specified (Project/Environment/Target/Commit/Branch/
Triggered By/Status), a chronological Timeline, a compact pipeline
step visualizer (✓/⏳/○/✗), a live-status panel (current step, running
time, error), a lightweight optional Release concept (label + notes on
a Deployment), a from/to Rollback confirmation, and a new "Recent
Releases" Dashboard widget. Every panel view now renders in Vazirmatn,
loaded from self-hosted font files with no external CDN dependency.

All of this was built on data that already existed — status timestamps,
per-step log entries, existing Gate/Policy checks — plus exactly one
small, additive migration (two nullable columns) for the Release
concept. Nothing about the Deployment Engine, Agent architecture,
GitHub integration, or permission system changed. All 222 pre-existing
tests pass unmodified; 12 new tests were added (234/234 total).

## 2. Pre-Implementation Audit

**Deployment domain, as it existed before this phase:**
- `Deployment`: full lifecycle status (`pending_approval → queued →
  running → succeeded/failed/cancelled/partial_success`), timestamps
  (`queued_at`/`started_at`/`finished_at`/`last_activity_at`),
  `commit_hash`/`previous_commit_hash`/`branch`,
  `trigger`/`triggered_by`/`approved_by`/`approved_at`,
  `rollback_of_deployment_id`, `failure_message`. No version/release
  concept existed anywhere.
- `DeploymentTask` (per-target) / `AgentJob` (per-attempt): same shape,
  new-domain analog, with retry/backoff already built (Phase 3-C.2).
- `DeploymentLogEntry` / `AgentJobLogEntry`: per-pipeline-step rows —
  `sequence`, `step_name`, `command`, `output`, `exit_code`,
  `duration_ms`, `timed_out`, plus a `created_at` timestamp from
  `$table->timestamps()`. **Already redacted at write time** by
  `SecretRedactor` in both `AgentJobService::recordLog()` (legacy) and
  `AgentTaskService::recordLog()` (new domain) — confirmed unchanged in
  this phase.
- `RollbackService`: `rollbackTo()`/`rollbackToPrevious()` (legacy,
  whole-deployment) and `rollbackTargetTo()`/`rollbackTargetToPrevious()`
  (per-target). Both already compute an explicit "current commit" vs.
  "target commit" internally — this phase's UI surfaces exactly that
  same computation *before* the click, not a new business rule.
- `DeploymentController`/`DeploymentTaskController`: `show()` already
  Gate-authorizes via `deployments.view` + `Gate::authorize('view',
  $project)` (Phase 4-G resource scoping) — unchanged.

**What was backend-only with no UI before this phase:**
- Per-step `duration_ms`/`timed_out` existed in the database but was
  only shown as trailing inline text (`(123ms)`), never as a visual
  step sequence or timeline.
- There was no derivable "what step is running right now" signal in the
  UI at all — an operator had to infer it from the last log line and
  guess.
- No release/version concept existed anywhere in the schema or UI.

**Where to add the Release Experience:** directly on `Deployment` via
two new nullable columns, not a new table/model — the brief's own Task
7 explicitly asked for "no big change," and every field a Release
"needs" (Commit/Branch/Created By) already exists on `Deployment`; the
only genuinely new information is an optional human label and free-text
notes.

**Font audit:** exactly one place in the whole app declares any
`font-family` — the single `<style>` block in
`resources/views/layouts/app.blade.php` — confirmed via
`grep -r font-family resources/views` (the only other hit is
`welcome.blade.php`, Laravel's default unused starter-kit landing page,
never routed to by this app and left untouched as out of scope). This
means "no scattered font CSS to unify" was already true; the work was
purely additive. A `vite`/`tailwindcss`/`Instrument Sans` scaffold also
exists in `resources/css|js/` and `vite.config.js` but is dead code —
referenced only by `welcome.blade.php`, with no `@vite` directive
anywhere in the actual application layout, and no build step (`npm run
build`) in this app's actual dev/deploy flow. Wiring the font through
that unused pipeline would have added a real new dependency (a required
`npm install`/`npm run build` step) for an app that otherwise runs with
zero JS build tooling — so the font was self-hosted as static files
under `public/fonts/vazirmatn/` instead, exactly matching how
`public/favicon.ico` already sits directly in `public/`.

## 3. Deployment UX Design

`deployments/show.blade.php` and `deployment-tasks/show.blade.php` were
restructured (not rewritten from scratch) into, top to bottom:

1. **Overview** — the existing table content, reorganized to lead with
   the brief's exact field list (Project/Environment/Target/Commit/
   Branch/Triggered By/Status) before the more detailed rows (deployment
   ID, previous commit, rollback lineage, approver, server, raw
   timestamps) that already existed.
2. **Live status** (Task 5) — only rendered while not terminal, or on a
   failed deployment: current step, running time, error message, last
   log line (truncated, already-redacted text only).
3. **Pipeline steps** (Task 4) — a compact ✓/⏳/○/✗ row.
4. **Timeline** (Task 3) — a vertical, chronological list: queued →
   started → each completed step (with its real timestamp) → completed/
   failed.
5. The existing per-target table and full raw pipeline log
   (command/output) are kept, unchanged, immediately below — the new
   visual layer is additive, not a replacement for the detailed log a
   power user still needs.

## 4. Pipeline Visualization

New, pure view-model class: `App\Support\Deployment\PipelineStepPresenter`
(`app/Support/Deployment/PipelineStepPresenter.php`). It writes nothing
and adds no deployment state — it merges two things that already
existed:
- The project's stored pipeline definition (`project.pipeline`, an
  ordered `{name, command}` array).
- Whatever log entries have been recorded so far, matched by
  `sequence` (the agent numbers steps 1..N in pipeline order — confirmed
  in `agent/agent.php`'s `$sequence++` loop, unchanged).

For each pipeline position it derives one of five statuses:
`succeeded`/`failed` (a log entry exists, by exit code/timeout),
`running` (no entry yet, but it's the very next un-logged step and the
overall run is still `running`), `pending` (hasn't started, run isn't
stopped), or `skipped` (run ended — failed/cancelled — before reaching
this step). The same class serves both the legacy `Deployment` (via
`DeploymentLogEntry`) and the new-domain `AgentJob` (via
`AgentJobLogEntry`) call sites without depending on either concrete
model, since both expose the same
`sequence`/`step_name`/`exit_code`/`timed_out`/`duration_ms`/`created_at`
shape.

Per-step start time is approximated as `created_at - duration_ms` (the
log row's timestamp is when the Hub received the *completed* step
report) — an honest, label-free approximation, not a new column.

## 5. Log Experience

Task 5's "current step / last log / error / execution time" panel reads
entirely from data Task 4's presenter already computed
(`PipelineStepPresenter::currentStep()`) plus existing `Deployment`
timestamps and `failure_message`. The "last log" line shows only
`step_name` and a length-limited (`Str::limit(..., 120)`) `output` —
the exact same, already-redacted `output` column the untouched raw log
section below it displays in full. No new field, no new write path, no
new redaction logic was introduced; this panel is strictly a second,
friendlier view of already-sanitized data.

## 6. Release Foundation

**Migration reported here, as required:**
`database/migrations/2026_07_31_090000_add_release_fields_to_deployments_table.php`
adds two nullable columns to the existing `deployments` table:
`release_label` (`string(100)`) and `release_notes` (`text`). Purely
additive — every existing row gets `null` in both, and
`Deployment::displayLabel()` falls back to a short commit hash whenever
`release_label` is absent, so nothing in the UI ever shows a blank
"Release" field.

- `DeploymentDispatcher::createManual()` gained one new, optional
  `?array $release = null` parameter. It does **not** touch the body of
  `createLegacyManual()`/`createLegacyFromWebhook()`/`createMultiTarget()`
  at all (their documented "exact, unmodified body" invariant from
  Phase 3-C.2 stays true) — instead, a release label/notes is applied
  via one conditional follow-up `$deployment->update([...])` call after
  the existing creation path returns, and only when at least one of the
  two fields was actually supplied.
- `ProjectController::deployManual()` validates two new, fully optional
  fields (`release_label` nullable/max:100, `release_notes`
  nullable/max:2000) alongside the existing required `commit_hash`.
- The manual-deploy form (`projects/show.blade.php`) gained two optional
  inputs. Webhook-triggered and rollback-triggered deployments still
  never set a label (there's no reasonable UI moment to collect one for
  either), and correctly fall back to the commit hash everywhere they're
  displayed.

## 7. Rollback UX

Both rollback confirmation dialogs (`deployments.show`'s whole-deployment
rollback and `deployment-tasks.show`'s per-target rollback) now show an
explicit **From → To** pair before the user confirms, computed the exact
same way `RollbackService` itself computes it internally:
- Whole-deployment: *From* = `$deployment->project->latestSuccessfulDeployment()`
  (mirrors `RollbackService::rollbackTo()`'s own `$currentCommit`
  lookup); *To* = the deployment being viewed.
- Per-target: *From* = that target's own latest successful task's
  deployment (mirrors `RollbackService::rollbackTargetTo()`'s own
  `$currentCommit` lookup); *To* = the task's deployment.

Both labels use `Deployment::displayLabel()`, so a labeled release shows
`v1.2.0 → v1.1.9` and an unlabeled one gracefully shows commit hashes
instead. `RollbackService` itself, its permission checks
(`Gate::authorize('rollback', $project)`, unchanged), and the
per-target-vs-whole-deployment distinction were not touched — this task
was presentation-only, made possible by extending the existing
`x-confirm-dialog` component to optionally render slot content (a table)
in addition to its existing `:body` string prop; every pre-4-I call site
still only passes `:body` and renders identically.

## 8. Dashboard Changes

Confirmed already present before this phase (Phase 4-C):
"Latest Deployments" (the existing Recent Deployments widget),
"Deployment Health" (the existing Health Overview card — problem
agents/deployments/recent errors), and "Failed Deployments" (surfaced
both in Health's `problemDeployments`/`recentErrors` and directly
visible via status badges on Recent Deployments). Task 9's only
genuinely new addition is **Recent Releases**:
`DashboardService::recentReleases()` — succeeded deployments only,
newest `finished_at` first — rendered as a new card showing each
release's label (or commit fallback), project, triggering user, and
relative time, each linking straight to its deployment detail page.

## 9. Vazirmatn Font Integration

- Four static weights (Regular/400, Medium/500, SemiBold/600, Bold/700)
  downloaded from the official `vazirmatn` npm package (v33.0.3, SIL
  Open Font License — `OFL.txt` bundled alongside) as `.woff2` files,
  checked in under both `resources/fonts/vazirmatn/` (source, per the
  brief's example path) and published to `public/fonts/vazirmatn/` (the
  actually-servable path, since this app has no asset build step — see
  §2's font audit).
- Four `@font-face` rules, one per weight, added to the single
  `<style>` block in `layouts/app.blade.php` — `font-display: swap` on
  each so text never sits invisible while a weight loads, and each rule
  scoped to one weight so the browser only fetches the weight(s) a given
  page actually renders with.
- `html, body { font-family: 'Vazirmatn', Tahoma, "Segoe UI", Arial,
  sans-serif; }` plus an explicit `button, input, textarea, select,
  table { font-family: inherit; }` — stated explicitly rather than
  relying on inheritance, since form controls don't inherit body's font
  in every browser's UA stylesheet by default. This is also the exact
  element list the brief named.
- One `<link rel="preload" ... as="font">` for the Regular weight (the
  one almost all body text renders in) — Medium/SemiBold/Bold are used
  far more sparingly (headings/labels/badges) and load on demand instead,
  per the brief's "optimal loading" requirement.
- The pre-existing, redundant `font-family: inherit` on the
  `input[type=text], ...` rule was removed now that the global rule
  above already covers it — the one piece of "unify, don't duplicate"
  cleanup the audit found actionable.
- **Deliberately left as monospace, not switched to Vazirmatn**: `pre`,
  `code`, `.secret-box`. These render commit hashes, shell commands, and
  tokens — LTR technical content where monospace alignment is the
  correct choice, not "scattered font CSS." The brief's own element list
  (body/html/button/input/textarea/select/table) doesn't include these,
  and this was a deliberate exception, not an oversight.

## 10. Security Review

- **No secret ever newly exposed.** Every new UI surface (overview,
  timeline, steps, live status, release label/notes, rollback dialogs,
  Recent Releases) renders only: step names, statuses, timestamps,
  durations, commit hashes, branch names, user-supplied release
  label/notes, and a length-limited `output` string that was already
  passed through `SecretRedactor::redact()` at write time before this
  phase existed. No new field reads a token/password/credential column,
  and no server/agent credential model was touched.
- **Redaction path re-verified, not just assumed** —
  `test_redacted_secret_never_appears_in_the_live_status_last_log_line`
  writes a log entry through the *real*
  `AgentJobService::recordLog()` (the same write path a live agent
  report takes) with an embedded fake secret, then asserts the rendered
  page never contains it.
- **Permission checks unchanged.** `DeploymentController::show()` and
  `DeploymentTaskController::show()` still call the exact same
  `Gate::authorize('deployments.view')` +
  `Gate::authorize('view', $project)` pair from Phase 4-F/4-G. No new
  route was added anywhere in this phase — every new UI element lives on
  an already-authorized page or an already-validated existing form
  (`projects.deploy` gained two optional fields, nothing else).
- **Direct URL access re-verified** —
  `test_non_member_cannot_view_a_deployment_by_direct_url` confirms a
  Developer with no `ProjectMember` row still gets 403 on
  `deployments.show`, exactly as Phase 4-G established.
- **New read-only queries stay within the same authorization boundary**
  — `$currentLive`/`$currentTargetCommit` (used for the rollback
  from/to display) are both scoped to the *same* project/target the
  caller was already authorized to view (`$project->deployments()`,
  `$target->deploymentTasks()`), so neither can leak another project's
  data.
- **`release_notes` is free-text, user-supplied, and rendered via
  Blade's `{{ }}`** — auto-escaped, no new XSS surface.

## 11. Files Changed

**New:**
- `database/migrations/2026_07_31_090000_add_release_fields_to_deployments_table.php`
- `app/Support/Deployment/PipelineStepPresenter.php`
- `resources/fonts/vazirmatn/{Vazirmatn-Regular,Medium,SemiBold,Bold}.woff2`, `OFL.txt`
- `public/fonts/vazirmatn/{Vazirmatn-Regular,Medium,SemiBold,Bold}.woff2`, `OFL.txt`
- `tests/Feature/Dashboard/DeploymentExperienceTest.php`

**Modified:**
- `app/Models/Deployment.php` (`release_label`/`release_notes` fillable, `displayLabel()`, `durationSeconds()`)
- `app/Services/Deployment/DeploymentDispatcher.php` (`createManual()` optional `$release` param)
- `app/Services/Dashboard/DashboardService.php` (`recentReleases()`)
- `app/Http/Controllers/Dashboard/DeploymentController.php` (steps/currentStep/currentLive view data)
- `app/Http/Controllers/Dashboard/DeploymentTaskController.php` (steps/currentStep/currentTargetCommit view data)
- `app/Http/Controllers/Dashboard/ProjectController.php` (`deployManual()` optional release fields)
- `app/Http/Controllers/Dashboard/DashboardController.php` (`recentReleases` view data)
- `resources/views/layouts/app.blade.php` (font, badge/step/timeline/responsive CSS)
- `resources/views/components/confirm-dialog.blade.php` (optional slot content)
- `resources/views/deployments/show.blade.php`, `resources/views/deployment-tasks/show.blade.php` (redesign)
- `resources/views/projects/show.blade.php` (history table columns, release fields on manual-deploy form)
- `resources/views/dashboard/index.blade.php` (Recent Releases widget)
- `lang/en/ui.php`, `lang/fa/ui.php`, `lang/en/status.php`, `lang/fa/status.php` (new keys)

## 12. Tests Result

- `php artisan test`: **234/234 passing** (222 pre-existing, unmodified,
  + 12 new in `DeploymentExperienceTest`).
- New tests cover: overview field rendering, pipeline step status
  derivation (succeeded/running/pending), release-label display and
  its commit-hash fallback, manual deploy with and without release
  fields (the latter being the explicit compatibility case), rollback
  dialog from/to labels, the Recent Releases widget's succeeded-only
  filter, redaction re-verification through the real write path, font
  asset presence, and both a direct-URL-blocked and a
  direct-URL-allowed authorization case (including the new-domain
  `deployment-tasks.show` page).

## 13. Remaining Work

- Live status is refresh-based (matches this app's existing zero-JS,
  server-rendered pattern everywhere else) — no auto-polling/WebSocket
  push for a currently-running deployment's step progress.
- Release labels are only collected on the manual-deploy form; a
  webhook-triggered or rollback-triggered deployment never gets one
  (by design — there's no natural point to ask for one on those paths)
  and always displays its commit hash instead.
- `Deployment::durationSeconds()` formats via `gmdate('H:i:s', ...)`,
  which would visually wrap past 24 hours — a non-issue for how long a
  deployment actually runs today, but worth a proper duration formatter
  if that ever changes.
- No dedicated Release *list* page exists yet (only the label surfaced
  inline on History/Dashboard/Detail) — a future phase could add one if
  the "Release Management" concept grows further.

## 14. Next Phase Recommendation

If Release Management continues to grow, the natural next step is a
first-class `Release` concept with its own list/detail page (once
there's a real need for something beyond a label + notes on a
Deployment — e.g., grouping several targets' deployments under one
named release, or release-level rollback across an entire environment
at once), plus the live-status auto-refresh noted above once/if a
non-zero-JS interaction pattern is introduced elsewhere in the app.
