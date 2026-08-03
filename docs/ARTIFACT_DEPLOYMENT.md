# Artifact-Based Deployment (removing production's direct GitHub dependency)

## 1. Root cause

`customer-site`'s production deploy pipeline's first step was:

```
git fetch origin {branch} && git reset --hard {commit}
```

...run **on the production server itself** by the Server Agent. The production
server (Iran) cannot reach `github.com:443` directly — the connection hangs
and fails with `Couldn't connect to server` after ~135s; SSH on port 22/443
also times out. Local Agent → GitHub push already worked (the Local Agent
runs on a developer machine with normal internet access), Server Agent
claiming/authentication was already healthy, `project_key` resolution was
correct — the **only** broken link was the production server's own outbound
connection to GitHub, which the architecture had no way to route around.

## 2. Architecture

```
Local Agent (has GitHub access)          Hub                          Server Agent (no GitHub access)
──────────────────────────────           ───                          ───────────────────────────────
git push succeeds
   │
   ▼
git archive --format=zip <commit>   →   POST /local-agent/artifacts        (metadata, phase 1)
   │ (excludes .git and everything        creates Artifact(status=uploading)
   │  gitignored — no .env, vendor,
   │  node_modules, storage/logs by
   │  construction, zero bespoke
   │  exclude-list to maintain)
   ▼
sha256 of the zip                  →   POST /local-agent/artifacts/{id}/content   (raw bytes, phase 2)
                                          verifies bytes' sha256 == phase-1 checksum
                                          stores on private `artifacts` disk
                                          Artifact(status=available)
                                                │
                                    DeploymentDispatcher attaches artifact_id
                                    to any Deployment created for that exact
                                    (project_id, commit_hash) — webhook or manual
                                                │
                                    AgentJobService::claimNextJob() includes
                                    {public_id, checksum_sha256, size_bytes}
                                    in the job payload as `artifact`
                                                │
                                                ▼
                                                                        GET /agent/artifacts/{id}/download
                                                                        (scoped: only a Deployment on THIS
                                                                        server may fetch it)
                                                                             │
                                                                        verify sha256
                                                                             │
                                                                        extract to {working_directory}.staging-*
                                                                             │
                                                                        copy preserve paths (.env, storage,
                                                                        public/storage, ...) from the CURRENT
                                                                        working_directory into staging
                                                                             │
                                                                        atomic swap:
                                                                          rename(current, .backup-<ts>)
                                                                          rename(staging, current)
                                                                        (failure at any point before the final
                                                                        rename leaves the live site untouched;
                                                                        a failure on the second rename attempts
                                                                        to restore the backup immediately)
                                                                             │
                                                                        continue running the Project's normal
                                                                        pipeline, SKIPPING any step marked
                                                                        skip_when_artifact:true (the git-fetch
                                                                        step) — composer install, migrate,
                                                                        optimize, queue:restart all still run
                                                                             │
                                                                        cleanup: keep only N most recent
                                                                        .backup-* directories
```

The production server now **only ever talks to the Hub** — never to GitHub —
for both polling and code delivery. If a project's Local Agent never
uploads a matching artifact (every project not opted into this flow, or an
upload that hasn't landed yet for whatever reason), `artifact` is simply
absent from the job payload and the agent runs the **exact legacy
git-based pipeline, byte for byte unchanged**. This is a fully additive,
opt-in path — no project's behavior changes unless its pipeline is
explicitly edited to add `skip_when_artifact: true` and its Local Agent is
actively producing artifacts.

### Why two upload phases instead of one multipart request

Discovered while building this, not a stylistic choice: PHP's `php://input`
(what the existing signed-request HMAC middleware reads to verify a
request's signature) is **not available for `multipart/form-data`
requests**. A single multipart upload could never be verified by the
existing middleware. Splitting into a normal signed JSON request (metadata,
including the checksum) followed by a signed request whose entire raw body
IS the file bytes (`Content-Type: application/octet-stream`) fits the
existing signing scheme with **zero changes to shared middleware**, and the
checksum authenticated in phase one is exactly what phase two's received
bytes are verified against.

### Why `git archive`, not a manual copy+exclude list

`git archive <commit>` packages exactly the tracked files at that commit —
no `.git`, and (since anything gitignored was, by definition, never
tracked) no `node_modules`, `vendor`, `storage/logs`, `.env` either, with
zero bespoke exclusion logic to keep in sync with each project's own
`.gitignore`. A project needing to exclude a file that IS tracked uses
git's own native `export-ignore` attribute in `.gitattributes` — the
standard mechanism for exactly this, not a deployment-system-specific
config format.

## 3. Changed / new files

**Hub (`service/`)**
- `database/migrations/2026_08_20_090000_create_artifacts_table.php`
- `database/migrations/2026_08_20_090100_add_artifact_id_to_deployments_table.php`
- `database/migrations/2026_08_20_090200_add_artifact_preserve_paths_to_projects_table.php`
- `app/Models/Artifact.php` (new)
- `app/Models/Project.php`, `app/Models/Deployment.php` (relations/fillable)
- `app/Services/Deployment/Artifact/ArtifactService.php` (new)
- `app/Http/Controllers/Api/LocalAgentArtifactController.php` (new)
- `app/Http/Controllers/Api/AgentArtifactController.php` (new)
- `app/Services/Deployment/DeploymentDispatcher.php` (attaches matching artifact)
- `app/Services/Deployment/AgentJobService.php` (`artifact` in job payload)
- `app/Console/Commands/CleanupArtifacts.php` (new) + scheduled hourly in `routes/console.php`
- `app/Http/Controllers/Dashboard/ProjectController.php` (presets get `skip_when_artifact`)
- `config/deployment.php`, `config/filesystems.php` (new `artifacts` disk + config keys)
- `routes/api.php` (new endpoints, see below)
- `database/factories/ArtifactFactory.php` (new)

**Local Agent (`local-agent/` + mirrored into `deployment-local-agent/agent/`)**
- `lib/ArtifactBuilder.php` (new) — `git archive` + checksum
- `lib/ArtifactUploader.php` (new) — orchestrates the two-phase upload
- `lib/Client.php` — new `uploadRawBody()` method
- `agent.php` — builds+uploads an artifact right after a successful publish; `LOCAL_AGENT_VERSION` bumped `1.2.1` → `1.3.0`

**Server Agent (`agent/`)**
- `lib/ArtifactManager.php` (new) — download, verify, extract, preserve, atomic swap, retention
- `lib/Client.php` — new `downloadToFile()` method
- `agent.php` — deploys the artifact (if present) before running the pipeline; skips any `skip_when_artifact` step
- `config.example.php` — documents new per-project `artifact_preserve_paths` / `artifact_retention_count`

## 4. New endpoints

| Method | Path | Caller | Purpose |
|---|---|---|---|
| POST | `/api/local-agent/artifacts` | Local Agent | Phase 1: record artifact metadata (project_key, commit_hash, branch, checksum, size) |
| POST | `/api/local-agent/artifacts/{id}/content` | Local Agent | Phase 2: raw file bytes, verified against phase 1's checksum |
| GET | `/api/agent/artifacts/{id}/download` | Server Agent | Download an artifact tied to one of this server's own Deployments |

All three sit behind the existing `local-agent.signed` / `agent.signed` HMAC middleware — no new auth mechanism.

## 5. Tests (all passing)

- Hub: 632/632 (full suite) — new coverage: `LocalAgentArtifactFlowTest` (8), `AgentArtifactDownloadTest` (5), `ArtifactDeploymentLinkingTest` (5), `CleanupArtifactsTest` (4)
- Server Agent (`agent/tests/run_artifact.php`): 19/19 — real download over real HTTP, checksum success/mismatch, real zip extraction, preserve-path copy across a real atomic swap, backup retention, three distinct failure paths (download failure, checksum mismatch, corrupt zip)
- Local Agent (`local-agent/tests/run_artifact.php`): 19/19 — real `git archive` against a real repo verified with `ZipArchive` (proves `.env`/`node_modules`/`storage/logs`/`.git` are genuinely excluded), real two-phase upload against a real fake-Hub process, Hub-unreachable failure path
- Pre-existing suites re-verified unaffected: Server Agent `run.php` (34/34), `run_recipe.php` (15/15), Local Agent `run.php` (97/97)

## 6. Manual steps required to activate this for `customer-site`

Nothing here was executed against production — these are the operator's own steps:

1. **Local Agent**: upgrade the developer machine's Local Agent to v1.3.0 (copy the updated `local-agent/` — or run `upgrade.ps1` from `deployment-local-agent/` once that package is rebuilt/redistributed).
2. **Local Project Mapping**: confirm the developer's Local Agent already has a `LocalProjectMapping` for `customer-site` (required — the upload endpoint rejects an unmapped project).
3. **Edit `customer-site`'s pipeline in the dashboard**: add `"skip_when_artifact": true` to the existing git-fetch/reset step's JSON (new projects created after this ships get it automatically via the updated presets; `customer-site` already exists, so this one field needs a manual edit).
4. **(Optional) `artifact_preserve_paths`**: if `customer-site` needs anything beyond the built-in default (`.env`, `storage`, `public/storage`) preserved across a code swap (e.g. a custom uploads directory), set it on the Project.
5. **Server Agent**: deploy the updated `agent/` directory (adds `ArtifactManager.php`) to the production server and restart the agent service — no config.php changes are required unless overriding `artifact_preserve_paths`/`artifact_retention_count` per-project.
6. First real publish after this: watch the PublishJob's own output in the dashboard — it now includes an `[artifact] uploaded: ...` (or `FAILED: ...`) line — and confirm the next Deployment's log shows a `deploy artifact` step instead of `git pull`.

## 7. Remaining risks

- **Large-body memory buffering**: both the existing signed-request middleware (`$request->getContent()`) and the Server Agent's checksum step buffer the full request/file into memory. Fine for a typical Laravel app's source (a few MB); would need streaming verification if a project's artifact ever approaches the configured max (`DEPLOYMENT_ARTIFACT_MAX_SIZE_MB`, default 500MB).
- **No cross-server rollback command yet**: `ArtifactManager` keeps `.backup-*` directories on disk (retention-limited) but there is no dashboard/CLI action to reactivate one — restoring one today means an operator manually renaming a backup directory back into place. Worth a follow-up if artifact-based deploys become the default path.
- **`export-ignore` reliance**: a project needing to exclude a *tracked* file from the artifact must add it to `.gitattributes` — not discoverable from the dashboard; worth a line in customer-site's own project documentation.
- **First-ever artifact deploy has no `.env`/`storage` to preserve**: on a brand-new `working_directory`, preserve-copy is a no-op (nothing exists yet) — the very first artifact-based deploy for `customer-site` must still be preceded by whatever one-time `.env`/`storage` provisioning already exists for it today (unchanged from the current manual bootstrap).

## 8. Explicit confirmation

No commit, no push, and no production deployment were performed as part of this work. No SSH connection was made to any server. All 816 tests referenced above ran against the local development database/filesystem only.
