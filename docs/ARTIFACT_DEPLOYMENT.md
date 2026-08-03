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
composer install --no-dev --prefer-dist
  --optimize-autoloader --no-interaction
   │ (skipped if no composer.json)
   ▼
npm ci --include=dev && npm run build
   │ (skipped if no package.json / no "build" script)
   ▼
race-guard: confirm HEAD is still exactly
the commit we were asked to publish
   │
   ▼
git archive <commit> → staging directory
(ZIP-Slip-safe extraction — entry names are
validated before extractTo(); see §6)
   │
   ▼
copy vendor/ and public/build/ from the
working tree into staging (symlink-escape
guarded — see §6)
   │
   ▼
validate staging: vendor/autoload.php present
if composer ran; public/build/manifest.json
present if the npm build ran; staging not empty
   │
   ▼
build a deterministic zip from staging
(sorted entries, pinned mtimes/permissions)
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

### Why `git archive` + copy, not a manual copy+exclude list, and not a shelled `unzip`

`git archive <commit>` packages exactly the tracked files at that commit —
no `.git`, and (since anything gitignored was never tracked) no
`node_modules`, `storage/logs`, `.env` either, with zero bespoke exclusion
logic to maintain. Since `vendor/` and `public/build/` are gitignored too,
they're copied in separately from the already-built working tree, straight
into the same staging directory, after the archive is extracted.

Extraction uses PHP's `ZipArchive::extractTo()`, never a shelled `unzip` —
a git symlink blob becomes a plain text file this way, never a real OS
symlink, which is a deliberate safety property (see §6).

## 4. Local build stage (`local-agent/lib/LocalBuildRunner.php`)

Runs before the artifact is ever archived, in the project's own directory,
each stage individually timed and logged:

1. `composer install --no-dev --prefer-dist --optimize-autoloader
   --no-interaction` — only if `composer.json` exists. Failure →
   `LocalBuildFailedException('local_composer_failed')`.
2. `npm ci --include=dev` — only if `package.json` exists. Failure →
   `local_npm_install_failed`.
3. `npm run build` — only if `package.json` declares a `"build"` script.
   Failure → `local_asset_build_failed`.

A project using neither Composer nor npm silently skips both stages (not an
error). Any failure stops the publish immediately — **the artifact is never
built** for a commit whose local build failed, and the Publish Job is
reported `failed` with the specific `failure_code`, never `succeeded`.

## 5. Complete artifact assembly (`local-agent/lib/ArtifactBuilder.php`)

Included: every tracked file of the exact commit (via `git archive`),
`vendor/`, `public/build/` and its manifest, in a staging directory.

Excluded (by construction, never present to begin with): `.git`, `.env` /
secrets, `node_modules`, `storage/logs`, temp caches, npm/composer caches —
none of these are ever tracked by git, and only `vendor/`/`public/build/`
are deliberately added back in from the working tree.

Guarantees:
- **Race-free**: before archiving, `git->lastCommitHash()` is compared
  (`hash_equals`) against the commit the artifact is being built for; a
  moved working tree aborts the build rather than silently archiving the
  wrong commit.
- **Deterministic**: staging is walked, entries sorted (`ksort(SORT_STRING)`),
  each file's mtime pinned to the commit's own timestamp
  (`setMtimeName()`), permissions forced to `0644`
  (`setExternalAttributesName()`) — two builds of the same commit produce
  byte-identical zips.
- **Temp-safe**: the archive temp file and staging directory are always
  deleted in a `finally` block, success or failure; a failed final zip is
  also deleted before the exception propagates.

## 6. Security

- **ZIP-Slip**: `extractZipSafely()` rejects any zip entry name containing
  `..`, starting with `/`, or matching a Windows drive-letter pattern,
  *before* calling `extractTo()`.
- **Symlink escape**: `copyRecursiveSafely()` resolves `realpath()` for any
  symlink encountered while copying `vendor/`/`public/build/`; anything
  resolving outside the expected source root is skipped, never followed or
  copied as a symlink.
- **Command injection**: all process invocation goes through array-form
  `proc_open()` (never a shell string), so branch/commit/path values are
  never interpolated into a shell command.
- **Secrets**: `.env` is never tracked by git and is never one of the two
  explicitly-added build-output directories, so it structurally cannot
  enter the artifact.
- **Defense-in-depth on the Hub**: `ArtifactService::finalizeUpload()`
  independently re-opens the uploaded zip and re-checks
  vendor/autoload.php and public/build/manifest.json presence — it does
  not simply trust whatever the Local Agent already validated, matching
  the same "don't rely on a single enforcement point" precedent already
  used elsewhere in this codebase (`ServiceAllowlist`).
- **Large files**: build/zip work happens through streaming
  read/write (`ZipArchive`, filesystem copy) rather than loading whole
  files into a PHP string; the request/response memory-buffering caveat
  from the original design (documented in §9 "Remaining risks") still
  applies to the HTTP transfer layer itself, unchanged by this work.

## 7. Production pipeline

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

## 8. Preservation and atomic swap

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

## 9. Statuses and failure codes

A Publish Job only becomes `succeeded` once local build, archive, *and*
upload have all succeeded — a failure anywhere in that chain fails the
Publish Job itself with a specific code (`PublishJob::AGENT_REPORTABLE_FAILURE_CODES`):

| `failure_code` | Meaning |
|---|---|
| `local_composer_failed` | `composer install` failed on the Local Agent |
| `local_npm_install_failed` | `npm ci` failed on the Local Agent |
| `local_asset_build_failed` | `npm run build` failed on the Local Agent |
| `artifact_build_failed` | Archiving/staging/zip assembly failed |
| `artifact_upload_failed` | Upload to the Hub failed (network, Hub rejection) |
| `artifact_missing_vendor` | Composer ran but `vendor/autoload.php` is absent from staging/the uploaded zip |
| `artifact_missing_build` | The npm build ran but `public/build/manifest.json` is absent from staging/the uploaded zip |
| `process_timeout` | Pre-existing: any step exceeded its timeout |

An `Artifact` moves `uploading` → `available` only after both phases and
Hub-side content validation succeed (`failed` otherwise); a `Deployment`
carries the matching `artifact_id` once `DeploymentDispatcher` attaches it.
The dashboard's Publish Job / Deployment output makes the split explicit —
Publish Job output includes a `[artifact] uploaded: ...` (or `FAILED:
...`) line, and a Deployment that used an artifact logs a single `deploy
artifact` step in place of the git/composer/npm steps it skipped.

## 10. Changed / new files (this segment)

**Local Agent (`local-agent/` + mirrored byte-for-byte into `deployment-local-agent/agent/`)**
- `lib/LocalBuildRunner.php` (new) — composer/npm orchestration, see §4
- `lib/LocalBuildFailedException.php` (new)
- `lib/ArtifactBuilder.php` (rewritten) — staging-based complete assembly, see §5
- `lib/ArtifactUploader.php` (rewritten) — runs `LocalBuildRunner` then `ArtifactBuilder`, maps every failure to its `failure_code`
- `lib/GitManager.php` — Windows `.cmd`-shim `proc_open()` resolution fix (`resolveArgv0ForWindows()`), needed once composer/npm invocations were added
- `agent.php` — `LOCAL_AGENT_VERSION` bumped `1.3.0` → `1.4.0`; publish branch now fails the job with the specific `failure_code` on any build/archive/upload failure instead of best-effort reporting

**Hub (`service/`)**
- `app/Services/Deployment/Artifact/ArtifactService.php` — `finalizeUpload()` now independently re-validates archive contents (`validateArchiveContents()`)
- `app/Services/Deployment/Artifact/ArtifactValidationException.php` (new)
- `app/Http/Controllers/Api/LocalAgentArtifactController.php` — catches `ArtifactValidationException`, returns `{message, reason}` with 422
- `app/Models/PublishJob.php` — new failure-code constants + `AGENT_REPORTABLE_FAILURE_CODES`
- `app/Http/Controllers/Api/LocalAgentJobController.php` — `report()` validation widened to accept every reportable failure code
- `app/Http/Controllers/Dashboard/ProjectController.php` — presets: `composer install`/`asset build` get `skip_when_artifact: true`; asset build command becomes `npm ci --include=dev && npm run build`
- `config/deployment.php` — `latest_local_agent_version` bumped to `1.4.0`

**Server Agent (`agent/`)**
- `lib/PipelineExecutor.php` (new) — legacy `pollAndExecute()` pipeline loop extracted verbatim for testability; behavior unchanged
- `agent.php` — delegates to `PipelineExecutor`

## 11. Tests (all passing, real/non-mocked — see below for exact numbers)

- Hub (`php artisan test`, full suite): **644/644**, including new
  `ArtifactServiceTest` (10 — valid zip, checksum mismatch, corrupt zip,
  empty archive, vendor-without-autoload, vendor-with-autoload,
  build-without-manifest, neither-vendor-nor-build, oversized body) and
  `PublishJobArtifactFailureCodeTest` (2 — every reportable failure code
  accepted and stored, unknown code rejected)
- Server Agent (`agent/tests/`): `run.php` **34/34**, `run_recipe.php`
  **15/15**, `run_artifact.php` **21/21** (extended this segment: confirms
  vendor/public-build always come from the new artifact, never preserved),
  `run_pipeline.php` **18/18** (new — `PipelineExecutor`: legacy path
  unaffected, artifact success + step-skip, activation-failure stops all
  later steps, a failing step stops subsequent steps)
- Local Agent (`local-agent/tests/`): `run.php` **97/97**, `run_artifact.php`
  **31/31** (rewritten this segment — real `composer install`/`npm ci`/`npm
  run build` against real small fixtures including failure cases, real
  `ArtifactBuilder` assembly/exclusions/determinism/race-guard/ZIP-Slip,
  real `ArtifactUploader` success and failure paths)

All tests run real processes (real `composer`, real `npm`, real `git`,
real `ZipArchive`, a real fake-Hub `php -S` server over real HTTP) — no
mocking of the build/archive/upload pipeline itself.

## 12. Manual steps required to activate this for a project

Nothing here was executed against production — these are the operator's
own steps:

1. **Local Agent**: upgrade every developer machine's Local Agent to
   v1.4.0.
2. **Server Agent**: deploy the updated `agent/` directory (adds
   `lib/PipelineExecutor.php`) and restart the agent service.
3. **Local Project Mapping**: confirm the developer's Local Agent has a
   `LocalProjectMapping` for the project (required for artifact upload).
4. **Pipeline**: existing projects created before this change need
   `"skip_when_artifact": true` added to their `composer install` and
   `asset build` steps manually (new projects get it automatically from
   the updated presets) — see the exact JSON in §7.
5. **(Optional) `artifact_preserve_paths`**: set on the Project if
   anything beyond `.env`/`storage`/`public/storage` must survive a swap.
6. First real publish after this: watch the Publish Job's own output — it
   now includes `[artifact] uploaded: ...` (or a specific `FAILED:
   [failure_code] ...` line) — and confirm the next Deployment's log shows
   a single `deploy artifact` step instead of git/composer/npm.

## 13. Remaining risks

- **Local build time**: `composer install`/`npm ci`/`npm run build` now
  run on every publish on the developer's own machine — slower publishes
  than the old "push and let production do it" flow, in exchange for zero
  runtime dependency on external registries.
- **Large-body memory buffering**: both the signed-request middleware and
  the Server Agent's checksum step buffer the full request/file into
  memory; unchanged from before this segment, still worth revisiting if an
  artifact ever approaches the configured max size.
- **No cross-server rollback command yet**: `.backup-*` directories are
  retained on disk but reactivating one today means an operator manually
  renaming a backup directory back into place.
- **`export-ignore` reliance**: a project needing to exclude a *tracked*
  file from the artifact must add it to `.gitattributes`.

## 14. Explicit confirmation

No commit, no push, and no production deployment were performed as part of
this work. No SSH connection was made to any server. All 644 Hub tests plus
88 Server Agent tests plus 128 Local Agent tests referenced above ran
against the local development database/filesystem only.
