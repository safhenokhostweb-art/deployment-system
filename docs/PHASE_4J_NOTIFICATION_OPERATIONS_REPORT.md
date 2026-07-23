# Phase 4-J — Notification & Operations Center: Completion & Hardening Report

> This document replaces an earlier draft of the same file that described the original Notification Center increment (the one this phase found already built, uncommitted, in the working tree — 234 pre-existing + 17 new tests at that time). Its still-relevant content (architecture decision, full schema) is folded into §2 below rather than discarded. This version reflects the state after this phase's hardening work, with 265/265 tests passing.

## 1. Executive Summary

Phase 4-J's Notification Center backend (model, migration, service, event subscriber, controller, routes, i18n, CSS foundation, factory) already existed in the working tree, uncommitted, from a prior increment — but this report had never been finalized to reflect it, the UI was partially wired, and several real gaps remained: two event-integration blind spots, a notification-display authorization gap, no header bell dropdown, no sidebar link, and incomplete test coverage. This phase closes all of those gaps without rewriting or removing anything that already worked, and without touching the Deployment Engine, Agent Architecture, GitHub Integration, Permission System, Resource Authorization, AuditLog Architecture, Localization/RTL, or Vazirmatn font setup. No migration was needed. The full regression suite (265 tests, 708 assertions) passes.

## 2. Existing Implementation Audit

Verified directly against the source before making any change.

**Already correct and unchanged by this phase:** `Notification` model + migration, `notification_preferences` column + migration, `NotificationService` (`notify`/`notifyAdmins`, opt-out preference check), `NotificationEventSubscriber` (11 events wired via `Event::subscribe()` in `AppServiceProvider`), `NotificationController` (index/markRead/markAllRead/preferences/updatePreferences — the preferences endpoints were already fully implemented), `RelatedResourceResolver`, all five `notifications.*` routes, i18n strings (fa+en), CSS foundation, the `NotificationFactory`, and the Blade views `notifications/index.blade.php` and `notifications/preferences.blade.php` (both already existed and rendered correctly).

**Architecture decision (from the original increment, still valid):** a custom `Notification` model + `NotificationService` was chosen over Laravel's built-in notification system, consistent with this codebase's existing pattern of small explicit classes over opinionated subsystems (`AuditLogEntry::record()` instead of a logging package, `PermissionMatrix` instead of a permissions package). `User` keeps the `Notifiable` trait attached but its own `notifications(): HasMany` method cleanly overrides the trait's `MorphMany` — nothing in the codebase ever called the trait's version, so this is not a conflict.

**Schema** (`notifications` table, mirrors `audit_log_entries`'s actor/subject shape deliberately):

| column | purpose |
|---|---|
| `user_id` | recipient (FK, cascade delete) |
| `type` | dot-notation, e.g. `deployment.failed` (`Notification::TYPE_*`) |
| `title` / `message` | pre-rendered display text (always generated with explicit `'fa'` locale — see §4) |
| `severity` | `success`\|`info`\|`warning`\|`critical` |
| `related_type` / `related_id` | FQCN + id of the resource this notification is about (mirrors `audit_log_entries.subject_type`/`subject_id`) |
| `actor_type` / `actor_identifier` | who/what caused it (mirrors `audit_log_entries.actor_type`/`actor_identifier`) |
| `metadata` | nullable JSON, reserved for future channel-specific payloads |
| `read_at` | nullable timestamp |

Indexed on `(user_id, read_at)`, `(user_id, created_at)`, `(related_type, related_id)`.

**Confirmed real gaps, addressed by this phase:**
- `RollbackService` dispatched no event at all — a rollback being queued was invisible to the Notification Center until it later succeeded or failed.
- `DetectStalledDeployments::failStalledLegacyDeployments()` updated a deployment's status directly with no event — a legacy deployment whose agent crashed mid-run produced no notification, unlike an agent-reported failure.
- `NotificationController` scoped visibility by `user_id` only; a notification's title/message text about a Deployment/Project remained visible even after the viewer's `ProjectMember` row was removed.
- No severity filter (only type + unread).
- The header bell was a plain link with an unread badge, no dropdown preview.
- No sidebar navigation entry for `/notifications` (the `nav.notifications` translation key existed but nothing referenced it).
- No dedicated authorization or broad security-sweep tests; no test for `DeploymentStarted`, `AgentOffline`, or the not-yet-existing rollback-start event.

**Authorization model reused as-is (no new Policy needed):** `PermissionMatrix` (role → dot-notation ability) plus `ProjectPolicy` (`before()` bypasses membership entirely for Admins; otherwise `hasPermission() && $project->isMember($user)`). Deployment access is always the existing composed check `Gate::authorize('deployments.view')` + `Gate::authorize('view', $deployment->project)`. Server/Agent/GitHubAccount/User have no per-resource policy anywhere in the app (a deliberate Phase 4-G decision) — this phase's authorization hardening matches that convention rather than inventing new per-resource rules for those types.

## 3. Completed Hardening — Summary

| Area | Before | After |
|---|---|---|
| Rollback start | No event | `RollbackStarted` event, dispatched in both `RollbackService::rollbackTo()` and `rollbackTargetTo()`, produces a `deployment.rollback_started` notification |
| Legacy stalled deployment | Status updated, no event | `DeploymentFailed` now dispatched at the same point, alongside the pre-existing audit log write |
| Failed rollback wording | Reused generic "deployment failed" text | Same event/type, but `NotificationEventSubscriber::onDeploymentFailed()` now branches on `$deployment->action` to use rollback-specific title/message copy |
| Notification display authorization | `user_id` only | New `NotificationVisibilityFilter` re-checks the *current* Gate/Policy state of a notification's related resource before display |
| Filters | Type, unread only | Added severity filter |
| Header bell | Static link + badge | `<details>`/`<summary>` dropdown showing the 5 most recent (authorization-filtered) notifications, zero JavaScript |
| Sidebar | No entry | Added, using the existing `nav.notifications` translation key |
| Tests | Event-dispatch + basic CRUD/preferences coverage | Added authorization-hardening, new-event, filter, and security-sweep tests |

## 4. Event Integration

Two real gaps closed, both purely additive — no business logic in `DetectStalledDeployments` or `RollbackService` changed, only an `event(...)` call added after logic that was already executing:

- **`app/Console/Commands/DetectStalledDeployments.php`** — `failStalledLegacyDeployments()` now dispatches `DeploymentFailed($deployment, $deployment->failure_message)` immediately after the existing status update and `AuditLogEntry::record()` call.
- **`app/Services/Deployment/RollbackService.php`** — a new `App\Events\RollbackStarted` (constructor: `Deployment $deployment`, mirrors `DeploymentFailed`'s shape) is dispatched at the end of both `rollbackTo()` and `rollbackTargetTo()`'s transactions, in the same "audit log then event" ordering `DeploymentDispatcher::approve()` already uses for a normal deploy.
- **`app/Listeners/NotificationEventSubscriber.php`** — registered a new `onRollbackStarted()` handler (uses the existing `notifyDeploymentAudience()` helper, `Notification::TYPE_DEPLOYMENT_ROLLBACK_STARTED`, `SEVERITY_INFO`) and modified `onDeploymentFailed()` to select rollback-specific copy when `$deployment->action === Deployment::ACTION_ROLLBACK`, so a failed rollback reads as "بازگشت ناموفق" (rollback failed) rather than the generic "استقرار ناموفق" (deployment failed) — same event, same notification type (kept in the same "deployment" preference group), different text only.
- **`app/Models/Notification.php`** — added `TYPE_DEPLOYMENT_ROLLBACK_STARTED = 'deployment.rollback_started'`.
- New lang keys added to both `lang/fa/notifications.php`/`lang/en/notifications.php` (`rollback_started_*`, `rollback_failed_*`) and `lang/fa/ui.php`/`lang/en/ui.php` (`type_deployment.rollback_started`, plus the new filter/bell strings in §6-7).
- `NotificationController::notificationTypeGroups()` gained the new type under the existing `deployment` group, so it's covered by the preferences page automatically.

No other event gap was introduced or left unaddressed: `DeploymentStarted`/`DeploymentSucceeded`/`RollbackCompleted` were already dispatched correctly; cancel and agent reject/disable/re-enable remain audit-logged-only by original design (out of this phase's scope, unchanged).

## 5. Authorization Model

New class: **`app/Support/Notification/NotificationVisibilityFilter.php`**. Given a `User` and a collection of their own `Notification` rows, it:

1. Batch-loads (one query, not per-row) every referenced `Deployment` (with its `project`) for the whole list.
2. Returns `true` immediately for any notification with `related_type === null` (a purely personal notification has nothing to authorize against).
3. For a `Deployment`-related notification: `Gate::forUser($user)->allows('deployments.view') && Gate::forUser($user)->allows('view', $deployment->project)` — the exact same composed check used everywhere else in the app, so Admin's `ProjectPolicy::before()` bypass is preserved automatically.
4. For `Server`/`Agent`/`GitHubAccount`/`User`-related notifications: a single role-only Gate check (`servers.view`/`agents.view`/`github.view`/`users.manage`), matching the existing convention that these types have no per-resource Policy anywhere in the app.
5. If the related resource itself was deleted since the notification was created, it stays visible (matches `RelatedResourceResolver`'s existing "no link, but still show the text" behavior for a deleted resource).

**Wired into:**
- `NotificationController::index()` — the query result is passed through `filterVisible()` before being rendered, so an unauthorized notification's title/message never reaches the response body, not just its link.
- The header bell dropdown in `layouts/app.blade.php` — the same filter is applied to its (separately capped) recent-notifications fetch.

**Deliberately not applied to:** the header bell's unread *count* badge, and `markRead`/`markAllRead` (both remain pure "is this row mine" state changes). See §12 for why.

## 6. Notification UI

`resources/views/notifications/index.blade.php`: added a severity `<select>` filter (options built from the `Notification::SEVERITY_*` constants, labelled via existing `ui.notifications.severity_*` keys) alongside the pre-existing type and unread-status filters. List rendering, mark-read/mark-all-read actions, and the empty state were already correct and are unchanged. `NotificationController::index()` now also accepts `?severity=` and applies `NotificationVisibilityFilter` before rendering.

## 7. Bell Component

`resources/views/layouts/app.blade.php`: the bell changed from a plain `<a>` link to a `<details class="notification-bell-wrap">` / `<summary>` pair — a click-to-toggle dropdown with **zero JavaScript**, using the same pattern this codebase already uses for `details.inline-form`. The dropdown shows the 5 most recent, authorization-filtered notifications (severity dot, title, relative time) and a "مشاهده همه اعلان‌ها" link to `/notifications`. The unread-count badge computation (a single indexed `COUNT` query, unchanged) is untouched.

A real regression surfaced and was fixed during testing: the dropdown initially rendered on every page including `/notifications` itself, so a notification the page's own filter had just excluded still leaked into the response body via the dropdown, breaking `assertDontSee`-based filter tests. Fixed by skipping the dropdown's item list (showing a one-line "شما در حال مشاهده فهرست کامل اعلان‌ها هستید" note instead) whenever `request()->routeIs('notifications.*')` — also better UX, since duplicating the same list twice on its own page added nothing.

## 8. Preferences

`resources/views/notifications/preferences.blade.php` and `NotificationController::preferences()`/`updatePreferences()`/`notificationTypeGroups()` already existed, fully working, and needed no changes. Confirmed it already satisfies the brief's four example toggles (Deployment Success, Deployment Failed, Agent Offline, Security) — each individual `Notification::TYPE_*` constant gets its own checkbox, grouped under a category heading (`deployment`/`agent`/`github`/`security`), so "Deployment Success" and "Deployment Failed" are independently toggleable, not bundled. `User::wantsNotification()` (opt-out, defaults to `true`) and `NotificationService::notify()`'s early-return on a disabled preference were already correct and untouched.

## 9. Security Review

Reviewed every notification-producing code path (`NotificationEventSubscriber`, all `lang/*/notifications.php` templates): interpolated values are always a name/label/date/email/status string, never a credential. `CredentialRotated`'s event payload itself (`Server $server, string $rotatedBy`) never carries a secret value — there is nothing to leak by construction. Secrets in this app (`signing_secret`, `agent_token_hash`, `github_webhook_secret`, `registration_token`, and log `command`/`output`, already redacted at write time by the existing `SecretRedactor`) are never read by any Listener or `NotificationService` call site.

Added `tests/Feature/Dashboard/NotificationSecurityTest.php`:
- `test_no_notification_produced_by_any_event_ever_contains_a_forbidden_secret_pattern` — fires all ten notification-producing events in one pass and asserts every resulting `Notification` row's title *and* message fails to match `/password|secret|ssh[-_ ]?key|token|env(?:ironment)?[-_ ]?variable/i`.
- `test_credential_rotated_event_payload_itself_carries_no_secret_value` — asserts a real (non-empty) `signing_secret` value never appears in the resulting notification.

Both pass. The pre-existing single-type test in `NotificationCenterTest` (`test_credential_rotated_notification_never_contains_a_secret_value`) is retained unchanged.

## 10. Files Changed

Scoped strictly to this phase's work. **Note:** `git status` on this repository currently shows extensive additional modified/untracked files across `agent/`, several Controllers/Models, and other views — that is unrelated, concurrent work in progress elsewhere in this same repository (evidenced by mid-edit `OperationJob`/`Operation*` references that appeared in shared files like `NotificationEventSubscriber.php` and `NotificationVisibilityFilter.php` while this phase was in progress). Nothing below was authored by that other work, and this phase did not modify anything belonging to it.

**Created:**
- `app/Events/RollbackStarted.php`
- `app/Support/Notification/NotificationVisibilityFilter.php`
- `tests/Feature/Dashboard/NotificationAuthorizationTest.php`
- `tests/Feature/Dashboard/NotificationSecurityTest.php`
- `docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md` (this file, replacing the earlier draft)

**Modified:**
- `app/Services/Deployment/RollbackService.php` — `RollbackStarted` dispatch in `rollbackTo()`/`rollbackTargetTo()`
- `app/Console/Commands/DetectStalledDeployments.php` — `DeploymentFailed` dispatch for the legacy stall path
- `app/Models/Notification.php` — added `TYPE_DEPLOYMENT_ROLLBACK_STARTED`
- `app/Listeners/NotificationEventSubscriber.php` — `onRollbackStarted()`, rollback-aware `onDeploymentFailed()`
- `app/Http/Controllers/Dashboard/NotificationController.php` — severity filter, `NotificationVisibilityFilter` wiring, new type in `notificationTypeGroups()`
- `resources/views/notifications/index.blade.php` — severity filter control
- `resources/views/layouts/app.blade.php` — bell dropdown, sidebar nav link, associated CSS
- `lang/fa/notifications.php`, `lang/en/notifications.php` — rollback-started/rollback-failed copy
- `lang/fa/ui.php`, `lang/en/ui.php` — new type label, severity filter labels, bell/view-all strings
- `tests/Feature/Deployment/NotificationFoundationTest.php` — new event-dispatch tests
- `tests/Feature/Dashboard/NotificationCenterTest.php` — new event/filter tests

No migration was added or changed — the schema already covered everything this phase needed.

## 11. Tests Result

Full suite: **265 tests, 708 assertions, 0 failures** (`php artisan test`). New/updated: `NotificationFoundationTest.php` (+2: legacy-stall-fires-DeploymentFailed, rollback-queued-fires-RollbackStarted), `NotificationCenterTest.php` (+6: DeploymentStarted notifies, RollbackStarted notifies, failed-rollback wording differs from failed-deploy wording, AgentOffline notifies, severity filter, unread-status filter), `NotificationAuthorizationTest.php` (new, 4 tests: membership-revoked hides notification, Admin always sees it, membership-present still shows it, resource-less personal notification always shows), `NotificationSecurityTest.php` (new, 2 tests: broad multi-event secret sweep, CredentialRotated payload check). All pre-existing tests, including the full Deployment/Agent/GitHub/Permission regression suite, pass unmodified.

One real bug was found and fixed mid-implementation: a test that resolved `DetectStalledDeployments` from the container and called `->handle()` directly failed (`Call to a member function writeln() on null`) because bypassing Artisan strips the command's I/O setup that `$this->info(...)` needs; fixed by using `$this->artisan('deployments:detect-stalled')` instead, which is also the correct/idiomatic way to test a console command in this codebase.

## 12. Remaining Limitations

- The header bell's unread-count badge is **not** run through `NotificationVisibilityFilter` — it stays a single raw indexed `COUNT` query, matching its pre-existing implementation and test (`test_header_bell_shows_unread_count_for_the_current_user_only`). This means the badge number can, in principle, include a notification whose resource the user has since lost access to (no content is exposed by a bare number; only the `/notifications` list and the bell dropdown enforce the content-level check). Filtering the count too would require the same batched authorization query on every single page load app-wide; given the app's small scale this would likely be fine, but it's a deliberate scope boundary, not an oversight — flagging it for a future phase to decide.
- `markRead`/`markAllRead` do not re-check resource authorization — they only verify the row belongs to the current user (unchanged, pre-existing behavior). This matches the brief's own framing ("قبل از نمایش" — before *display*), not a write-path concern.
- Per the brief's explicit exclusions, none of Email/SMS/Webhook delivery, a queued listener, mobile push, or a notification template engine were implemented — the subscriber remains fully synchronous, exactly as before this phase.
- A separate, apparently large, concurrent engagement (referencing "Operation"/"OperationJob"/an Operations Command Center) is actively modifying many files in this same repository, including two files this phase also touched (`NotificationEventSubscriber.php`, `NotificationVisibilityFilter.php`, `Notification.php`, both `notifications.php` lang files). All of that other work's edits were left exactly as found — nothing from it was reverted, completed, or judged as part of this report — but it means the working tree currently mixes two independent, uncommitted efforts. Worth confirming with the user before any commit, so the two aren't bundled together unintentionally.

## 13. Next Phase Recommendation

1. Decide whether to also authorization-filter the bell's unread count (§12) — likely low-risk given this app's scale, but a deliberate choice rather than default behavior.
2. Once the concurrent Operations Command Center work settles, re-run this same regression suite to confirm nothing between the two efforts collides (both reference `Notification` and its event subscriber directly).
3. Consider whether `RollbackStarted`'s notification should also reach some audience beyond Admins + the triggering user — currently identical to every other deployment-lifecycle notification via the shared `notifyDeploymentAudience()` helper, not a gap specific to rollback.
4. When a real outbound channel (email is the natural first one, given `User` already has an `email` column) is needed, build it as a second consumer of `NotificationService::notify()` rather than a parallel path.
