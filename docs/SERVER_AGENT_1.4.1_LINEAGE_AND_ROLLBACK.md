# Server Agent 1.4.1 lineage and rollback contract

## Why this release exists

Deployment #184 proved that a Hub self-deployment of `service/` does not, and cannot, update the separately-installed Server Agent runtime at `/opt/deployment-server-agent` — there is no channel between them. Commit `bcc7670f` fixed a real correctness bug in `agent/lib/PipelineExecutor.php` (the Deployment #183/#184 sequence-collision + `|| true` masking defects), but that fix has sat inert in this repository since it never went through any of the Server Agent's existing manual install/upgrade scripts. `1.4.0`'s own overlay (`agent/install/upgrade-1.4.0.sh`) deliberately preserved `PipelineExecutor.php` unchanged — Production is still running the pre-fix implementation today.

This release does two things at once: it finally ships the `bcc7670f` fix, and it installs the exact-commit self-upgrade control contract (`server_agent_control_jobs` / `ServerAgentPackage`, `lib/SelfUpgradeManager.php`) so that `1.4.1` is the LAST Server Agent release that requires a hand-run overlay script. Every release after this one is expected to ship through the Hub-driven self-upgrade path instead.

## Safe baseline and exact diff

The production baseline is `1.4.0` (commit `0ded41a`'s construction — the `1.3.4-p1` runtime plus the official 1.4.0 overlay; see `agent/release/1.4.0-lineage.json`). Component hashes are in `agent/release/1.4.1-lineage.json` and match `upgrade-1.4.1.sh`'s own constants.

`1.4.1` is a three-entry overlay on a byte-for-byte backup of the active runtime:

| File | Category | Exact change |
|---|---|---|
| `agent.php` | version metadata | `AGENT_VERSION` becomes `1.4.1` |
| `lib/PipelineExecutor.php` | correctness fix | commit `bcc7670f`: prepare/before_activation/activation each get a distinct, always-logged sequence; a trailing `\|\| true`/`\|\| :` on a before_activation command is stripped before execution so a masked failure can never defeat the activation gate; `maintenanceEnabled` no longer depends on string-matching a possibly-masked exit code |
| `lib/SelfUpgradeManager.php` | new | downloads, hash-verifies, stages, and atomically activates a future exact-commit Server Agent package, then restarts through the new `deployment-server-agent-restart` privileged helper |

Every other runtime byte comes from the active `1.4.0` backup — `OperationRegistry.php`, `ArtifactManager.php`, `ReleaseValidator.php`, and the existing `deployment-service-reload` helper are untouched. The generic `upgrade-runtime.sh` is not used for this release either (it depends on a mutable Local Agent build workspace path, which this release's whole point is to move away from — see the architecture notes below).

This release also INSTALLS a new privileged helper, `deployment-server-agent-restart` (+ its own `.sudoers` drop-in): a fixed-argv, zero-argument, root-owned restart of exactly `deployment-server-agent.service`, installed only after activation succeeds. `SelfUpgradeManager` never issues a raw shell command to it or anything else.

## Activation and rollback

`agent/install/upgrade-1.4.1.sh` follows `upgrade-1.4.0.sh`'s exact contract: a safe `run_id`, a verified byte-for-byte backup with `SHA256SUMS` before any mutation, a staged three-entry overlay that rejects any unrelated diff, PHP syntax validation of every candidate file before staging, and exactly one restart of `deployment-server-agent.service` on activation. A failure at any point before the final `mv` leaves the active `1.4.0` runtime completely untouched.

Rollback restores exactly that run's verified `1.4.0` backup and helper, same hashed-Hub-attestation requirement as `1.4.0`'s own rollback. The new restart helper/sudoers this release installs are deliberately left in place on rollback (harmless if unused, and removing a sudoers drop-in mid-emergency is its own separate risk).

## Going forward: the self-upgrade control contract

After this one-time bootstrap, a future Server Agent release is expected to ship as: a Local Agent materializes an exact-commit `agent/` package (`ServerAgentPackageMaterializer`, reusing the same fetch/archive/tree-hash-verify core the Local Agent's own Linux self-upgrade already uses) and uploads it to the Hub; the Hub queues a `ServerAgentControlJob` for the target Server Agent with the exact commit, target version, and the package's own SHA-256; the Server Agent downloads, verifies, stages, and atomically activates it (`SelfUpgradeManager`), then restarts through the fixed helper; the Hub verifies the post-restart heartbeat reports exactly the expected version before marking the upgrade succeeded. No SSH, no manual file copying, no mutable build-workspace dependency.

`config('deployment.min_server_agent_version_for_pre_activation_maintenance')` exists as a capability-check config key (`Agent::enforcesPreActivationMaintenanceOrdering()`) but is deliberately NOT yet wired into any deploy-blocking gate — see that key's own docblock for why activating it before the existing `1.4.0` fleet has completed this one-time bootstrap would recreate exactly the kind of unrecoverable deadlock this mechanism exists to avoid. Wiring it in is a deliberate, separate follow-up once the fleet-wide transition is confirmed complete.

Staging tests use isolated temporary directories and a fake Hub server; no real install, restart, or deployment is performed.
