# Composer path dependencies pinned to Git commits

## Audited flow before this change

`LocalAgentJobController::next()` issued an unversioned build payload containing the consumer project, branch, and exact consumer SHA. `WorkspaceSourcePreparer` fetched and checked out that consumer SHA in a per-job `BuildWorkspace`, but parsed Composer `path` repositories and copied them from the live sibling workspace. `ArtifactBuilder` produced a deterministic ZIP and release-manifest schema v1. The Hub checked ZIP structure/checksum, and the Server Agent's `ReleaseValidator` checked commit/runtime completeness before activation. Dependency Git provenance was not stored anywhere.

## Contract v2 and manifest v2

The Hub-owned `artifact_dependency_policies` config is the **allowlist/template only** — WHICH packages/paths a project may pin, and which upstream `repository_mapping`/`branch` they must come from. It deliberately carries no commit.

**Root-cause fix (Customer Release #74 / Publish Job #256 — "Dependency contract must pin every Composer path repository exactly once").** Before this fix, the config array ALSO carried a single hand-maintained `commit_sha` literal shared by every dependency, with no lifecycle for advancing it and no record of which commit a given Release actually built against. That combination produced two independent, compounding failure modes:

1. **Structural drift** — the allowlist's package/path set had to be kept in sync with customer-site's own `composer.json` by hand, and silently fell one path (`modules-extension-sdk`) behind.
2. **Stale commit lifecycle** — the pinned `commit_sha` was a literal in `service/config/deployment.php`, updated only when someone remembered to, with no connection to any actual saas-platform release state.

**The fix separates authorization from resolution:**

- `App\Support\Deployment\ArtifactDependencyPolicy::template($projectKey)` — the trusted allowlist (project_id, repository_mapping, branch, package_name, source_path, destination_path), duplicate-destination-checked at read time. Answers "what is authorized," never "what is current."
- `App\Services\Publish\SaasDependencyTargetResolver` — resolves the authoritative upstream commit from Hub-owned state: the upstream project's `latestSuccessfulDeployment()`, verified to be backed by a matching `isReady()` Release on the expected branch. Mirrors `ServerAgentUpgradeTargetResolver`'s exact reasoning. Never reads `origin/main` HEAD, a mutable persistent workspace, or any GitHub-specific API; fails closed (409) the moment no eligible target exists.
- `App\Services\Publish\ArtifactDependencySnapshotService` — the ONE place a Release's dependency commit is actually chosen. Resolves it the first time it's needed for a given `Release`, merges it into the template via `ArtifactDependencyPolicy::resolveContract()`, and **freezes** the result onto that Release's own `dependency_snapshot` column (an additive, nullable JSON column). Every later poll or retry of that exact Release replays the frozen value — never re-resolves, even after the upstream project ships a newer ready Release. This is what makes exact-commit evidence and rollback reproducible: the pin is a recorded fact about the Release, not a live query re-evaluated on demand.

A build payload has `build_contract_version: 2` and a `dependency_contract` containing `version`, `required`, and dependency entries. Each entry carries `project_id`, `repository_mapping`, `branch`, full `commit_sha`, `package_name`, `source_path`, and Composer `destination_path`. It never accepts a caller-supplied repository URL. `LocalAgentJobController::next()` resolves this contract from the job's `Release` (frozen, reused on every poll) for `ACTION_BUILD_ARTIFACT`; `ACTION_PUBLISH` jobs have no Release to anchor a snapshot to and resolve live on every call, matching the pre-existing always-live behavior for that action.

**Legacy/backward-compatible behavior:** a `Release` row created before this change (or before pinning was ever required) simply has `dependency_snapshot = null`. Nothing rewrites historical rows in bulk. The first time such a Release's contract is actually needed, it resolves and freezes exactly like a brand-new Release — this is deliberate lazy resolution, not a migration/backfill.

Project sync independently supplies the path/package allowlist (without a commit) and repository mappings via `ArtifactDependencyPolicy::allowlist()`. The Local Agent requires an exact tuple match before resolving the mapping's configured URL. It fetches each branch into a random directory under the unique job workspace, proves the SHA is an ancestor of the fetched branch, rejects unsafe paths/symlinks/submodules, and extracts `git archive <sha>:<source_path>`. On a mismatch, `WorkspaceSourcePreparer` now reports the exact set difference (`missing_in_contract=[...]` / `extra_in_contract=[...]`) instead of a bare boolean failure — the gate itself is unchanged and still fails closed on any mismatch.

Release-manifest v2 adds `dependency_lock_required` and `dependency_locks`. Each lock repeats the request identity and records `tree_hash`, deterministic `checksum_sha256`, and final `artifact_path`. The builder checks Composer's materialized package against the lock before ZIP creation, extracts the sealed ZIP, rejects links/reparse points, and checks the bytes again.

## Fail-closed resolution boundary

`ArtifactDependencySnapshotService`/`SaasDependencyTargetResolver` run at Artifact-build-request time — strictly before, and structurally independent of, the NO CONFIRM -> NO DEPLOYMENT approval gate. Neither class creates, approves, or queues a `Deployment`; they only ever read the upstream project's own recorded Deployment/Release state. If no eligible upstream target exists (no active project, no successful deployment, no matching ready Release, wrong branch), resolution aborts with 409 and freezes nothing — a Release is never left with a partial or invalid snapshot.

## Compatibility and rollout

Manifest v1 and build payloads without a dependency contract retain legacy behavior for projects whose policy is not enabled. `customer-site` is allowlisted, but its environment-controlled policy defaults off. When enabled it fails closed on a missing v2 lock. Other projects remain compatible until explicitly opted in. Hub dispatch gates pinned builds on Local Agent 1.5.0+ and activation on Server Agent 1.4.0+. A sealed `.deployment/dependency-lock-v2` required-path marker keeps v2 artifacts distinguishable after policy rollback.

Rollout order: dry-run and back up active Server `1.3.4-p1`, activate Server 1.4.0 and confirm heartbeat; dry-run and back up active Local `1.4.7`, activate Local 1.5.0 and confirm heartbeat/project sync; only then enable policy and request a new artifact. Rollback first disables policy and cancels v2 jobs with a run-scoped attestation, then restores Local `1.4.7` and Server `1.3.4-p1` from verified on-host backups. Other projects' v1 artifacts remain usable; v2 artifacts cannot be activated by a downgraded Server Agent.
