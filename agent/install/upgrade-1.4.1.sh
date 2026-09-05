#!/usr/bin/env bash
# Server Agent 1.4.0 -> 1.4.1 overlay and exact-backup rollback.
#
# This is the ONE exceptional bootstrap release for the Server Agent
# self-upgrade contract (see agent/release/1.4.1-lineage.json): 1.4.0 has
# no way to understand server_agent_control_jobs / ServerAgentPackage, so
# it must be installed once, by hand, the same way 1.4.0 itself was
# (upgrade-1.4.0.sh). Every release AFTER this one is expected to ship
# through the exact-commit self-upgrade control contract this release
# installs (lib/SelfUpgradeManager.php), not another hand-run script.
#
# Overlay: agent.php, lib/PipelineExecutor.php (the Deployment #183/#184
# fix, commit bcc7670f), lib/SelfUpgradeManager.php (new). Also installs
# the new, narrowly-scoped deployment-server-agent-restart privileged
# helper (+ sudoers) this release depends on — restart-only, zero
# arguments, never a general-purpose command (see
# deployment-server-agent-restart.sudoers).
#
# NOT executed as part of implementing this task — see the task's own
# instruction. Run manually by an operator, once, after this code has
# been reviewed/pushed and an exact trusted package has been prepared.
set -Eeuo pipefail

ACTION="${1:-dry-run}"
RUN_ID="${SERVER_AGENT_RUN_ID:-}"
INSTALL_DIR="${SERVER_AGENT_INSTALL_DIR:-/opt/deployment-server-agent}"
SOURCE_DIR="${SERVER_AGENT_SOURCE_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)}"
STATE_ROOT="${SERVER_AGENT_STATE_ROOT:-/opt/deployment-server-agent-upgrades}"
HELPER="${SERVER_AGENT_SERVICE_HELPER:-/usr/local/sbin/deployment-service-reload}"
RESTART_HELPER="${SERVER_AGENT_RESTART_HELPER:-/usr/local/sbin/deployment-server-agent-restart}"
RESTART_SUDOERS="${SERVER_AGENT_RESTART_SUDOERS:-/etc/sudoers.d/deployment-server-agent-restart}"
SYSTEMCTL_BIN="${SERVER_AGENT_SYSTEMCTL_BIN:-systemctl}"
PHP_BIN="${SERVER_AGENT_UPGRADER_PHP_BIN:-php}"
TEST_MODE="${SERVER_AGENT_TEST_MODE:-0}"
ROLLBACK_ATTESTATION="${SERVER_AGENT_ROLLBACK_ATTESTATION:-}"
ROLLBACK_ATTESTATION_SHA256="${SERVER_AGENT_ROLLBACK_ATTESTATION_SHA256:-}"
SERVICE="deployment-server-agent.service"

case "$ACTION" in activate|dry-run|rollback) ;; *) echo 'Usage: upgrade-1.4.1.sh activate|dry-run|rollback' >&2; exit 64;; esac
case "$RUN_ID" in ''|*[!A-Za-z0-9._-]*) echo 'SERVER_AGENT_RUN_ID is required and contains only A-Z, a-z, 0-9, dot, underscore or dash.' >&2; exit 64;; esac
[[ "$INSTALL_DIR" == /* && "$INSTALL_DIR" != / ]] || { echo 'Unsafe install directory.' >&2; exit 64; }
[[ "$STATE_ROOT" == /* && "$STATE_ROOT" != / ]] || { echo 'Unsafe state directory.' >&2; exit 64; }
[[ "$TEST_MODE" == 1 || "$(id -u)" == 0 ]] || { echo 'Root is required.' >&2; exit 1; }

RUN_ROOT="$STATE_ROOT/$RUN_ID"
BACKUP="$RUN_ROOT/backup"
STAGE="$RUN_ROOT/staging"
MARKER="$RUN_ROOT/$ACTION.done"
# Baseline (active 1.4.0 runtime) hashes — computed from this
# repository's own committed source at the exact commit that shipped
# 1.4.0 (bcc7670f's parent tree); PipelineExecutor.php's baseline is the
# 1.3.4-vintage file the 1.4.0 overlay deliberately preserved (see
# agent/release/1.4.0-lineage.json), matching what Production actually
# runs today.
B_AGENT=65cec6541cdd4144a2493e79c002d0244fdfe81df27e8e524a6cc1b223ecdbfa
B_REGISTRY=6397de566e08429654144994d4b69f6229d75d2d571cdd11d8c0292f6efd53a8
B_HELPER=23560656f95e29c9de6b7eda070774f7d356da44cbfbdd52502291bfd81b1969
B_PIPELINE=9f8f693b120cd29ba14bef40b5151fa41303cb981d2f4df80d697b31741dcf2a
# Candidate (1.4.1) hashes. C_AGENT is the canonical LF git blob hash
# (git cat-file -p 757e1b5:agent/agent.php | sha256sum) — a prior value
# here was computed against a CRLF-converted copy of the same content
# and could never match a real Linux checkout or ServerAgentPackage
# (both use core.autocrlf=false/core.eol=lf), so this check would
# always fail. See ServerAgentBootstrapIntegrityTest for the regression
# guard against this recurring.
C_AGENT=e8b106424a0ec4443da5b63cb8f2a34acc042ba2e8975654fac13d4b3c120b68
C_PIPELINE=73985fc464f51145dbd329e1f7c8b56486fbd67a0fb0e0eaf1376582603361af

sha() { sha256sum "$1" | awk '{print $1}'; }
version() { sed -n "s/^const AGENT_VERSION = '\([^']*\)';/\1/p" "$1" | head -n1; }
assert_hash() { local actual; actual="$(sha "$1")"; [[ "$actual" == "$2" ]] || { echo "HASH_MISMATCH file=$(basename "$1") expected=$2 actual=$actual" >&2; exit 2; }; }
snapshot() {
    local root="$1" output="$2"
    (cd "$root" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum) > "$output"
}
restart_once() {
    local restart_marker="$RUN_ROOT/$ACTION.restart.done"
    [[ -f "$restart_marker" ]] && return 0
    "$SYSTEMCTL_BIN" restart "$SERVICE"
    "$SYSTEMCTL_BIN" is-active --quiet "$SERVICE"
    : > "$restart_marker"
}

[[ -f "$INSTALL_DIR/agent.php" && -f "$INSTALL_DIR/lib/OperationRegistry.php" && -f "$HELPER" ]] || { echo 'Active Server Agent runtime is incomplete.' >&2; exit 3; }

if [[ "$ACTION" == rollback ]]; then
    [[ -f "$ROLLBACK_ATTESTATION" && "$ROLLBACK_ATTESTATION_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'A hashed Hub rollback attestation is required.' >&2; exit 4; }
    [[ "$(sha "$ROLLBACK_ATTESTATION")" == "$ROLLBACK_ATTESTATION_SHA256" ]] || { echo 'Hub rollback attestation checksum mismatch.' >&2; exit 4; }
    grep -Eq '"run_id"[[:space:]]*:[[:space:]]*"'"$RUN_ID"'"' "$ROLLBACK_ATTESTATION" || { echo 'Hub rollback attestation run_id mismatch.' >&2; exit 4; }
    grep -Eq '"policy_required"[[:space:]]*:[[:space:]]*false' "$ROLLBACK_ATTESTATION" || { echo 'Hub rollback attestation does not prove policy-off state.' >&2; exit 4; }
    [[ -d "$BACKUP/runtime" && -f "$BACKUP/SHA256SUMS" && -f "$BACKUP/deployment-service-reload" ]] || { echo 'Exact server backup is missing.' >&2; exit 4; }
    if [[ -f "$MARKER" ]]; then echo "ROLLBACK_ALREADY_DONE run_id=$RUN_ID"; exit 0; fi
    snapshot "$BACKUP/runtime" "$RUN_ROOT/backup.verify"
    cmp -s "$BACKUP/SHA256SUMS" "$RUN_ROOT/backup.verify" || { echo 'Server backup checksum verification failed.' >&2; exit 5; }
    restore="$RUN_ROOT/restore"
    rm -rf -- "$restore"
    cp -a "$BACKUP/runtime" "$restore"
    failed="$RUN_ROOT/failed-1.4.1"
    [[ ! -e "$failed" ]] || { echo 'Failed-runtime preservation path already exists.' >&2; exit 5; }
    mv "$INSTALL_DIR" "$failed"
    mv "$restore" "$INSTALL_DIR"
    install -m 0755 "$BACKUP/deployment-service-reload" "$HELPER"
    assert_hash "$INSTALL_DIR/agent.php" "$B_AGENT"
    assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$B_REGISTRY"
    assert_hash "$HELPER" "$B_HELPER"
    # The new restart helper/sudoers this release installed are
    # deliberately left in place on rollback — a 1.4.0 runtime with an
    # unused restart helper present is harmless, and removing a sudoers
    # drop-in as part of an emergency rollback is its own separate risk.
    restart_once
    : > "$MARKER"
    echo "ROLLBACK_OK run_id=$RUN_ID restored_version=1.4.0 backup=$BACKUP"
    exit 0
fi

[[ "$(version "$INSTALL_DIR/agent.php")" == 1.4.0 ]] || { echo 'Active Server Agent must be 1.4.0.' >&2; exit 6; }
[[ "$(version "$SOURCE_DIR/agent.php")" == 1.4.1 ]] || { echo 'Candidate Server Agent must be 1.4.1.' >&2; exit 6; }
assert_hash "$INSTALL_DIR/agent.php" "$B_AGENT"
assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$B_REGISTRY"
assert_hash "$HELPER" "$B_HELPER"
[[ ! -f "$INSTALL_DIR/lib/PipelineExecutor.php" ]] || assert_hash "$INSTALL_DIR/lib/PipelineExecutor.php" "$B_PIPELINE"
[[ -f "$SOURCE_DIR/lib/PipelineExecutor.php" && -f "$SOURCE_DIR/lib/SelfUpgradeManager.php" ]] || { echo 'Candidate source is incomplete.' >&2; exit 6; }
for file in agent.php lib/PipelineExecutor.php lib/SelfUpgradeManager.php; do "$PHP_BIN" -l "$SOURCE_DIR/$file" >/dev/null; done

mkdir -p "$RUN_ROOT"
[[ ! -e "$BACKUP" ]] || { echo 'This run_id already owns a backup.' >&2; exit 7; }
mkdir -p "$BACKUP"
cp -a "$INSTALL_DIR" "$BACKUP/runtime"
install -m 0755 "$HELPER" "$BACKUP/deployment-service-reload"
snapshot "$BACKUP/runtime" "$BACKUP/SHA256SUMS"
printf 'run_id=%s\nsource_version=1.4.0\ntarget_version=1.4.1\nhelper_sha256=%s\n' "$RUN_ID" "$B_HELPER" > "$BACKUP/metadata"

cp -a "$BACKUP/runtime" "$STAGE"
install -m 0755 "$SOURCE_DIR/agent.php" "$STAGE/agent.php"
install -m 0644 "$SOURCE_DIR/lib/PipelineExecutor.php" "$STAGE/lib/PipelineExecutor.php"
install -m 0644 "$SOURCE_DIR/lib/SelfUpgradeManager.php" "$STAGE/lib/SelfUpgradeManager.php"
assert_hash "$STAGE/lib/OperationRegistry.php" "$B_REGISTRY"
changed="$(diff -qr "$BACKUP/runtime" "$STAGE" || true)"
[[ "$(printf '%s\n' "$changed" | sed '/^$/d' | wc -l)" == 3 ]] || { echo 'Candidate is not the expected three-entry overlay.' >&2; exit 8; }
printf '%s\n' "$changed" | grep -q '/agent.php'
printf '%s\n' "$changed" | grep -q '/lib/PipelineExecutor.php'
printf '%s\n' "$changed" | grep -q 'SelfUpgradeManager.php'
if printf '%s\n' "$changed" | grep -Evq '(agent\.php|lib/PipelineExecutor\.php|SelfUpgradeManager\.php)'; then echo 'UNRELATED_RUNTIME_DIFF' >&2; exit 8; fi
cmp -s "$BACKUP/runtime/lib/OperationRegistry.php" "$STAGE/lib/OperationRegistry.php"
assert_hash "$STAGE/agent.php" "$C_AGENT"
assert_hash "$STAGE/lib/PipelineExecutor.php" "$C_PIPELINE"
snapshot "$STAGE" "$RUN_ROOT/CANDIDATE-SHA256SUMS"

if [[ "$ACTION" == dry-run ]]; then
    rm -rf -- "$STAGE"
    : > "$MARKER"
    echo "DRY_RUN_OK run_id=$RUN_ID baseline=1.4.0 target=1.4.1 overlay_files=3 backup=$BACKUP"
    exit 0
fi

[[ ! -f "$MARKER" ]] || { echo "ACTIVATION_ALREADY_DONE run_id=$RUN_ID"; exit 0; }
old="$RUN_ROOT/pre-activation"
mv "$INSTALL_DIR" "$old"
mv "$STAGE" "$INSTALL_DIR"

# Install the new, narrowly-scoped restart helper this release depends
# on — fixed argv, zero arguments accepted, sudoers-constrained to
# exactly this one invocation (see
# deployment-server-agent-restart.sudoers). Installed AFTER activation
# succeeds so a failure here does not leave the runtime half-upgraded;
# SelfUpgradeManager's own restart-call failure handling already treats
# a missing/misconfigured helper as non-fatal to a completed activation.
install -m 0755 "$SOURCE_DIR/install/deployment-server-agent-restart" "$RESTART_HELPER"
install -m 0440 "$SOURCE_DIR/install/deployment-server-agent-restart.sudoers" "$RESTART_SUDOERS"
visudo -cf "$RESTART_SUDOERS"

restart_once
[[ "$(version "$INSTALL_DIR/agent.php")" == 1.4.1 ]]
assert_hash "$HELPER" "$B_HELPER"
: > "$MARKER"
echo "ACTIVATION_OK run_id=$RUN_ID old_version=1.4.0 new_version=1.4.1 backup=$BACKUP"
