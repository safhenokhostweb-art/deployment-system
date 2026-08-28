# Server Agent 1.4.0 lineage and rollback contract

## Safe baseline and exact diff

The production baseline is `1.3.4-p1`, not `1.3.6`. It is the `1.3.4` runtime at commit `ba0e793` plus the official p1 overlay. Its component hashes are in `agent/release/1.4.0-lineage.json` and match the p1 installer's constants.

`1.4.0` is a three-file overlay on a byte-for-byte backup of the active runtime:

| File | Category | Exact change |
|---|---|---|
| `agent.php` | version metadata | `AGENT_VERSION` becomes `1.4.0`; the p1 post-success `config_cache` permission repair remains unchanged |
| `lib/ArtifactManager.php` | validation wiring | passes signed `dependency_lock_required` to `ReleaseValidator` |
| `lib/ReleaseValidator.php` | dependency validation | validates manifest v2 locks, full SHAs, ordinary package directories and deterministic content checksums before activation |

Every other runtime byte comes from the active `1.3.4-p1` backup. `OperationRegistry.php` remains at p1 hash `6397de...53a8`, `PipelineExecutor.php` remains the `1.3.4` implementation, and `1.3.5/1.3.6` persistence changes are excluded. The generic `upgrade-runtime.sh` is not used for this release.

The privileged helper remains at p1 hash `235606...1969`; helper, sudoers, `deployment-runtime-permissions`, PHP-FPM and Nginx configuration are not installed or changed. The helper contains no PHP-FPM action. It hashes expanded `nginx -T` output under a lock, records the first baseline without reload, does nothing when unchanged, and reloads Nginx only when that hash changes.

## Activation and rollback

`agent/install/upgrade-1.4.0.sh` requires a safe `run_id`. Before activation it copies the actual active runtime and helper, creates `SHA256SUMS`, stages the three-file overlay, and rejects any fourth diff. It never reloads PHP-FPM or Nginx; activation restarts only `deployment-server-agent.service` once.

Server rollback restores exactly that run's verified `1.3.4-p1` backup and helper. Rebuilding from source is forbidden. Repeating the same rollback returns `ROLLBACK_ALREADY_DONE` without another restart.

Local rollback uses `deployment-local-agent/upgrade-1.5.0.ps1` and restores exactly its run-scoped, hashed `1.4.7` backup. Repeating rollback does not restart the scheduled task again.

Before either downgrade, disable the Hub policy and clear config cache, then run `dependency-pinning:prepare-rollback --run-id=<run_id>`. It refuses in-flight v2 work, cancels queued v2 build/deploy work, and emits a hashed attestation. Each rollback requires that exact attestation file and SHA-256, and verifies its `run_id` and policy-off claim. Artifact v2 remains recognizable through `.deployment/dependency-lock-v2`, so it cannot be dispatched to Server Agent `<1.4.0`; other projects' Artifact v1 remains valid.

## Compatibility matrix

| State | Expected and tested outcome |
|---|---|
| old Hub + Server `1.3.4-p1` + Local `1.4.7` | unchanged Artifact v1 behavior |
| new Hub, policy off, old Agents | dependency contract is empty and v1 activation continues |
| new Hub + Server `1.4.0` + Local `1.4.7`, policy off | v1 build/activation continues; no pinned build is requested |
| new Hub + Server `1.4.0` + Local `1.5.0`, policy off | both generations interoperate without opt-in |
| policy on only for `customer-site` | Local `<1.5.0` build and Server `<1.4.0` activation fail closed; other projects stay v1-compatible |
| policy and both Agents rolled back | queued v2 work is cancelled; v2 marker blocks old Server dispatch; other v1 artifacts remain valid |
| any incompatible order | Hub version gates reject before build dispatch or activation |

Staging tests use isolated temporary directories and fake restart commands; no real install or deployment is performed.
