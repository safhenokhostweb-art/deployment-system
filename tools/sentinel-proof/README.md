# Sentinel local runtime proof — Design A

Implementation only. Nothing auto-runs, boots the main Hub, reads its
environment, dispatches jobs, or starts an Agent. Do not invoke a router
directly. Runtime execution requires separate owner authorization.

## Independent state

SentinelProofRunConfig takes the actual source repository, exact commit, branch,
project key, and two dedicated unused ports. Construction performs no writes.
A random run identity places all state under %TEMP%/SentinelProof/<run-id>:

- hub/database.sqlite, storage, cache, sessions, logs
- runtime/database.sqlite, storage, cache, sessions, logs
- runtime/application/releases/<persisted-deployment-public-id>
- artifacts/ for the real final ZIP

No existing identity, database, storage, cached configuration or production
target is reused. The ledger uses existing model classes behind a scoped
connection resolver, disables model listeners, and restores the caller's
resolver/container. It has no job tables or queue/scheduler backend.
The in-memory factory is exclusively for component fixtures.

## Owner-authorized future execution

1. Initialize the filesystem and independent Hub ledger explicitly. Both
   default to refusing execution. Do not copy the current Hub SQLite or config.
2. Build the exact source commit separately in this run's isolated workspace.
   Use SentinelExactCommitExporter::export('build/source.zip', true): it reads
   the exact commit with git ls-tree and git cat-file blob, never git archive
   or the working tree. Git attributes can change archive EOL bytes; raw blobs
   preserve LF/CRLF and executable modes. Environment and credential names,
   including committed examples, are filtered before reading their objects.
   Every finalized ZIP entry is checked for blob size, SHA-256 and mode before
   computing archive_sha256 from the sealed ZIP bytes. blob_inventory digests
   describe individual Git blobs, not the archive checksum. Use locked dependencies.
   Internal Composer path packages must remain the committed blob versions;
   never call the SimpleDeploy live-path-copy helper on them.
   Exported safe relative symlinks are metadata only and are never followed or
   materialized by the exporter. Escaping targets and submodules are rejected;
   the artifact/runtime gate still rejects all symlinks. Do not extract such a
   source ZIP with an unchecked generic unzip helper.
   Git read failures retain a sanitized operation context in the proof report:
   operation ID, Blob OID/path when safe, exit code, timeout state, and an
   evidence-based stderr classification. Raw stderr, full command arguments,
   credentials, and absolute source paths are never included. An unrecognized
   nonzero Git exit remains `GIT_PROCESS_FAILED`; malformed tree output and a
   Blob identity mismatch are reported as distinct failures.
3. Use the existing ArtifactBuilder manifest contract. Include the filtered
   committed source_paths inventory, lock digest, actual build results and
   required paths. Build vendor and public/build inside the workspace.
   Seal the ZIP inside this run's artifacts directory. Compute its real hash.
   The adapter verifies committed source blobs against Git; a JSON commit claim
   alone is insufficient. Extra untracked content is rejected.
4. Construct the filesystem/artifact/Hub/release/runtime adapters and
   orchestrator. Invoke execute only with explicit execution AND isolated
   application schema authorizations.
5. The orchestrator observes the ordered state machine. It registers real
   records, validates/extracts the ZIP, writes the marker after record/checksum
   checks, activates current, initializes the separate application schema,
   starts two loopback-only PHP servers, verifies /health/release, sends one
   signed heartbeat, checks persistence, and invokes Reader/Collector/Verifier.
   It stops only its own process objects.
6. Preserve the result for review. Cleanup is separately authorized and checks
   run ownership and all paths before deleting anything.

This is a separate local proof ledger, not the normal production approval
pipeline. It does not weaken that pipeline or mark a production Release READY.
Existing LocalAgentJobController is unchanged. The independent router uses
the existing signer/contract and an atomic snapshot/watermark update.

## Runtime identity and health

The source health contract is distinct from the ZIP manifest. After actual
local migrations, ReleaseManifestManager creates its runtime manifest with
actual module/schema information. Git provenance comes from the byte-verified
ZIP because the archive has no .git. Its self-checksum is recalculated after
those factual fields are populated.

Every runtime health request verifies artifact bytes, extracted files, marker
and current containment before booting the extracted application. Only
/health/release is accepted on loopback. The probe requires the observed
deployment identity and commit in that genuine response. Bootstrap, migration
and health failures stop the state machine.

The Local collector's opt-in artifact/root parameters hash the real in-run
file. Missing or out-of-run bytes yield NULL. The proof counter is locked and
scoped to the run. Normal collector behavior is unchanged.

## Remaining runtime prerequisites

- Real exact-commit build and sealed ZIP; none has been built here.
- Approval for isolated source migrations and local record creation.
- Dedicated free ports, PHP/ZIP/cURL, build tools and locked packages.
- Windows symlink permissions and rename behavior must be demonstrated.
- The source migration set and /health/release must pass on separate SQLite.
  Unsupported migrations or attempted queue dispatch fail closed. No worker
  or scheduler is started to work around failures.
- OS outbound restrictions may be required for application plugins. Adapters
  prohibit non-loopback proof requests and existing credentials; they do not
  install a firewall.
- Unit tests establish no actual local or production verification.
