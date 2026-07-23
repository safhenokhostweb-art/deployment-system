# Phase 4-B — Persian Localization + RTL Foundation Implementation Report

## 1. Executive Summary

Phase 4-B implements the Persian/RTL dashboard designed (but not built) in Phase 4-A. Every visible string in the dashboard — navigation, status labels, form labels, empty states, confirm dialogs, flash messages, and the specific error-message example the brief called out — now renders in Persian inside an RTL layout with a sidebar and system-status header.

The change surface is exactly what the brief required: **zero** Model, Controller, Service, Migration, or Route files were touched. `git status` shows the full diff is confined to `bootstrap/app.php` (middleware/exception wiring only), 15 Blade views, and new files under `lang/`, `resources/views/components/`, `resources/views/errors/`, plus two small presentation-layer glue classes explained in §3. All 117 pre-existing tests still pass unmodified, and 7 new tests were added — 124/124 passing.

Two constraints turned out to be in tension and required a documented judgment call, laid out in §7: the brief's example error message and the 16 flash-success messages are hardcoded English strings inside Controllers/Services that cannot be edited this phase. Translating them without touching those files required two small new presentation-layer files (a middleware, a static translator) rather than zero new files. This is flagged explicitly for the user to challenge if the intent was "literally zero new PHP files."

## 2. Pre-Implementation Audit

Findings, gathered before writing any code:

- `lang/` did not exist — this is a fresh Laravel 13 app; Laravel no longer scaffolds it by default.
- `config('app.locale')` and `config('app.fallback_locale')` both default to `'en'` (unset in `.env`). This is the fact that makes the whole approach safe: nothing about the global locale needed to change.
- 15 Blade views existed, all hand-written HTML/CSS in a single `layouts/app.blade.php` (no Vite/Tailwind build step) — English only, LTR only, no components, no `__()` calls anywhere.
- Enumerated every `abort()`/`abort_unless()`/`abort_if()` call with a literal message across `app/Services` and `app/Http/Controllers`: 15 are dashboard-facing (409/422), 2 are agent-API-facing only (`AgentJobService`/`AgentTaskService`'s "Invalid status transition from agent.", both 422, both reached only through `/api/agent/*` routes that already force JSON).
- Enumerated every `->with('status', '...')` flash call: 16, all in Dashboard Controllers, all literal English strings.
- Found one more literal string outside both categories: `AuthController::login()` calls `back()->withErrors(['email' => 'Invalid credentials.'])` directly (not via `$request->validate()`), so it needed the same manual-lookup treatment.
- Grepped `tests/` for `assertSee`/`assertSeeText`: only 2 matches, both in `SecretHandlingTest` asserting on a webhook-secret *value*, not on any English UI copy. Confirmed no existing test would break from re-translating page text.
- Grepped for `assertStatus(409)`/`assertStatus(422)` against dashboard routes: found in `AgentApprovalTest` and `DeploymentApprovalTest`. This ruled out "redirect instead of showing the guarded page" as a translation strategy for `abort()` messages — the status code has to stay exactly 409/422, only the body text can change (see §7).

## 3. Localization Architecture

**Locale strategy.** `config('app.locale')`/`fallback_locale` remain `'en'`, completely untouched. Instead, a new middleware, `App\Http\Middleware\SetDashboardLocale`, is appended only to the `'web'` middleware group in `bootstrap/app.php`:

```php
$middleware->web(append: [SetDashboardLocale::class]);
```

It calls `app()->setLocale('fa')` and `Carbon::setLocale('fa')` per-request. The `'api'` group (agent/webhook endpoints) never runs this middleware, so `config('app.locale')` is what those requests still see — API JSON responses are byte-for-byte unaffected. This is what Task 9's new API test (`test_api_agent_error_responses_stay_english_json_unaffected_by_dashboard_locale`) locks in.

**Why two small new PHP files were unavoidable, not optional.** The brief's own Task 7 example — `"Project 'X' has an Environment configured but no active deployment targets."` — is a literal string inside `DeploymentDispatcher.php`, a Service file that's out of scope to edit. The only way to translate it without touching that file is to intercept it after it's thrown. Laravel gives exactly one presentation-layer seam for that: `bootstrap/app.php`'s `withExceptions()` closure (already used once in Phase 1.5). The same is true for the 16 flash-success strings and the one `withErrors()` call — they're set in Controllers that are out of scope, so the seam is the `'web'` middleware's response phase, after the Controller has already run.

Both files are pure translation lookups with no business logic:
- `app/Support/DashboardMessageTranslator.php` — regex-matches a literal English string against a fixed, enumerable table (from §2's audit) and returns the looked-up `lang/fa/messages.php` string, extracting the 1-3 dynamic values (project name, health-check type, TTL minutes) via substring matching. Unmatched input passes through unchanged — nothing this table doesn't recognize silently disappears.
- `App\Http\Middleware\SetDashboardLocale` — besides setting the locale, its response phase runs *only when the response is a `RedirectResponse`* (i.e., only right after a Controller flashed something), translates `session('status')` and any `session('errors')` MessageBag entries via the translator above, and writes the translated value back with `session()->put()` (not `->flash()` again, to avoid re-registering the same key for another round of flash-aging).

The `bootstrap/app.php` exception hook:

```php
$exceptions->render(function (HttpExceptionInterface $e, Request $request) {
    if ($request->is('api/*')) return null;
    $message = $e->getMessage();
    if ($message === '' || !in_array($e->getStatusCode(), [409, 422], true)) return null;
    return response()->view('errors.dashboard', [
        'statusCode' => $e->getStatusCode(),
        'message' => DashboardMessageTranslator::translateError($message),
    ], $e->getStatusCode());
});
```

Scoped tightly: only non-API requests, only when the exception carries a message, only status 409/422 (the exact codes every enumerated `abort()` call uses). Anything else — 404s, 403s, 500s, message-less aborts — falls through to Laravel's default handling, completely unaffected. The status code is preserved exactly, so `assertStatus(409)`/`assertStatus(422)` in the existing test suite pass unchanged; only the response body's text is now Persian.

**Validation messages** ($request->validate() failures) needed no manual lookup at all — once the web-group locale is `'fa'`, Laravel automatically resolves messages from the new `lang/fa/validation.php`, exactly the framework's own built-in mechanism. Nothing about how any Controller calls `->validate()` changed.

## 4. Translation Dictionary

`lang/en/` and `lang/fa/` (mirrored, so English is the fallback with identical structure):

| File | Contents |
|---|---|
| `nav.php` | Brand, sidebar/header labels, "N/M servers online" |
| `status.php` | Every `Deployment`/`Agent`/`DeploymentTask`/`AgentJob` status constant, plus online/offline/active/never/yes/no |
| `ui.php` | ~180 keys, nested per page (`common`, `auth`, `dashboard`, `servers`, `projects`, `agents`, `deployments`, `deployment_tasks`) — every label, button, placeholder, help paragraph, and confirm-dialog body from the 15 views |
| `messages.php` | `errors.*` (15 abort-guard messages) and `flash.*` (16 status-flash messages + `invalid_credentials`) — the lookup tables `DashboardMessageTranslator` resolves into |
| `validation.php` (fa only) | Laravel's own validation-rule keys (`required`, `regex`, `max`, `size`, `exists`, …) plus `attributes` (field → Persian label) and `custom` overrides for the three regex-validated fields (project key, repository, commit hash) |

Example, exactly as specified in the brief:

```php
// status.php
'succeeded' => 'موفق', 'pending_approval' => 'در انتظار تایید', 'partial_success' => 'موفقیت جزئی',
```

## 5. RTL Implementation

`layouts/app.blade.php`: `<html lang="fa" dir="rtl">`. All directional CSS properties were switched to logical equivalents (`text-align: start`, `padding-inline-start`, `margin-inline-end`) so the browser mirrors correctly rather than needing hand-flipped `left`/`right` rules. Flexbox row layouts (sidebar + main content) mirror automatically under `dir="rtl"` with no manual reordering.

Codes, hashes, commit SHAs, URLs, and IP addresses are wrapped in the new `<x-ltr>` component (`dir="ltr"`, `unicode-bidi: isolate`) so they don't visually scramble inside RTL text — this was the specific rule from the Phase 4-A design. Pipeline JSON textareas and log `<pre>` blocks are also forced `dir="ltr"; text-align:left` since their content (JSON, shell output) is inherently LTR regardless of surrounding language.

Responsive layout is preserved: below 820px the sidebar collapses from a vertical column into a wrapped horizontal strip via one `@media` block; no page-specific responsive rules were removed.

## 6. Shared UI Components

Six anonymous Blade components under `resources/views/components/` (no PHP class, no ServiceProvider registration — Laravel auto-discovers them):

- `<x-badge status="...">` — renders `badge-{status}` CSS class + the matching `status.php` label. Used for all Deployment/Agent/DeploymentTask/AgentJob statuses and the online/offline pseudo-status.
- `<x-card :title="...">` — the existing `.card` box, now reusable with an optional heading.
- `<x-ltr>` — the RTL-safe inline wrapper described in §5.
- `<x-empty-state>` — the "no X yet" muted placeholder text.
- `<x-alert type="success|error">` — replaces the layout's inline flash/error markup, reused by the new `errors/dashboard.blade.php` view too.
- `<x-confirm-dialog>` — native `<dialog>` element (no JS framework), replacing every existing `onsubmit="return confirm(...)"` call (8 destructive/irreversible actions: rotate server credentials, rotate webhook secret, reject/discard pending agent, reject/disable an approved agent, rollback a deployment, rollback a target). The trigger button calls `showModal()`; the dialog's confirm button submits the real form programmatically. Confirm-dialog usage is 1:1 with wherever the *original* code already had a JS `confirm()` — no new confirmations were invented.

## 7. Error Message Localization

The brief's exact example works end-to-end:

- Input (unchanged, still thrown by `DeploymentDispatcher.php` exactly as before): `"Project 'X' has an Environment configured but no active deployment targets."`
- Output on the dashboard: `"برای پروژه X محیطی تنظیم شده اما هیچ مقصد فعال برای استقرار وجود ندارد."`
- The internal exception class, its status code (422), and its English message text in the PHP source are all **unchanged** — translation happens strictly at the render boundary, per the brief's explicit rule.

**Judgment call, flagged for review:** achieving this required accepting that "no Controller/Service change" doesn't quite cover "translate a string a Controller/Service hardcodes" for free — some interception point has to exist. §3 explains why the two new files (`SetDashboardLocale`, `DashboardMessageTranslator`) are the smallest possible seam. If the intended scope was that these hardcoded strings should stay English until a future phase can touch the Services properly, that's a one-line rollback (drop the `render()` callback and the flash-translation block) — the rest of Phase 4-B does not depend on it.

## 8. Security UX

No changes to how secrets are generated, hashed, masked, or rotated — `SecretHandlingTest` (from Phase 3-D) passes unmodified. What changed is presentation only: labels are Persian, "shown only once" cards use `<x-card>` with a Persian title, and the raw secret/token/hash values themselves are wrapped in `<x-ltr>` so they don't reverse visually inside RTL text — but the values, masking logic, and rotation flow are byte-for-byte what Phase 1.5/3-B/3-D built.

## 9. Files Changed

**Modified (15 views + 1 bootstrap file):** `bootstrap/app.php`; `layouts/app.blade.php`; `auth/login.blade.php`; `dashboard/index.blade.php`; `servers/{index,create,show}.blade.php`; `projects/{index,create,edit,show}.blade.php`; `agents/{index,pending,show}.blade.php`; `deployments/show.blade.php`; `deployment-tasks/show.blade.php`.

**New:** `lang/en/{nav,status,ui,messages}.php`, `lang/fa/{nav,status,ui,messages,validation}.php`; `resources/views/components/{badge,card,ltr,empty-state,alert,confirm-dialog}.blade.php`; `resources/views/errors/dashboard.blade.php`; `app/Http/Middleware/SetDashboardLocale.php`; `app/Support/DashboardMessageTranslator.php`; `tests/Feature/Dashboard/PersianLocalizationTest.php`.

**Untouched:** every file under `app/Models/`, `app/Http/Controllers/` (business logic — only their *rendered output* changed, not their code), `app/Services/`, `database/migrations/`, `routes/`.

## 10. Test Results

```
Full suite: 124/124 passed, 318 assertions (117 pre-existing + 7 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/PersianLocalizationTest.php`):

1. Dashboard renders `dir="rtl"` and Persian sidebar labels.
2. Login page renders `dir="rtl"` and Persian.
3. A deployment status badge renders `موفق`, not `succeeded`.
4. A flash success message (`Deployment approved and queued.`) renders as its Persian translation, and the English original is absent.
5. The guard-exception scenario (already covered by an existing test asserting `assertStatus(409)`) additionally renders the Persian translation, **while the 409 status code is unchanged** — proving the existing behavioral contract survived.
6. A validation failure (`name` required) produces the Persian message `فیلد نام الزامی است` via `lang/fa/validation.php`.
7. An API (agent-facing) error response is confirmed to contain **no Persian characters** at all, regardless of which guard rejects it — proving the `'api'` group is unaffected by the dashboard's locale.

## 11. Remaining Work

- **Reports / Settings sidebar items** (Task 5 asked for them explicitly) have no backing routes — creating them was out of scope ("no Route" rule). They render as disabled "به‌زودی" (coming soon) placeholders.
- **"استقرارها" (Deployments) sidebar item** — there is no `/deployments` index route in this app (only per-project history and per-deployment `show` pages). Rather than a route-less placeholder, it links to the Dashboard's "Recent deployments" card (`#recent-deployments` anchor), which is real, already-rendered data — this is a judgment call, flagged here in case a real `deployments.index` route is preferred in a future phase.
- **Carbon's Persian locale** (`Carbon::setLocale('fa')`) affects `diffForHumans()` output (e.g. "۲ ساعت پیش") — this is bundled with `nesbot/carbon` already, no new dependency, but wasn't explicitly asked for; flagging in case the user wants English-numeral timestamps kept for now.
- No Persian web font was added (no CDN, no new dependency) — the layout uses the system font stack (`Tahoma, "Segoe UI", "Vazirmatn", Arial`), which renders correctly but isn't as polished as a dedicated Persian typeface would be.
- The two "glue" files from §3/§7 are the one deviation from a strict reading of "zero new files" — flagged there for explicit sign-off.

## 12. Phase 4-C Recommendation

The two Gaps Phase 4-A named as needing real backend work — GitHub OAuth/App integration and Environment/DeploymentTarget CRUD UI — are still gaps; nothing this phase touched changes that assessment. A natural Phase 4-C would tackle the Environment/DeploymentTarget CRUD UI specifically, since the domain models and service layer for it already exist (Phase 3-C/3-C.2) and only the Controller+View layer is missing — unlike GitHub OAuth, which needs new external integration work first.
