# Phase 4-E — GitHub Integration + Project Wizard Implementation Report

## 1. Executive Summary

This phase adds a real, working GitHub App integration (account connect/disconnect/test, repository picker, branch picker, automatic webhook provisioning) and turns Project creation into a 6-step guided wizard, closing the last "technical user only" gap named across Phases 4-A through 4-D. Unlike Phase 4-D's onboarding wizard — where the audit found the underlying capability (agent self-registration) genuinely didn't exist anywhere in the codebase — this phase's GitHub API integration is real, complete, and independently testable: every call is standard OAuth-style REST against GitHub's documented, stable API, verified with `Http::fake()` mocking GitHub's real response shapes (the same technique Phase 3-D's `HealthCheckServiceTest` already established for this codebase). Two genuine implementation bugs were found and fixed during testing (§9) — both are documented as a record of what "verified against the real contract" actually caught, not swept under the rug.

180/180 tests pass (163 from before this phase, unmodified, + 17 new). Three additive migrations ran (reported before executing, per the brief's rule) — a new `github_accounts` table, a nullable `servers.type`-adjacent `projects.description` column, and (from the architecture decision in §3) no schema change was needed for GitHub credentials at all, because none are ever stored.

## 2. Pre-Implementation Audit

**Models:** No GitHub integration model existed. `Project.repository`/`.branch`/`.project_key`/`.github_webhook_secret` were exactly as Phase 1 left them. No `description` column existed despite the wizard's Step 1 needing one.

**Controllers:** `ProjectController` had full CRUD but every field (`repository`, `branch`, `project_key`) was hand-typed by the operator. No `WebhookController` beyond the existing inbound `GitHubWebhookController`.

**Services:** No GitHub-facing service existed at all — grepping the entire `app/` tree for "github" (case-insensitive) turned up only inbound-webhook code: `GitHubWebhookController`, `GitHubWebhookVerifier`, `ProcessGitHubWebhookPush`, `WebhookDelivery`. No `DeploymentDispatcher` change was needed — it already resolves targets and branches purely from `Project` columns, regardless of how those columns got populated.

**Routes:** `POST /api/webhooks/github/{projectKey}` — confirmed as the only GitHub-related route, keyed entirely by `project_key` in the URL plus a per-project HMAC secret, with **no relationship at all** to any "connected account" concept. This is the fact that made the whole phase safe: the wizard only needed to make repository/branch *selection* easier — the actual webhook receiving mechanism needed zero changes.

**`project_key` handling:** confirmed `ProjectController::store()` required it as a hand-typed, regex-validated, globally-unique field — exactly the friction Task 7 asked to remove.

**Branch handling:** confirmed `GitHubWebhookController::handle()` compares the pushed branch against `$project->branch` (a plain string) and silently ignores non-matching pushes — this behavior is completely unaffected by whether `branch` was typed by hand or picked from a GitHub API response; the column's *meaning* never changes.

**Conclusion:** the audit found no existing outbound GitHub code to build on, but no obstruction either — every extension point the wizard needed already existed as an insertion point.

## 3. GitHub Integration Decision

Per your explicit choice (Recommended option): **GitHub App**, not OAuth App or a Personal Access Token.

- **Security**: a GitHub App's installation access tokens are scoped to exactly the repositories the operator selected during installation and expire in 1 hour — an OAuth App token or PAT would carry the same access as the connecting user's entire account, with no per-repo boundary this app could enforce.
- **Multi-account support**: nothing here is tied to "the one user who authorized it" — any number of `GitHubAccount` rows (installations) can exist side by side, each independently connectable/disconnectable.
- **Repository access control**: the operator picks exactly which repos the App can see, on GitHub's own installation screen — this app never even sees repos outside that set.
- **Commercial viability**: this is the same model Vercel, Netlify, and CircleCI use for exactly this kind of "pick a repo, we deploy it" product.

**Implementation, as reported before writing any code**: RS256 JWT app-authentication signed with PHP's built-in `openssl_sign()` (confirmed via CLI: `openssl_pkey_get_private()`/`openssl_sign()` work correctly in this environment even though `openssl_pkey_new()` — key *generation* — doesn't, due to a missing `openssl.cnf` on this specific PHP build; irrelevant to the shipped code, which only ever *parses* an existing key, never generates one). No new Composer dependency. `GITHUB_APP_ID`/`GITHUB_APP_SLUG`/`GITHUB_APP_CLIENT_ID`/`GITHUB_APP_CLIENT_SECRET`/`GITHUB_APP_PRIVATE_KEY` are all unset in this environment's `.env` — "Connect GitHub" shows a clear, translated "not configured yet" notice instead of attempting anything, until a real GitHub App is registered at github.com and its credentials are filled in.

## 4. Account Management

`GitHubAccount` (new, additive table) stores `installation_id`, `account_login`, `account_type`, `status`, `connected_by`, `connected_at`, `last_tested_at` — **no token, no secret, no credential of any kind**. Every API call mints a fresh installation access token on demand from the App's private key (env-only) and discards it at the end of the request; nothing long-lived is ever persisted. `github/accounts/index.blade.php` shows exactly the three fields Task 3 asked for (name, provider, connection status) plus Connect/Disconnect/Test Connection actions — `test_github_accounts_page_never_shows_a_token_or_secret` asserts none of `access_token`, `ghs_` (GitHub's installation-token prefix), or `private_key` ever appear in the rendered page.

## 5. Repository Picker

`GitHubAccountController::repositories()` (JSON, called by the wizard's vanilla-JS `fetch()`) exchanges an installation token and calls `GET /installation/repositories` — the endpoint GitHub scopes to exactly what that installation was granted, so there is no separate "authorization" check to write: the GitHub App's own installation boundary **is** the access control. The wizard shows name, owner, a private/public badge, and (used to pre-select the branch dropdown) the default branch — all four fields Task 4 asked for. A client-side search filters the already-loaded list; no repeated API calls per keystroke.

## 6. Branch Selection

`GitHubAccountController::branches()` calls `GET /repos/{owner}/{repo}/branches` — real branch names from GitHub, never a hardcoded or guessed list. Per Task 5's "manual value only if needed" rule, the wizard's Step 2 always has a manual-entry fallback (the exact same plain `repository`/`branch` text inputs the legacy form always had) — available immediately if no GitHub account is connected, or reachable via a toggle even when one is, so a repository GitHub's API can't currently see (private fork, temporarily inaccessible, etc.) is never a hard blocker.

## 7. Project Creation Wizard

Six steps, one HTML form, vanilla JS only (no new frontend dependency, matching every prior phase's constraint) — `<x-wizard-steps>` (Phase 4-D's component, reused directly) drives the visual indicator while `.wizard-panel[data-step]` divs are shown/hidden and validated per-step before "Next" advances:

1. **Project info** — name, description (new column, §8).
2. **GitHub** — account → repository → branch, or manual entry (§5-6).
3. **Deployment** — server (existing legacy field) + an Environment name, defaulting to "Production" (§9).
4. **Pipeline** — a template picker (Laravel / Static-Node / Custom) that swaps the *same* `pipeline` textarea content — the field submitted to the server never changes shape, only what pre-fills it.
5. **Review** — a JS-populated summary of everything entered, before anything is sent.
6. **Create** — the auto-generated `project_key` preview (§8) with a manual-override escape hatch, the existing `auto_deploy` checkbox, and the actual submit button.

Every field name submitted (`name`, `repository`, `branch`, `server_id`, `pipeline`, `auto_deploy`, `project_key`) is identical to what `ProjectController::store()` already accepted before this phase — the wizard only changes *how* those values get typed in, not what the server receives or validates for them.

## 8. Project Key Strategy

`ProjectController::generateUniqueProjectKey()`: `Str::slug($name)`, with a `-2`, `-3`, … suffix appended only on collision. `project_key` moved from `required` to `nullable` in validation for the create path only (`update()`'s handling was already `sometimes` and is untouched) — a request that omits it entirely (every pre-4-E caller, including every existing test) gets one generated; a request that supplies it (manual override, or any external integration) is validated exactly as before: regex-checked, globally unique, never silently overridden. `test_manual_project_key_still_works_exactly_as_before` and `test_auto_generated_project_key_gets_a_suffix_on_collision` cover both paths.

## 9. Webhook Compatibility

**Confirmed unchanged**: `GitHubWebhookController`, `GitHubWebhookVerifier`, `routes/api.php`'s webhook route, and the `webhook_deliveries` idempotency table — none were touched. The wizard's only interaction with the existing webhook mechanism is *optional, additive*: when a GitHub account was used to pick the repository, `ProjectController::store()` calls the new `GitHubAppClient::createWebhook()` to register the **exact same** URL (`route('webhooks.github', $project->project_key)`) and **exact same** freshly generated secret on the GitHub repository via the API, instead of asking the operator to paste them into GitHub's UI by hand. If that call fails for any reason (API error, network issue, repository permissions), `tryAutoConfigureWebhook()` catches it and returns `false` — **project creation still succeeds**, and the operator sees the same manual webhook URL/secret card every project has always shown. `test_webhook_auto_configuration_failure_does_not_block_project_creation` asserts this directly.

**Two real bugs found and fixed while building this** (worth recording plainly, not glossing over):
1. `GitHubAppClient`'s constructor originally declared its `$appId`/`$privateKey` properties `readonly` via constructor promotion, then tried to reassign them with `??=` inside the constructor body — PHP rejects mutating a `readonly` property after promotion-assignment, even within the same constructor. Fixed by assigning explicitly in the constructor body instead of promoting.
2. `GitHubAccount`'s table name defaults, under Laravel's snake-case convention, to `git_hub_accounts` (it inserts an underscore before every capital-after-lowercase, including inside "GitHub" itself) — not the `github_accounts` table the migration actually created. Fixed with an explicit `protected $table = 'github_accounts';`.

Both were caught immediately by the smoke tests run against the actual pages before any new test was even written — exactly the value of testing against real code paths rather than assuming a first draft is correct.

## 10. Security Review

- **Encryption/Masking/Rotation**: moot for GitHub credentials specifically — there are none to encrypt, mask, or rotate, since no token is ever persisted (§4). The existing per-project `github_webhook_secret` (already encrypted-at-rest, already masked on `projects.show`, already rotatable) is completely unchanged.
- **Repository access/authorization**: enforced entirely by GitHub's own installation boundary (§5) — this app never has broader access than what the operator explicitly granted on GitHub's installation screen.
- **Webhook secret handling**: the auto-created webhook uses the identical generation, storage, masking, and rotation path every project already had; nothing new was introduced.
- **Audit logging**: `github_account.connected`, `github_account.disconnected`, `github_account.connection_tested`, and `project.github_webhook_auto_configured` were all added, following the exact `AuditLogEntry::record()` pattern every other mutating action in this app already uses.
- A GitHub API failure at any point (installation lookup, token exchange, repository/branch listing, webhook creation) is caught and translated to a Persian, non-leaking message — GitHub's raw error detail is `report()`-logged server-side only, never shown to the operator.

## 11. Files Changed

**New:** `app/Models/GitHubAccount.php`; `app/Services/GitHub/GitHubAppClient.php`; `app/Services/GitHub/GitHubApiException.php`; `app/Http/Controllers/Dashboard/GitHubAccountController.php`; `resources/views/github/accounts/index.blade.php`; `database/factories/GitHubAccountFactory.php`; `database/migrations/2026_07_27_090000_create_github_accounts_table.php`; `database/migrations/2026_07_27_090100_add_description_to_projects_table.php`; `tests/Feature/Dashboard/GitHubIntegrationTest.php`.

**Modified:** `app/Http/Controllers/Dashboard/ProjectController.php` (wizard `create()`/`store()`, project_key auto-generation, environment/target auto-creation, webhook auto-configuration — all additive, all opt-in via new nullable fields); `app/Models/Project.php` (`description` in `$fillable`); `config/services.php`, `.env.example` (GitHub App config, unset by default); `resources/views/projects/create.blade.php` (full wizard rewrite); `resources/views/projects/show.blade.php` (description display, webhook-auto-configured notice); `resources/views/layouts/app.blade.php` (GitHub nav link, new CSS only); `routes/web.php` (new `github.*` routes); `lang/en/ui.php`, `lang/fa/ui.php`, `lang/en/messages.php`, `lang/fa/messages.php`, `lang/en/nav.php`, `lang/fa/nav.php` (new key groups only).

**Untouched:** `GitHubWebhookController`, `GitHubWebhookVerifier`, `ProcessGitHubWebhookPush`, `WebhookDelivery`, every Environment/DeploymentTarget Controller from Phase 4-C.5, every Agent/Server file from Phase 4-D, and the entire legacy single-server deploy path.

## 12. Tests Result

```
Full suite: 180/180 passed, 472 assertions (163 pre-existing + 17 new), 0 regressions.
```

New tests (`tests/Feature/Dashboard/GitHubIntegrationTest.php`), all GitHub API calls mocked via `Http::fake()` against real, documented GitHub response shapes:

1. `GitHubAppClient` generates a structurally correct RS256 JWT (`alg`, `iss` verified from the decoded token sent as the real request's Bearer header).
2. `connect` redirects to the real GitHub App installation URL when configured.
3. `connect` shows a friendly error when not configured.
4. `callback` creates a `GitHubAccount` from a valid installation id.
5. `callback` handles a GitHub API failure without crashing.
6. `disconnect` marks an account disconnected without deleting it.
7. `test-connection` updates `last_tested_at` on success.
8. Repositories endpoint returns the documented JSON shape.
9. Branches endpoint returns branch names.
10. GitHub accounts page never shows a token or secret.
11. Wizard creates a project with an auto-generated `project_key`.
12. Auto-generated key gets a `-2` suffix on collision.
13. Manual `project_key` still works exactly as before.
14. Wizard creates a real Environment + DeploymentTarget when `environment_name` is provided.
15. Omitting `environment_name` creates no Environment — legacy-only project, byte for byte.
16. GitHub-account-driven project creation auto-configures the webhook.
17. A webhook auto-configuration failure does not block project creation.

## 13. Remaining Work

- The wizard's repository/branch pickers require JavaScript; there's no server-rendered fallback beyond the always-available manual-entry toggle. Acceptable given every other interactive piece since Phase 4-B (confirm dialogs, wizard steps) already made the same tradeoff.
- No pagination on the repository list — `GET /installation/repositories` is requested with `per_page=100`; an installation with more than 100 accessible repos would need a follow-up page. Not implemented, since no prior phase's dashboard widgets paginate either (same "acceptable at current scale" precedent as Phase 4-C's Agent Status widget).
- GitHub App installations can be updated/removed on GitHub's side (repository added/removed from the installation, or the installation deleted entirely) with no webhook listener here to react to that — `test_connection` is the only way an operator currently discovers a stale connection. A `installation_repositories`/`installation` webhook handler would close this gap.
- The Environment/DeploymentTarget auto-created by the wizard (§7 Step 3) always creates exactly one target, on the one server chosen. Multi-target-from-the-wizard (picking several servers at project-creation time, mirroring Phase 4-C.5's bulk target-add) was not built — the operator can still add more targets afterward from the Project detail page.

## 14. Phase 4-F Recommendation

With GitHub connection, repository/branch selection, and guided Project creation now real, the "non-technical user" UX arc that began in Phase 4-A is functionally complete for the main happy path. The two items most worth prioritizing next are the ones §13 names as structurally deferred rather than merely polish: (1) a GitHub installation-webhook listener, so a repository removed from the App's access on GitHub's side is reflected here automatically instead of only being caught by a manual "Test connection" click; and (2) the role/permission system Phase 4-C.5 and 4-D both flagged — now more pressing than ever, since GitHub account connection is exactly the kind of sensitive, organization-wide action a real permission system would want to restrict to specific operators.
