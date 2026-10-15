# Sentinel local proof diagnostic review

Previous run: `2f606c65-a4c9-48af-8646-de4ae6f12d49`.

The previous run is inspected without booting Laravel or opening a writable
connection. SQLite inspection uses `mode=ro&immutable=1` and `query_only=ON`.
It contains one local agent and zero projects, releases, artifacts, deployments
and heartbeat nonces. The previous report records successful exact source
export, Composer, npm ci, npm build and artifact sealing, followed by failure
in `SENTINEL_LOCAL_ONLY_CHAIN`. No runtime migration was reached in that run.

Entrypoint: `phase-one.php` -> independent filesystem -> exact Git blobs ->
isolated build -> sealed artifact validation -> Hub initialize -> orchestrator
SOURCE_VERIFIED -> ARTIFACT_VERIFIED -> RELEASE_REGISTERED. Registration
validates the artifact first, then saves Project, Release, Artifact, Deployment.
The Hub uses a new PDO SQLite connection, a dedicated Capsule container and
scoped model resolver. It does not bootstrap the main Laravel application or
run its migrations. Schema is created explicitly in the isolated ledger.
Model events are disabled; UUIDs are explicitly assigned; resolver/container
are restored. No model defines a separate connection override. The selected
attributes exist in the ledger schema. Encrypted agent secret casting is not
used: the new ephemeral identity is written only to its isolated database.

The extracted application's Laravel bootstrap/migrations happen only after
registration and release activation. Its environment/config/cache/storage and
SQLite database are independently restricted by `runtime-router.php` before
application providers boot. No queue connection, worker or scheduler is supplied.

The exact original exception is **not provable from preserved evidence**.
`step()` discarded it when throwing `proof_step_failed`; `execute()` discarded
that wrapper when throwing `proof_execution_failed`. No inner stage or previous
exception was recorded. Empty project tables cannot distinguish source/artifact
verification failure from registration validation or a first-model save failure.
Standalone Project saving without Laravel bootstrap passes using an in-memory
component database; this is diagnostic testing, not runtime verification.

Changes retain terminal failure and controlled outer exception codes, preserve
previous exceptions, record exact attempted state/model-save stage and sanitize
exception reports by default denial of arbitrary text. No SQL bindings, raw
traces/arguments, absolute code paths or credentials are emitted. Runtime router
error logs use the same sanitizer. Runtime initialization records its exit code.

A separate reporting defect is proven by the existing contracts: the entrypoint
looked for `evidence.verdict`, but the orchestrator returned the evidence document,
which has no such field. The original evidence field remains unchanged; the
orchestrator additionally returns the actual collection/verification outcome and
the entrypoint checks its verdict. This does not establish the previous failure
cause. A regression test verifies that an advisory FAILED field cannot replace
the actual recomputed VERIFIED verdict.

Validation before the new run:

- Sentinel suite: 76 tests, 75 passed, 829 assertions, one OS symlink skip.
- Final changed isolation/diagnostic tests: 21 tests, 20 passed, 325 assertions,
  one OS symlink skip. Includes the added exception-chain and reporting tests.
- Heartbeat/auth feature tests: 17 passed, 36 assertions, isolated test database.
- Native Windows symlink helper: two actual native links and 13 rejection checks
  pass, zero blocked links. This separately covers the skipped PHP symlink test.
- Changed PHP files pass syntax checks; git diff whitespace check passes.

No source repository changes, prohibited Git mutation, production operation,
main database connection/write, fallback or external build handoff is authorized.
Final verification must use the new run's real observations and persisted data.
All evidence is retained at the first failure; a test fixture is never final proof.

Independent read-only ZIP/Git-tree audit passes for all 7678 committed blobs,
including Git object identities, source ZIP modes, final source bytes, manifest,
required paths, lock digest and the previously recorded final artifact checksum.
This audit is never used by the execution pipeline.

New run `0114172b-dc0d-4c96-b137-6bb9e599f997` stopped at its first failure in
EXACT_COMMIT_EXPORT, before build, Hub initialization or runtime. Its recorded
exception is `RuntimeException: proof_source_blob_unavailable`, originating at
SentinelExactCommitExporter.php:70. The inner native Git exception was discarded
by that existing catch, so its exact cause cannot be established from the new
run either. A code-mode wait timeout/stale-host event was observed, but does not
prove the cause of the Git failure. After stopping, that catch was amended only
to preserve its previous exception. This additional diagnostic correction was
not used in the stopped run. No second fresh run is executed.

REAL_LOCAL_RUNTIME_VERIFIED=NO. No final runtime proof chain was reached.

Preservation verification: all 24438 previous-run files match their recorded
paths, sizes and SHA-256 values. Independent native read-only verification has
zero mismatches, read errors, unexpected paths or reparse points. Before/after
complete inventory digests are recorded in `final-review.json`.
