#!/usr/bin/env bash
# Server Agent 1.4.1 -> 1.4.2 overlay, staging-area provisioning, and
# exact-backup rollback.
#
# This is the ONE additional exceptional bootstrap release the Control
# Job #1 incident requires (see agent/release/1.4.2-lineage.json): the
# currently-installed 1.4.1 SelfUpgradeManager cannot deliver 1.4.2 to
# itself, because it fails BEFORE it ever reaches staging — the
# unprivileged deployment-agent service user cannot create a new
# directory entry directly under root-owned /opt, which is exactly
# where 1.4.1's staging path lived. This script runs as root (the same
# manual-overlay pattern 1.4.0 and 1.4.1 themselves used), so it never
# hits that problem itself, and it is what provisions the fix that lets
# EVERY SUBSEQUENT release ship through the normal Hub-driven
# exact-commit self-upgrade contract again.
#
# Overlay: agent.php, lib/SelfUpgradeManager.php (both candidate files
# for 1.4.2). Also installs the new, narrowly-scoped
# deployment-server-agent-activate privileged helper (+ sudoers) this
# release depends on — it performs ONLY the backup-aside + staged-in
# atomic swap, never a general-purpose command (see
# deployment-server-agent-activate.sudoers). Also provisions the
# permanent deployment-server-agent-staging directory every FUTURE
# Hub-driven self-upgrade needs — owned by the unprivileged service
# user, sibling of the install directory so the final rename() stays
# on one filesystem, /opt itself never made writable by that user.
#
# NOT executed as part of implementing this task — run manually by an
# operator, once, after this code has been reviewed/pushed and an exact
# trusted package has been prepared.
set -Eeuo pipefail

ACTION="${1:-dry-run}"
RUN_ID="${SERVER_AGENT_RUN_ID:-}"
INSTALL_DIR="${SERVER_AGENT_INSTALL_DIR:-/opt/deployment-server-agent}"
SOURCE_DIR="${SERVER_AGENT_SOURCE_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)}"
STATE_ROOT="${SERVER_AGENT_STATE_ROOT:-/opt/deployment-server-agent-upgrades}"
HELPER="${SERVER_AGENT_SERVICE_HELPER:-/usr/local/sbin/deployment-service-reload}"
ACTIVATE_HELPER="${SERVER_AGENT_ACTIVATE_HELPER:-/usr/local/sbin/deployment-server-agent-activate}"
ACTIVATE_SUDOERS="${SERVER_AGENT_ACTIVATE_SUDOERS:-/etc/sudoers.d/deployment-server-agent-activate}"
SERVICE_OWNER="${SERVER_AGENT_SERVICE_OWNER:-deployment-agent:deployment-agent}"
SYSTEMCTL_BIN="${SERVER_AGENT_SYSTEMCTL_BIN:-systemctl}"
PHP_BIN="${SERVER_AGENT_UPGRADER_PHP_BIN:-php}"
TEST_MODE="${SERVER_AGENT_TEST_MODE:-0}"
ROLLBACK_ATTESTATION="${SERVER_AGENT_ROLLBACK_ATTESTATION:-}"
ROLLBACK_ATTESTATION_SHA256="${SERVER_AGENT_ROLLBACK_ATTESTATION_SHA256:-}"
SERVICE="deployment-server-agent.service"

case "$ACTION" in activate|dry-run|rollback) ;; *) echo 'Usage: upgrade-1.4.2.sh activate|dry-run|rollback' >&2; exit 64;; esac
case "$RUN_ID" in ''|*[!A-Za-z0-9._-]*) echo 'SERVER_AGENT_RUN_ID is required and contains only A-Z, a-z, 0-9, dot, underscore or dash.' >&2; exit 64;; esac
[[ "$INSTALL_DIR" == /* && "$INSTALL_DIR" != / ]] || { echo 'Unsafe install directory.' >&2; exit 64; }
[[ "$STATE_ROOT" == /* && "$STATE_ROOT" != / ]] || { echo 'Unsafe state directory.' >&2; exit 64; }
[[ "$TEST_MODE" == 1 || "$(id -u)" == 0 ]] || { echo 'Root is required.' >&2; exit 1; }

RUN_ROOT="$STATE_ROOT/$RUN_ID"
BACKUP="$RUN_ROOT/backup"
STAGE="$RUN_ROOT/staging"
MARKER="$RUN_ROOT/$ACTION.done"
STAGING_ROOT="$(dirname -- "$INSTALL_DIR")/deployment-server-agent-staging"

# Baseline (active 1.4.1 runtime) hashes — computed from this
# repository's own committed source at the exact commit that shipped
# 1.4.1 (757e1b5). lib/PipelineExecutor.php and lib/OperationRegistry.php
# are deliberately preserved unchanged by this release; their baseline
# and candidate values are identical.
B_AGENT=e8b106424a0ec4443da5b63cb8f2a34acc042ba2e8975654fac13d4b3c120b68
B_REGISTRY=6397de566e08429654144994d4b69f6229d75d2d571cdd11d8c0292f6efd53a8
B_HELPER=23560656f95e29c9de6b7eda070774f7d356da44cbfbdd52502291bfd81b1969
B_PIPELINE=73985fc464f51145dbd329e1f7c8b56486fbd67a0fb0e0eaf1376582603361af
B_SELFUPGRADE=1a4f7ded2008d7191c98b73ebe7e4bde64a046c54f38fddf07b75fe764c56cdf
# Candidate (1.4.2) hashes — the canonical LF git blob hashes of the
# exact-commit source (git show <commit>:agent/<path> | sha256sum),
# never computed against a CRLF-converted working-tree copy (see
# ServerAgentReleaseSyncTest, which fails the moment either of these
# literals drifts from the real committed content).
C_AGENT=6e1c1818a20ff5cfb6ba7d7449da97f1414742fded428c6751d999b98d17c5a3
C_SELFUPGRADE=4b22530cd4a3a56c1b0afdf01d7001989abfb1b2fa216b391f74eb0fe54e3424
C_ACTIVATE_HELPER=0de7ca08bd411f0b28b213dc06df72fe23d872906806aa43fc116408a3ec2dd4
C_ACTIVATE_SUDOERS=8b1ad1265a9c5ada72f9127ad892bffac9ebe79616fd83c60718735fafaa17cf

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
    failed="$RUN_ROOT/failed-1.4.2"
    [[ ! -e "$failed" ]] || { echo 'Failed-runtime preservation path already exists.' >&2; exit 5; }
    mv "$INSTALL_DIR" "$failed"
    mv "$restore" "$INSTALL_DIR"
    install -m 0755 "$BACKUP/deployment-service-reload" "$HELPER"
    assert_hash "$INSTALL_DIR/agent.php" "$B_AGENT"
    assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$B_REGISTRY"
    assert_hash "$HELPER" "$B_HELPER"
    # The new activation helper/sudoers and the staging directory this
    # release installed are deliberately left in place on rollback — a
    # 1.4.1 runtime with an unused activation helper and an empty,
    # correctly-owned staging directory present is harmless, and
    # removing a sudoers drop-in or a directory as part of an emergency
    # rollback is its own separate risk (mirrors upgrade-1.4.1.sh's own
    # precedent for the restart helper it introduced).
    restart_once
    : > "$MARKER"
    echo "ROLLBACK_OK run_id=$RUN_ID restored_version=1.4.1 backup=$BACKUP"
    exit 0
fi

[[ "$(version "$INSTALL_DIR/agent.php")" == 1.4.1 ]] || { echo 'Active Server Agent must be 1.4.1.' >&2; exit 6; }
[[ "$(version "$SOURCE_DIR/agent.php")" == 1.4.2 ]] || { echo 'Candidate Server Agent must be 1.4.2.' >&2; exit 6; }
assert_hash "$INSTALL_DIR/agent.php" "$B_AGENT"
assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$B_REGISTRY"
assert_hash "$HELPER" "$B_HELPER"
[[ ! -f "$INSTALL_DIR/lib/PipelineExecutor.php" ]] || assert_hash "$INSTALL_DIR/lib/PipelineExecutor.php" "$B_PIPELINE"
[[ ! -f "$INSTALL_DIR/lib/SelfUpgradeManager.php" ]] || assert_hash "$INSTALL_DIR/lib/SelfUpgradeManager.php" "$B_SELFUPGRADE"
[[ -f "$SOURCE_DIR/lib/SelfUpgradeManager.php" ]] || { echo 'Candidate source is incomplete: missing lib/SelfUpgradeManager.php.' >&2; exit 6; }
[[ -f "$SOURCE_DIR/install/deployment-server-agent-activate" && -f "$SOURCE_DIR/install/deployment-server-agent-activate.sudoers" ]] || { echo 'Candidate source is incomplete: missing the activation helper or its sudoers fragment.' >&2; exit 6; }
assert_hash "$SOURCE_DIR/install/deployment-server-agent-activate" "$C_ACTIVATE_HELPER"
assert_hash "$SOURCE_DIR/install/deployment-server-agent-activate.sudoers" "$C_ACTIVATE_SUDOERS"
for file in agent.php lib/SelfUpgradeManager.php; do "$PHP_BIN" -l "$SOURCE_DIR/$file" >/dev/null; done
bash -n "$SOURCE_DIR/install/deployment-server-agent-activate"
visudo -cf "$SOURCE_DIR/install/deployment-server-agent-activate.sudoers"

mkdir -p "$RUN_ROOT"
[[ ! -e "$BACKUP" ]] || { echo 'This run_id already owns a backup.' >&2; exit 7; }
mkdir -p "$BACKUP"
cp -a "$INSTALL_DIR" "$BACKUP/runtime"
install -m 0755 "$HELPER" "$BACKUP/deployment-service-reload"
snapshot "$BACKUP/runtime" "$BACKUP/SHA256SUMS"
printf 'run_id=%s\nsource_version=1.4.1\ntarget_version=1.4.2\nhelper_sha256=%s\n' "$RUN_ID" "$B_HELPER" > "$BACKUP/metadata"

cp -a "$BACKUP/runtime" "$STAGE"
install -m 0755 "$SOURCE_DIR/agent.php" "$STAGE/agent.php"
install -m 0644 "$SOURCE_DIR/lib/SelfUpgradeManager.php" "$STAGE/lib/SelfUpgradeManager.php"
assert_hash "$STAGE/lib/OperationRegistry.php" "$B_REGISTRY"
[[ ! -f "$STAGE/lib/PipelineExecutor.php" ]] || assert_hash "$STAGE/lib/PipelineExecutor.php" "$B_PIPELINE"
changed="$(diff -qr "$BACKUP/runtime" "$STAGE" || true)"
[[ "$(printf '%s\n' "$changed" | sed '/^$/d' | wc -l)" == 2 ]] || { echo 'Candidate is not the expected two-entry overlay.' >&2; exit 8; }
printf '%s\n' "$changed" | grep -q '/agent.php'
printf '%s\n' "$changed" | grep -q 'SelfUpgradeManager.php'
if printf '%s\n' "$changed" | grep -Evq '(agent\.php|SelfUpgradeManager\.php)'; then echo 'UNRELATED_RUNTIME_DIFF' >&2; exit 8; fi
cmp -s "$BACKUP/runtime/lib/OperationRegistry.php" "$STAGE/lib/OperationRegistry.php"
assert_hash "$STAGE/agent.php" "$C_AGENT"
assert_hash "$STAGE/lib/SelfUpgradeManager.php" "$C_SELFUPGRADE"
snapshot "$STAGE" "$RUN_ROOT/CANDIDATE-SHA256SUMS"

if [[ "$ACTION" == dry-run ]]; then
    rm -rf -- "$STAGE"
    : > "$MARKER"
    echo "DRY_RUN_OK run_id=$RUN_ID baseline=1.4.1 target=1.4.2 overlay_files=2 backup=$BACKUP"
    exit 0
fi

[[ ! -f "$MARKER" ]] || { echo "ACTIVATION_ALREADY_DONE run_id=$RUN_ID"; exit 0; }
old="$RUN_ROOT/pre-activation"
mv "$INSTALL_DIR" "$old"
mv "$STAGE" "$INSTALL_DIR"

# Install the new, narrowly-scoped activation helper this release
# depends on — fixed argv shape (one staging-basename argument matched
# against a strict hex allowlist inside the helper itself), sudoers-
# constrained to exactly that argument shape (see
# deployment-server-agent-activate.sudoers). Installed AFTER activation
# succeeds so a failure here does not leave the runtime half-upgraded;
# SelfUpgradeManager's own activation-call failure handling treats a
# missing/misconfigured helper as a hard failure for THAT upgrade
# attempt, never as an already-completed one.
install -m 0755 "$SOURCE_DIR/install/deployment-server-agent-activate" "$ACTIVATE_HELPER"
install -m 0440 "$SOURCE_DIR/install/deployment-server-agent-activate.sudoers" "$ACTIVATE_SUDOERS"
visudo -cf "$ACTIVATE_SUDOERS"

# Provision the permanent self-upgrade staging area every FUTURE
# Hub-driven upgrade depends on — a sibling of INSTALL_DIR (same
# filesystem, so the activation helper's own rename() stays atomic),
# owned by the unprivileged service user, mode 0700 (nothing else on
# the box needs to read it). Idempotent: reruns of this script (or a
# later release's own installer) never fail because it already exists.
mkdir -p "$STAGING_ROOT"
chown "$SERVICE_OWNER" "$STAGING_ROOT"
chmod 0700 "$STAGING_ROOT"

restart_once
[[ "$(version "$INSTALL_DIR/agent.php")" == 1.4.2 ]]
assert_hash "$HELPER" "$B_HELPER"
: > "$MARKER"
echo "ACTIVATION_OK run_id=$RUN_ID old_version=1.4.1 new_version=1.4.2 backup=$BACKUP staging_root=$STAGING_ROOT"
