# Composer path dependencies pinned to Git commits

## Audited flow before this change

`LocalAgentJobController::next()` issued an unversioned build payload containing the consumer project, branch, and exact consumer SHA. `WorkspaceSourcePreparer` fetched and checked out that consumer SHA in a per-job `BuildWorkspace`, but parsed Composer `path` repositories and copied them from the live sibling workspace. `ArtifactBuilder` produced a deterministic ZIP and release-manifest schema v1. The Hub checked ZIP structure/checksum, and the Server Agent's `ReleaseValidator` checked commit/runtime completeness before activation. Dependency Git provenance was not stored anywhere.

## Contract v2 and manifest v2

The Hub-owned `artifact_dependency_policies` is the allowlist and pin source. A build payload has `build_contract_version: 2` and a `dependency_contract` containing `version`, `required`, and dependency entries. Each entry carries `project_id`, `repository_mapping`, `branch`, full `commit_sha`, `package_name`, `source_path`, and Composer `destination_path`. It never accepts a caller-supplied repository URL.

Project sync independently supplies the path/package allowlist (without a commit) and repository mappings. The Local Agent requires an exact tuple match before resolving the mapping's configured URL. It fetches each branch into a random directory under the unique job workspace, proves the SHA is an ancestor of the fetched branch, rejects unsafe paths/symlinks/submodules, and extracts `git archive <sha>:<source_path>`.

Release-manifest v2 adds `dependency_lock_required` and `dependency_locks`. Each lock repeats the request identity and records `tree_hash`, deterministic `checksum_sha256`, and final `artifact_path`. The builder checks Composer's materialized package against the lock before ZIP creation, extracts the sealed ZIP, rejects links/reparse points, and checks the bytes again.

## Compatibility and rollout

Manifest v1 and build payloads without a dependency contract retain legacy behavior for projects whose policy is not enabled. `customer-site` is allowlisted, but its environment-controlled policy defaults off. When enabled it fails closed on a missing v2 lock. Other projects remain compatible until explicitly opted in. Hub dispatch gates pinned builds on Local Agent 1.5.0+ and activation on Server Agent 1.4.0+. A sealed `.deployment/dependency-lock-v2` required-path marker keeps v2 artifacts distinguishable after policy rollback.

Rollout order: dry-run and back up active Server `1.3.4-p1`, activate Server 1.4.0 and confirm heartbeat; dry-run and back up active Local `1.4.7`, activate Local 1.5.0 and confirm heartbeat/project sync; only then enable policy and request a new artifact. Rollback first disables policy and cancels v2 jobs with a run-scoped attestation, then restores Local `1.4.7` and Server `1.3.4-p1` from verified on-host backups. Other projects' v1 artifacts remain usable; v2 artifacts cannot be activated by a downgraded Server Agent.
