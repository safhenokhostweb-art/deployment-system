# Provider-neutral release preparation

The contract is Git plus `.deployment/release-policy.json`. Before starting work, a human, script, or code agent creates `.deployment/release-task.json` from the example and records the current baseline SHA and the task's exact owned paths. The active task file is control state and is never committed.

Run `php deployment/release-prepare.php check` for a side-effect-free readiness check. Run `php deployment/release-prepare.php prepare` only after the task is complete. Preparation validates ownership, branch and Git state, dependencies, migrations, PHP syntax, and configured checks; stages only exact candidate paths; creates a deterministic release commit; pushes it; and stores `.git/deployment/releases/<sha>.json`.

To send metadata to Deployment System, set `DEPLOYMENT_RELEASE_METADATA_URL` to `/api/projects/{project-key}/releases` and `DEPLOYMENT_RELEASE_SECRET` to that project's webhook/release secret. Delivery is HMAC-SHA256 authenticated. Provider identity is never part of the contract or protocol.

A migration matching `migration_policy.review_required_patterns` is released only when `migration_policy.reviewed` records an owner review for that exact path: `{path, sha256, reviewed_by, reviewed_at, reason}`, where `sha256` is over the file with line endings normalized to LF. Any later edit voids the review. Applied reviews are recorded as `reviewed_migrations` in the release metadata.

Fail-closed results include `MIXED WORKTREE — RELEASE NOT SAFE`, `TEST FAILURE`, `MIGRATION REVIEW REQUIRED`, dependency/branch failures, dirty tracked runtime data, pre-staged changes, and metadata delivery mismatch/failure. The implementation never uses `git add .` or `git add -A`.
