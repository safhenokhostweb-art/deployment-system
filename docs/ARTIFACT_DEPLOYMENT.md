# Artifact-Based Deployment (Production has zero direct dependency on GitHub, Packagist, or the npm registry)

## 1. Root cause

Two separate, compounding problems on the production server (Iran):

1. `customer-site`'s deploy pipeline ran `git fetch origin {branch} && git reset
   --hard {commit}` **on the production server itself**. Production cannot
   reach `github.com:443` directly — the connection hangs and fails after
   ~135s.
2. Even once code delivery was solved by shipping a `git archive` artifact
   (see §2 history below), the pipeline still ran `composer install` and
   `npm ci && npm run build` **on production**, which needs
   `repo.packagist.org` and the npm registry — both equally unreachable from
   that server. A `git archive` doesn't include `vendor/` or `public/build/`
   at all (they're gitignored), so production had no working code without
   these steps.

The fix has to move **all three** external dependencies (git, Composer,
npm) off production entirely: the artifact must arrive **complete** —
already containing `vendor/` and `public/build/` — so production never
needs to run any of them.

## 2. Final pipeline

```
Local Commit/Push
  → Local Composer Install
  → Local npm ci/build
  → Full Artifact Build
  → Upload to Hub
  → Production Download
  → Checksum
  → Atomic Activation
  → Skip Git/Composer/npm
  → Migrate
  → Optimize
  → Queue Restart
  → Success
```

Everything left of "Upload to Hub" runs on the **developer's machine** (Local
Agent), which has normal internet access. Everything from "Production
Download" onward runs on the **production server**, which talks to the Hub
only — never to GitHub, Packagist, or the npm registry.

## 3. Architecture

```
Local Agent (has GitHub/Packagist/npm access)     Hub                    Server Agent (no external access)
──────────────────────────────────────────────    ───                    ───────────────────────────────
git push succeeds
   │
   ▼
race-guard: confirm HEAD is still exactly
the commit we were asked to publish
   │
   ▼
create an ISOLATED, disposable build workspace
under %TEMP%\DeploymentLocalAgent\builds\
<project-key>\<job-id>\ — see §5. NEVER any
project's own directory.
   │
   ▼
git archive <commit> → extracted into the
workspace's own project/ directory
(ZIP-Slip-safe extraction — entry names are
validated before extractTo(); see §7)
   │
   ▼
Composer path-repository dependencies (if any)
copied into the workspace at the same relative
position they hold next to the real project — see §5
   │
   ▼
composer install --no-dev --prefer-dist
  --optimize-autoloader --no-interaction
  --no-scripts
   │ (skipped if no composer.json; see §5 for --no-scripts)
   ▼
npm ci --include=dev && npm run build
   │ (skipped if no package.json / no "build" script)
   │  — ALL OF THE ABOVE RUNS INSIDE THE ISOLATED
   │    WORKSPACE ONLY — the real project directory
   │    (its own vendor/, node_modules/) is never
   │    read from or written to at any point.
   ▼
validate the workspace's project/ directory:
vendor/autoload.php present if composer ran;
public/build/manifest.json present if the npm
build ran; not empty
   │
   ▼
build a deterministic zip directly from the
workspace (sorted entries, pinned mtimes,
node_modules/.git excluded — see §6)
   │
   ▼
delete the ENTIRE workspace (guarded — see §5);
a cleanup failure only ever logs a warning,
never affects the Publish Job's own result
   │
   ▼
sha256 of the zip                           →   POST /local-agent/artifacts        (metadata, phase 1)
                                                   creates Artifact(status=uploading)
   │
   ▼
raw zip bytes                               →   POST /local-agent/artifacts/{id}/content   (phase 2)
                                                   verify bytes' sha256 == phase-1 checksum
                                                   independently re-open the zip and check:
                                                     - openable, non-empty
                                                     - any vendor/ entry ⇒ vendor/autoload.php present
                                                     - any public/build/ entry ⇒ public/build/manifest.json present
                                                   (defense-in-depth: the Hub does not simply
                                                   trust whatever the Local Agent already checked)
                                                   Artifact(status=available)
                                                        │
                                            DeploymentDispatcher attaches artifact_id
                                            to the Deployment for that exact
                                            (project_id, commit_hash)
                                                        │
                                            AgentJobService::claimNextJob() includes
                                            {public_id, checksum_sha256, size_bytes}
                                            in the job payload as `artifact`
                                                        │
                                                        ▼
                                                                                GET /agent/artifacts/{id}/download
                                                                                     │
                                                                                verify sha256
                                                                                     │
                                                                                extract to {working_directory}.staging-*
                                                                                     │
                                                                                copy preserve paths (.env, storage,
                                                                                public/storage, artifact_preserve_paths)
                                                                                from the CURRENT working_directory —
                                                                                vendor/ and public/build/ always come
                                                                                from the NEW artifact, never preserved
                                                                                     │
                                                                                atomic swap:
                                                                                  rename(current, .backup-<ts>)
                                                                                  rename(staging, current)
                                                                                     │
                                                                                if activation fails, NO further step
                                                                                runs — job is reported failed here
                                                                                     │
                                                                                continue the pipeline, SKIPPING every
                                                                                step marked skip_when_artifact:true
                                                                                (git pull, composer install, asset
                                                                                build) — migrate, optimize, and
                                                                                queue:restart still run
                                                                                     │
                                                                                cleanup: retain only N most recent
                                                                                .backup-* directories
```

If a project's Local Agent never uploads a matching artifact, `artifact` is
absent from the job payload and the agent runs the **exact legacy pipeline,
byte for byte unchanged** — this is fully additive and opt-in per project
(Sandogh, for example, keeps its current git/composer/npm-on-production
behavior unless artifacts are explicitly enabled for it).

### Why two upload phases instead of one multipart request

PHP's `php://input` (what the existing signed-request HMAC middleware reads
to verify a request's signature) is **not available for
`multipart/form-data` requests**. A single multipart upload could never be
verified by the existing middleware. Splitting into a normal signed JSON
request (metadata, including the checksum) followed by a signed request
whose entire raw body IS the file bytes (`Content-Type:
application/octet-stream`) fits the existing signing scheme with zero
changes to shared middleware.

### Why `git archive` first, not a manual copy+exclude list, and not a shelled `unzip`

`git archive <commit>` packages exactly the tracked files at that commit —
no `.git`, and (since anything gitignored was never tracked) no
`node_modules`, `storage/logs`, `.env` either, with zero bespoke exclusion
logic to maintain. It's extracted into the isolated workspace's own
`project/` directory (see §5) FIRST — composer/npm then run directly
inside that same directory, so `vendor/`/`public/build/` end up exactly
where they need to be without a separate "copy build output in" step
(a real, pre-existing design this replaced — see §5's own root-cause
explanation for why).

Extraction uses PHP's `ZipArchive::extractTo()`, never a shelled `unzip` —
a git symlink blob becomes a plain text file this way, never a real OS
symlink, which is a deliberate safety property (see §7).

## 4. Local build stage (`local-agent/lib/LocalBuildRunner.php`)

Runs inside the isolated workspace's `project/` directory (see §5) — NEVER
the real project directory — each stage individually timed and logged:

0. `composer check-platform-reqs --no-dev --format=json` — only if
   `composer.json` exists, run BEFORE `composer install`. Composer's own,
   authoritative check of `composer.lock`'s real (including transitive)
   `ext-*`/`php` requirements against the PHP environment actually running
   Composer. A genuine missing extension fails fast with
   `LocalBuildFailedException('missing_php_extension')` — never bypassed
   with `--ignore-platform-req` — carrying the exact extension name, the
   package that required it, the loaded php.ini path, and the raw `php
   --ini`/`php -m` output for diagnosis. Best-effort: if this sub-command
   can't produce a verdict at all (old Composer, broken composer_command),
   it silently defers to step 1's own authoritative result instead of
   inventing a false diagnosis.
1. `composer install --no-dev --prefer-dist --optimize-autoloader
   --no-interaction --no-scripts` — only if `composer.json` exists.
   Failure → `LocalBuildFailedException('local_composer_failed')`.
   `--no-scripts` is deliberate — see §5.
2. `npm ci --include=dev` — only if `package.json` exists. Failure →
   `local_npm_install_failed`.
3. `npm run build` — only if `package.json` declares a `"build"` script.
   Failure → `local_asset_build_failed`.

A project using neither Composer nor npm silently skips both stages (not an
error). Any failure stops the publish immediately — **the artifact is never
built** for a commit whose local build failed, and the Publish Job is
reported `failed` with the specific `failure_code`, never `succeeded`.

## 5. Isolated build workspace (`local-agent/lib/BuildWorkspace.php`, `WorkspaceSourcePreparer.php`)

### Root cause

A real bug report, AFTER the local-build-stage design above already
shipped: `composer install`/`npm ci` originally ran **directly inside the
developer's own working tree** (e.g. `C:\laragon\www\customer-site`).
`npm ci` deletes the existing `node_modules/` before reinstalling — but a
native Tailwind CSS binary inside it
(`node_modules\@tailwindcss\oxide-win32-x64-msvc\...node`) was locked by
Windows (VS Code, Vite's own dev server, or an editor's file watcher had
it loaded), so every publish failed:

```
local_npm_install_failed
npm ci failed (exit -4048)
EPERM unlink tailwindcss-oxide.win32-x64-msvc.node
```

Manual retries only ever "worked" by coincidence — closing every other
process first happened to release the lock. Retrying, clearing
`node_modules`, or asking the developer to close their editor are not
real fixes; the actual bug is architectural: **the build should never
have touched the real working tree's `vendor/`/`node_modules/` at all.**

### Design

Every publish gets its own throwaway directory tree:

```
%TEMP%\DeploymentLocalAgent\builds\<project-key>\<job-id>\
  project\           ← git archive extracts the exact published commit here;
                        composer install / npm ci / npm run build all run
                        here — this becomes the artifact's own source tree
  <sibling package>\  ← a Composer path-repository dependency's real content,
                        copied here at the same relative position it holds
                        next to the real project (see below)
```

This directory lives entirely **outside every project's own folder** — so
the Watcher's own `git status`-based change detection can never mistake a
build workspace for a project change, and nothing inside it can ever be
locked by anything the developer has open in their real project.

- **Job ids are unique** (Hub-issued) — two publishes, even for the same
  project, even from two Local Agent processes in principle, always get
  distinct, fully independent workspace paths. No locking of the agent's
  own is needed for this.
- **Cleanup runs in a `finally` block** — success or failure, the whole
  workspace is deleted afterwards. A cleanup failure (e.g. something else
  briefly held a lock on a file *inside* the disposable workspace itself)
  is only ever logged as a `[local_workspace_cleanup_failed]` warning —
  `BuildWorkspace::cleanup()` never throws, so it can never turn an
  otherwise-successful publish into a failed one, or vice versa.
- **Guarded deletion**: every delete this class performs first confirms
  the resolved real path is nested inside the expected base builds
  directory — never equal to that base directory itself. A coding mistake
  fails loudly (`RuntimeException`) rather than silently deleting
  something unexpected.
- **Stale-workspace TTL sweep**: `BuildWorkspace::pruneStale()` runs once
  at the start of every publish (not on its own schedule) and removes any
  workspace older than `workspace_ttl_seconds` (config.php, default 24h)
  — a best-effort catch for whatever a crashed/killed agent process left
  behind; the normal path always cleans up its own workspace regardless.

### Composer path-repository dependencies

Audited case (`customer-site`'s real `composer.json`):

```json
"repositories": [
    {"type": "path", "url": "../saas-platform/packages/saas-platform/modules/tickets", "options": {"symlink": true}},
    {"type": "path", "url": "../saas-platform/packages/saas-platform/modules/forms", "options": {"symlink": true}},
    {"type": "path", "url": "../saas-platform/packages/saas-platform/core-utils", "options": {"symlink": true}}
]
```

`WorkspaceSourcePreparer` reads `repositories[].type === "path"` entries
from composer.json **as archived at the exact published commit** (not the
live working tree — the same commit-fidelity reasoning as `git archive`
itself), resolves each relative URL against the REAL project directory
(the only place it actually exists), and copies that directory's real
content into the workspace at the SAME relative position it holds next to
the real project — generically, for however many path repositories a
project declares, without hardcoding any project's own package names.
`composer install` then resolves `../saas-platform/...` correctly from
inside the workspace, without ever touching the real `saas-platform`
checkout.

An absolute path-repository URL, or one with enough `..` segments to
resolve outside the workspace's own root, is rejected
(`local_workspace_prepare_failed`) rather than silently written somewhere
unexpected.

**Never a Junction/symlink to the real working tree in the final
artifact** — the copy is real, dereferenced file content, confirmed by a
direct test that builds the final zip and asserts the path-dependency's
actual source is inside `vendor/`, not a broken reference to a path that
won't exist once the workspace is deleted.

### Windows Junction detection — two false starts, confirmed by direct testing

Composer's path-repository `"options": {"symlink": true}` is wired up on
Windows as a real NTFS Junction (`mklink /J`). Getting this right took two
rounds of hands-on testing with a real Junction (`mklink /J` + PHP), not
guessing from documentation:

1. `is_link()` does **not** detect a Junction — returns `false` for one.
2. `readlink()`, the obvious next choice, is worse than useless here: on
   this platform it returns a non-`false`, realpath-like string for an
   **ordinary directory too**, not just for a real link. Trusting it
   caused an early version of `recursiveDelete()` to treat every ordinary
   directory as if it were a link and never actually delete its contents
   — workspace cleanup silently did nothing.

The technique that proved reliable: compare `realpath($path)` against
`realpath(dirname($path)) . DIRECTORY_SEPARATOR . basename($path)` — i.e.
"where would this path be if its own final component were an ordinary
entry" vs "where does it actually, fully resolve to". They match for an
ordinary entry and differ for a Junction/symlink. See
`SafeFilesystem::isLinkLikeEntry()` (used only by `recursiveDelete()` —
`copyRecursiveSafely()`/`collectFilesSafely()` don't need it at all, since
always resolving via `realpath()` is correct and harmless for an ordinary
entry too).

`recursiveDelete()` also had to learn that a Windows directory Junction
can **only** be removed via `rmdir()` — `unlink()` fails on it outright
(`DeleteFile()` doesn't work on a directory reparse point, confirmed by
direct testing) — while a POSIX symlink is the opposite (`rmdir()` on a
symlink fails with `ENOTDIR` by spec; `unlink()` is what removes it).
Trying `rmdir()` first, then `unlink()`, is correct on both, and in every
case removes only the link/Junction pointer itself, never recursing into
or deleting its target's real content.

## 6. Complete artifact assembly (`local-agent/lib/ArtifactBuilder.php`)

By the time this class runs, the workspace's `project/` directory (§5)
already IS the complete, built artifact source — the exact commit's
tracked files (`git archive`) plus `vendor/`/`public/build/` (composer/npm
ran directly inside it). This class no longer archives or copies anything
in (that moved to `WorkspaceSourcePreparer`, §5) — it only validates, then
builds the final zip.

Included: every tracked file of the exact commit, `vendor/`, `public/build/`
and its manifest.

Excluded: `.git` (git archive never produces one), `.env` / secrets
(never tracked, never gitignored-then-added-back), `storage/logs`, temp
caches (none of these are ever tracked by git) — and, explicitly, by name,
during the zip-build walk: **`node_modules/`** and **`.git/`**, wherever
found. This exclusion is new and necessary specifically because composer/npm
now run in the SAME directory the artifact is built from (§5) — previously
`node_modules` was never an issue because npm never ran anywhere near the
staging directory in the first place.

Guarantees:
- **Race-free, once, at the right moment**: the working tree is only ever
  read from at the single instant `WorkspaceSourcePreparer` snapshots it
  (`git archive`) — `hash_equals()` against the commit being built,
  checked BEFORE that snapshot. Everything after (composer/npm, zip build)
  operates purely on the isolated, already-snapshotted workspace, so there
  is structurally no later moment for a race to occur.
- **Deterministic**: the workspace is walked, entries sorted
  (`ksort(SORT_STRING)`), each file's mtime pinned to the commit's own
  timestamp (`setMtimeName()`), permissions forced to `0644`
  (`setExternalAttributesName()`) — two builds of the same commit produce
  byte-identical zips.
- **Symlink/Junction-aware, bounded by the WHOLE workspace root**: a
  Composer path-repository dependency is wired up as a real Junction
  inside `vendor/` pointing at a WORKSPACE-SIBLING directory (§5), not
  just somewhere inside the source tree itself — so the safe boundary for
  resolving it has to be the entire workspace root, not merely
  `project/`. See `SafeFilesystem::collectFilesSafely()`.
- **Temp-safe**: a failed final zip is deleted before the exception
  propagates; the whole workspace itself is deleted by
  `BuildWorkspace::cleanup()` regardless of outcome (§5).

## 7. Security

- **ZIP-Slip**: `extractZipSafely()` rejects any zip entry name containing
  `..`, starting with `/`, or matching a Windows drive-letter pattern,
  *before* calling `extractTo()`.
- **Symlink/Junction escape**: `copyRecursiveSafely()`/`collectFilesSafely()`
  resolve `realpath()` for every entry copied/collected; anything resolving
  outside the expected root is skipped, never followed or copied as a
  link. Confirmed correct against a REAL Windows Junction (`mklink /J`,
  exactly how Composer wires up a path-repository package) by direct
  testing — see §5's own "two false starts" writeup for why `is_link()`
  and `readlink()` both proved unreliable for this on Windows, and what
  technique actually works.
- **Path-repository escape**: a Composer path-repository URL that's
  absolute, or has enough `..` segments to resolve outside the isolated
  workspace's own root, is rejected (`local_workspace_prepare_failed`)
  before anything is ever copied — see §5.
- **Guarded workspace deletion**: every delete `BuildWorkspace` performs
  first confirms the resolved real path is nested inside the expected
  base builds directory (never equal to it) — a coding mistake fails
  loudly rather than silently deleting something unexpected; verified by
  a real test that also confirms deletion outside the floor was a true
  refusal (the directory genuinely still exists afterwards), not a silent
  no-op.
- **Command injection**: all process invocation goes through array-form
  `proc_open()` (never a shell string), so branch/commit/path values are
  never interpolated into a shell command.
- **Secrets**: `.env` is never tracked by git and is never one of the
  build-output directories composer/npm produce, so it structurally
  cannot enter the artifact — and, separately, never enters the isolated
  build workspace at all in the first place, since the workspace is
  populated purely from `git archive` (§5).
- **Defense-in-depth on the Hub**: `ArtifactService::finalizeUpload()`
  independently re-opens the uploaded zip and re-checks
  vendor/autoload.php and public/build/manifest.json presence — it does
  not simply trust whatever the Local Agent already validated, matching
  the same "don't rely on a single enforcement point" precedent already
  used elsewhere in this codebase (`ServiceAllowlist`).
- **Large files**: build/zip work happens through streaming
  read/write (`ZipArchive`, filesystem copy) rather than loading whole
  files into a PHP string; the request/response memory-buffering caveat
  from the original design (documented in §14 "Remaining risks") still
  applies to the HTTP transfer layer itself, unchanged by this work.

## 8. Production pipeline

`git pull`, `composer install`, and `asset build` all carry
`"skip_when_artifact": true` and are skipped **only** when the job carries a
valid `artifact` that activates successfully. `migrate`, `optimize`, and
`queue:restart` always run. If artifact activation fails, no subsequent
step runs at all — the job is reported failed at that point. Projects with
no artifact for a given deploy keep running the complete, unchanged legacy
pipeline.

Current preset (`PIPELINE_PRESET_LARAVEL` in `ProjectController`), matching
`customer-site`'s own pipeline:

```json
[
    {"name": "git pull", "command": "git fetch origin {branch} && git reset --hard {commit}", "skip_when_artifact": true},
    {"name": "composer install", "command": "composer install --no-dev --optimize-autoloader --no-interaction", "skip_when_artifact": true},
    {"name": "migrate", "command": "{php_binary} artisan migrate --force"},
    {"name": "cache clear", "command": "{php_binary} artisan optimize:clear && {php_binary} artisan optimize"},
    {"name": "asset build", "command": "npm ci --include=dev && npm run build", "skip_when_artifact": true},
    {"name": "queue restart", "command": "{php_binary} artisan queue:restart"}
]
```

## 9. Preservation and atomic swap

Preserved from the previously-active release: `.env`, `storage/`,
`public/storage`, and any path listed in a project's
`artifact_preserve_paths`. **`vendor/` and `public/build/` are never
preserved** — they always come from the new artifact, proven by a real
test that embeds a release-specific marker into each fixture artifact and
asserts the new marker wins after every swap (`agent/tests/run_artifact.php`).

Swap is a two-step rename (`current` → `.backup-<ts>`, `staging` →
`current`); a failure before the final rename leaves the live site
untouched, and a failure on the second rename attempts to restore the
backup immediately.

## 10. Statuses and failure codes

A Publish Job only becomes `succeeded` once local build, archive, *and*
upload have all succeeded — a failure anywhere in that chain fails the
Publish Job itself with a specific code (`PublishJob::AGENT_REPORTABLE_FAILURE_CODES`):

| `failure_code` | Meaning |
|---|---|
| `missing_php_extension` | The Local Agent's configured PHP binary is missing a `composer.lock`-required PHP extension (e.g. ext-gd) — detected via `composer check-platform-reqs` BEFORE `composer install` runs; never bypassed with `--ignore-platform-req` |
| `local_composer_failed` | `composer install` failed on the Local Agent |
| `local_npm_install_failed` | `npm ci` failed on the Local Agent |
| `local_asset_build_failed` | `npm run build` failed on the Local Agent |
| `artifact_build_failed` | Archiving/staging/zip assembly failed |
| `artifact_upload_failed` | Upload to the Hub failed (network, Hub rejection) |
| `artifact_missing_vendor` | Composer ran but `vendor/autoload.php` is absent from staging/the uploaded zip |
| `artifact_missing_build` | The npm build ran but `public/build/manifest.json` is absent from staging/the uploaded zip |
| `local_workspace_create_failed` | Could not create the isolated build workspace directory (disk full, permissions) — see §5 |
| `local_workspace_prepare_failed` | Populating the workspace failed — a Composer path-repository URL doesn't exist, or resolves outside the workspace's own root (see §5); never `local_workspace_cleanup_failed`, which is deliberately NOT reportable (see below) |
| `process_timeout` | Pre-existing: any step exceeded its timeout |

A workspace **cleanup** failure is never one of the codes above — it can
only ever be logged locally (`[local_workspace_cleanup_failed]`,
`BuildWorkspace::cleanup()` never throws) and must never affect whether
the Publish Job itself succeeded or failed; see §5.

An `Artifact` moves `uploading` → `available` only after both phases and
Hub-side content validation succeed (`failed` otherwise); a `Deployment`
carries the matching `artifact_id` once `DeploymentDispatcher` attaches it.
The dashboard's Publish Job / Deployment output makes the split explicit —
Publish Job output includes a `[artifact] uploaded: ...` (or `FAILED:
...`) line, and a Deployment that used an artifact logs a single `deploy
artifact` step in place of the git/composer/npm steps it skipped.

## 11. Changed / new files

**Local Agent (`local-agent/` + mirrored byte-for-byte into `deployment-local-agent/agent/`)** — cumulative through v1.4.2:
- `lib/LocalBuildRunner.php` — composer/npm orchestration (§4); `composer_command`/`npm_command`/`php_binary` config (v1.4.1, the PATH-independence fix); `composer check-platform-reqs` pre-flight (v1.4.2, §4 step 0); `--no-scripts` (v1.4.2, §5)
- `lib/LocalBuildFailedException.php`
- `lib/SafeFilesystem.php` (new, v1.4.2) — shared ZIP-Slip/symlink-safe extract/copy/collect/delete primitives, see §5/§7
- `lib/BuildWorkspace.php` (new, v1.4.2) — isolated workspace lifecycle, see §5
- `lib/WorkspaceSourcePreparer.php` (new, v1.4.2) — populates a workspace (git archive + path-dependency copy), see §5
- `lib/ArtifactBuilder.php` (rewritten, v1.4.2) — no longer archives/copies (moved to `WorkspaceSourcePreparer`); validates + zips an already-prepared workspace, see §6
- `lib/ArtifactUploader.php` (rewritten, v1.4.2) — owns the workspace's full lifecycle (prune stale → create → prepare → build → upload → cleanup)
- `lib/GitManager.php` — Windows `.cmd`-shim `proc_open()` resolution fix (`resolveArgv0ForWindows()`)
- `agent.php` — `LOCAL_AGENT_VERSION`: `1.3.0` → `1.4.0` → `1.4.1` (PATH fix) → `1.4.2` (missing-extension pre-check + isolated workspace, shipped together); publish branch fails the job with the specific `failure_code` on any build/archive/upload failure
- `config.example.php` — documents `composer_command`/`npm_command`/`php_binary`/`workspace_base_dir`/`workspace_ttl_seconds`

**Local Agent tests (`local-agent/tests/`)**
- `run_command_resolution.php`, `run_agent_config_validation.php` (v1.4.1)
- `run_platform_requirements.php` (v1.4.2, missing-extension pre-check)
- `run_workspace_isolation.php` (new, v1.4.2) — the isolated-workspace suite, see §12

**Hub (`service/`)**
- `app/Services/Deployment/Artifact/ArtifactService.php` — `finalizeUpload()` independently re-validates archive contents
- `app/Services/Deployment/Artifact/ArtifactValidationException.php`
- `app/Http/Controllers/Api/LocalAgentArtifactController.php` — catches `ArtifactValidationException`, returns `{message, reason}` with 422
- `app/Models/PublishJob.php` — failure-code constants + `AGENT_REPORTABLE_FAILURE_CODES`, including `missing_php_extension`, `local_workspace_create_failed`, `local_workspace_prepare_failed` (v1.4.2)
- `app/Http/Controllers/Api/LocalAgentJobController.php` — `report()` validation widened to accept every reportable failure code
- `app/Http/Controllers/Dashboard/ProjectController.php` — presets: `composer install`/`asset build` get `skip_when_artifact: true`
- `config/deployment.php` — `latest_local_agent_version` bumped to `1.4.2`
- `tests/Unit/LocalAgentVersionSyncTest.php` — regression guard keeping the version identical across both agent trees and the Hub's own default

**Server Agent (`agent/`)**
- `lib/PipelineExecutor.php` (new) — legacy `pollAndExecute()` pipeline loop extracted verbatim for testability; behavior unchanged
- `agent.php` — delegates to `PipelineExecutor`

## 12. Tests (all passing, real/non-mocked — see below for exact numbers)

- Hub (`php artisan test`, full suite): **644/644**
- Server Agent (`agent/tests/`), unaffected by this change, re-confirmed:
  `run.php` **34/34**, `run_recipe.php` **15/15**, `run_artifact.php`
  **21/21**, `run_pipeline.php` **18/18** — **88/88**
- Local Agent (`local-agent/tests/`): `run.php` **97/97**,
  `run_command_resolution.php` **23/23**,
  `run_agent_config_validation.php` **10/10**,
  `run_platform_requirements.php` **19/19** (the
  `check-platform-reqs` pre-flight, §4 step 0), `run_artifact.php`
  **34/34** (rewritten for `ArtifactBuilder`'s new,
  narrower "build an already-prepared directory" API — the
  archive/copy-in logic it used to own moved to
  `WorkspaceSourcePreparer`), `run_workspace_isolation.php` **30/30**
  (new — see below) — **213/213**

`run_workspace_isolation.php` specifically proves, for real, on Windows:
- the real working tree's pre-existing `vendor/`/`node_modules/` are
  byte-for-byte and mtime-for-mtime unchanged after a full publish
- **the actual reported bug, reproduced and fixed**: a file inside the
  real working tree's `node_modules/` locked by a genuinely separate
  process (`FileShare::None`, confirmed to reproduce the exact "Resource
  temporarily unavailable" error a plain `fopen()` handle does NOT) never
  stops the build, and is left completely untouched — still locked, still
  present, unchanged
- the race guard (moved from `ArtifactBuilder` to
  `WorkspaceSourcePreparer`) still refuses a stale commit
- an uncommitted working-tree edit never enters the archived workspace
- Composer path-repository dependencies (customer-site's real, audited
  case) are copied in and end up self-contained inside `vendor/` in the
  final artifact — real content, not a Junction reference — while the
  real sibling package directory is never modified; an escaping/absolute
  path-repository URL is rejected
- workspace cleanup succeeds on the normal path, and — when something
  inside the workspace is locked — logs a `[local_workspace_cleanup_failed]`
  warning without throwing and without corrupting the job's own result
- `guardedRemoveTree()` genuinely refuses to delete outside its floor (a
  real, existing-but-unrelated directory)
- two jobs for the same project get fully independent workspaces with
  zero cross-contamination; a workspace never lands inside any project's
  own directory (so the Watcher can never mistake one for a project
  change)
- a workspace base directory containing spaces works end-to-end
- `--no-scripts` is genuinely applied — a `post-autoload-dump` script
  that would always fail never runs

All tests run real processes (real `composer`, real `npm`, real `git`,
real `ZipArchive`, a real fake-Hub `php -S` server over real HTTP, and for
the locked-file reproduction, a real separate process holding a real
Windows file handle) — no mocking of the build/archive/upload pipeline
itself.

## 13. Manual steps required to activate this for a project

Nothing here was executed against production — these are the operator's
own steps:

1. **Local Agent**: upgrade every developer machine's Local Agent to
   v1.4.2 (`upgrade.ps1` from an updated `deployment-local-agent/`
   package — copies `agent.php` + `lib/*.php` only, `config.php` is never
   touched).
2. **Server Agent**: deploy the updated `agent/` directory (adds
   `lib/PipelineExecutor.php`) and restart the agent service.
3. **Local Project Mapping**: confirm the developer's Local Agent has a
   `LocalProjectMapping` for the project (required for artifact upload).
4. **Pipeline**: existing projects created before this change need
   `"skip_when_artifact": true` added to their `composer install` and
   `asset build` steps manually (new projects get it automatically from
   the updated presets) — see the exact JSON in §8.
5. **(Optional) `artifact_preserve_paths`**: set on the Project if
   anything beyond `.env`/`storage`/`public/storage` must survive a swap.
6. **(Optional) `workspace_base_dir`/`workspace_ttl_seconds`**: config.php
   keys on the Local Agent — default to `%TEMP%\DeploymentLocalAgent\builds`
   and 24h; only worth setting explicitly if `%TEMP%` is unusually
   space-constrained or slow (e.g. a network-mapped profile directory).
7. First real publish after this: watch the Publish Job's own output — it
   now includes `[artifact] uploaded: ...` (or a specific `FAILED:
   [failure_code] ...` line) — and confirm the next Deployment's log shows
   a single `deploy artifact` step instead of git/composer/npm.

## 14. Remaining risks

- **Local build time**: `composer install`/`npm ci`/`npm run build` now
  run on every publish on the developer's own machine — slower publishes
  than the old "push and let production do it" flow, in exchange for zero
  runtime dependency on external registries. The isolated workspace adds
  a small further cost on top (a fresh `git archive` extract and, for
  path-repository projects, copying those packages in) in exchange for
  the working tree never being touched at all.
- **Composer path-repository glob patterns unsupported**: Composer itself
  supports glob URLs (e.g. `"../packages/*"`) in a path repository —
  `WorkspaceSourcePreparer` only handles exact-directory URLs (the real,
  audited case for every project this ships to today). A project that
  starts using a glob would fail clearly at the "does not exist" check
  rather than silently resolving wrong.
- **`package:discover`-triggered caches are built lazily in production,
  not at build time**: `--no-scripts` means `bootstrap/cache/packages.php`
  isn't pre-generated by the local build — Laravel's own `PackageManifest`
  regenerates it the first time any artisan command boots the framework
  in production (the very first pipeline step, `migrate`, does this),
  using the real `.env` — see §5's own root-cause writeup. A production
  `bootstrap/cache/` that isn't writable would surface as a failure at
  that point; this is a pre-existing requirement of `artisan
  optimize`/`config:cache` succeeding at all, not a new one.
- **Large-body memory buffering**: both the signed-request middleware and
  the Server Agent's checksum step buffer the full request/file into
  memory; unchanged from before this segment, still worth revisiting if an
  artifact ever approaches the configured max size.
- **No cross-server rollback command yet**: `.backup-*` directories are
  retained on disk but reactivating one today means an operator manually
  renaming a backup directory back into place.
- **`export-ignore` reliance**: a project needing to exclude a *tracked*
  file from the artifact must add it to `.gitattributes`.

## 15. Explicit confirmation

No commit, no push, and no production deployment were performed as part of
this work. No SSH connection was made to any server. All 644 Hub tests plus
88 Server Agent tests plus 213 Local Agent tests (945 total) referenced
above ran against the local development database/filesystem only. The
real `customer-site`/`saas-platform` projects were read from directly
(their exact path-repository configuration, per §5, was audited from
their real `composer.json`) but never modified — every write in this
segment's testing happened inside disposable, session-scratch temp
directories, never inside either real project's own working tree.
