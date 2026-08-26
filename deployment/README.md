# Provider-neutral release preparation

The contract is Git plus `.deployment/release-policy.json`. Before starting work, a human, script, or code agent creates `.deployment/release-task.json` from the example and records the current baseline SHA and the task's exact owned paths. The active task file is control state and is never committed.

Run `php deployment/release-prepare.php check` for a side-effect-free readiness check. Run `php deployment/release-prepare.php prepare` only after the task is complete. Preparation validates ownership, branch and Git state, dependencies, migrations, PHP syntax, and configured checks; stages only exact candidate paths; creates a deterministic release commit; pushes it; and stores `.git/deployment/releases/<sha>.json`.

To send metadata to Deployment System, set `DEPLOYMENT_RELEASE_METADATA_URL` to `/api/projects/{project-key}/releases` and `DEPLOYMENT_RELEASE_SECRET` to that project's webhook/release secret. Delivery is HMAC-SHA256 authenticated. Provider identity is never part of the contract or protocol.

Fail-closed results include `MIXED WORKTREE — RELEASE NOT SAFE`, `TEST FAILURE`, `MIGRATION REVIEW REQUIRED`, dependency/branch failures, dirty tracked runtime data, pre-staged changes, and metadata delivery mismatch/failure. The implementation never uses `git add .` or `git add -A`.
