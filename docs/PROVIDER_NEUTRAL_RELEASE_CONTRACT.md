# Provider-Neutral Project Release Contract

## 1. Final release-contract architecture

Each repository owns a versioned `.deployment/release-policy.json`; each active task owns an uncommitted `.deployment/release-task.json`. The shared `deployment/release-prepare.php` executable is the reference implementation. Git is the source boundary, task metadata is the ownership boundary, project policy is the validation boundary, HMAC-authenticated JSON is the handoff boundary, and Deployment System's `Release` plus exact `Artifact` records are the deployment boundary.

Push webhooks prepare releases and may queue the configured authoritative Agent to build the exact commit. They never create a production Deployment. A deployment is created only by the operator confirmation POST.

## 2. Provider-neutral guarantees

The files, JSON schema, Git commands, test commands, HTTP/HMAC protocol, and Laravel hub domain contain no AI-provider identity or API. A human, CI job, shell script, Codex, Claude, or a future tool produces the same inputs and receives the same verdicts.

## 3. Project-side mechanism

`check` is side-effect-free. `prepare` applies the same gates, stages candidate paths individually with `git add -- <path>`, commits with `release(<project-key>): <task-id>` plus deterministic trailers, pushes `HEAD:<production-branch>`, writes commit-keyed metadata under `.git/deployment/releases`, and optionally posts it to the hub using `DEPLOYMENT_RELEASE_METADATA_URL` and `DEPLOYMENT_RELEASE_SECRET`.

## 4. File ownership/detection algorithm

1. Validate the repository branch and merge state.
2. Validate that task `base_sha` is an ancestor of HEAD.
3. Union committed paths in `base_sha..HEAD` with tracked/untracked working-tree paths.
4. Exclude only known untracked runtime paths and the known active-task control file. A tracked runtime/generated change blocks.
5. Require every remaining path to match both project allow-list and the single task's ownership list.
6. Enforce lockfile/package boundary rules and clean, pushed external Git dependency commits.
7. Sort and de-duplicate to produce the exact candidate.

## 5. Mixed-worktree behavior

Any unowned or disallowed candidate returns `MIXED WORKTREE — RELEASE NOT SAFE` with exact unowned/disallowed paths. Unknown untracked source files, pre-staged files, merge conflicts, and ownership ambiguity block. The tool does not guess, reset, stash, or broaden staging.

## 6. Test/migration gates

All candidate PHP files receive syntax checks. Policy commands classify test/build/package checks and must exit zero. Migration files are scanned using project-defined review patterns; destructive or opaque operations return `MIGRATION REVIEW REQUIRED`. Project-specific policies may add stronger migration analyzers as commands. Exact dependency lockfile pairs and external repository clean/pushed state are enforced before commit.

## 7. Release metadata format

Versioned JSON contains project key, commit SHA, base SHA, branch, task/summary, sorted changed files, migration files, dependency commits, test/syntax results, build result, health contract, risk flags, creation timestamp, and `release_ready`. The hub rejects project/branch/SHA/type/path mismatch and rejects a different base SHA for an already-recorded release.

## 8. Deployment System integration

`POST /api/projects/{project-key}/releases` accepts the project-generated JSON with `X-Deployment-Signature-256`. The push webhook creates the commit-keyed Release and queues an artifact build only through the configured authoritative build Agent. Artifact upload synchronizes that exact Release. No available Artifact for another SHA can satisfy readiness.

## 9. UI workflow

The project action is **Review & Deploy Latest**. Its readiness view presents project, branch/commit, summary, changed count/list, tests, migration verdict, risk flags, exact Artifact, and health contract. Derived states are `NOT READY`, `MIXED WORKTREE`, `TEST FAILURE`, `MIGRATION REVIEW REQUIRED`, `ARTIFACT BUILDING`, and `READY TO DEPLOY`. Only the last state renders **Confirm deployment**. Neither SHA nor Artifact ID is entered by the operator.

## 10. Changes required in each of the three projects

- `deployment-system`: the shared executable, baseline policy, hub receiver, orchestration, UI, and tests are included here. Before its first real release, create the active task file and configure the metadata URL/secret in the release environment. Expand required commands to the desired full service/agent suite once current unrelated work is separated.
- `saas-platform`: audit found Laravel 10, Composer path repositories under `packages/saas-platform`, Vite, and an already mixed untracked Theme Release task on branch `audit/artifact-deployment-readiness`. Copy the shared `deployment/` directory (or a versioned shared-tool subtree), add a project policy with `main`/actual production branch, Composer/npm lock pairs, PHPUnit/package tests, Vite build, Laravel migration patterns, runtime excludes, and package-boundary ownership. Do not prepare until the current task is assigned one coherent ownership manifest or separated.
- `customer-site`: audit found Laravel 12, Vite, and Composer path dependencies into sibling `saas-platform`, plus a large mixed tracked/untracked worktree on branch `audit/artifact-deployment-readiness`. Its policy must add `git_dependencies` for every consumed SaaS package repository/branch so dirty or unpushed dependency commits block, plus `composer test:ci`, Vite build, migration rules, and persistent upload/storage exclusions. Do not prepare or deploy the present mixed worktree.

The shared implementation should be copied or synchronized from one reviewed source; only JSON policy differs per repository. No application deployment was performed.

## 11. Files changed

See the implementation commit/worktree diff. Changes are limited to the shared project contract/tool/tests/docs and Deployment System release ingestion, orchestration, readiness, routes, controller, and views. Pre-existing agent, recipe, and fixture modifications were not edited.

## 12. Tests

The standalone suite covers coherent ownership, unrelated work, runtime exclusions, unknown source files, safe/unsafe migrations, failing gates before commit, exact staging/push/metadata, and provider irrelevance. Laravel feature coverage validates authenticated metadata, mismatch blocking, exact Artifact readiness, no SHA/Artifact form input, explicit confirmation, webhook release creation, and no deployment on branch advance.

## 13. Rollout plan

1. Review and commit this shared contract separately from pre-existing dirty work.
2. Configure each hub Project's production branch, authoritative build Agent, exact-artifact requirement, and HTTP health contract.
3. Install the same tool and a tailored policy in `saas-platform`; split its current work before creating an active task.
4. Install it in `customer-site`; configure sibling Git dependencies; split its current mixed work before creating an active task.
5. Configure HMAC metadata URL/secret in trusted release environments and exercise `check` against a harmless task.
6. Prepare and push one non-production pilot release; verify metadata, auto-build, exact Artifact, readiness UI, and mandatory confirmation without confirming production.
7. Enable the operator workflow project-by-project. Keep auto-deploy disabled.
