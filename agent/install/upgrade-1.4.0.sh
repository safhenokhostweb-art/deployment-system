#!/usr/bin/env bash
# Minimal Server Agent 1.4.0 overlay and exact-backup rollback.
set -Eeuo pipefail

ACTION="${1:-dry-run}"
RUN_ID="${SERVER_AGENT_RUN_ID:-}"
INSTALL_DIR="${SERVER_AGENT_INSTALL_DIR:-/opt/deployment-server-agent}"
SOURCE_DIR="${SERVER_AGENT_SOURCE_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)}"
STATE_ROOT="${SERVER_AGENT_STATE_ROOT:-/opt/deployment-server-agent-upgrades}"
HELPER="${SERVER_AGENT_SERVICE_HELPER:-/usr/local/sbin/deployment-service-reload}"
SYSTEMCTL_BIN="${SERVER_AGENT_SYSTEMCTL_BIN:-systemctl}"
PHP_BIN="${SERVER_AGENT_UPGRADER_PHP_BIN:-php}"
TEST_MODE="${SERVER_AGENT_TEST_MODE:-0}"
ROLLBACK_ATTESTATION="${SERVER_AGENT_ROLLBACK_ATTESTATION:-}"
ROLLBACK_ATTESTATION_SHA256="${SERVER_AGENT_ROLLBACK_ATTESTATION_SHA256:-}"
SERVICE="deployment-server-agent.service"

case "$ACTION" in activate|dry-run|rollback) ;; *) echo 'Usage: upgrade-1.4.0.sh activate|dry-run|rollback' >&2; exit 64;; esac
case "$RUN_ID" in ''|*[!A-Za-z0-9._-]*) echo 'SERVER_AGENT_RUN_ID is required and contains only A-Z, a-z, 0-9, dot, underscore or dash.' >&2; exit 64;; esac
[[ "$INSTALL_DIR" == /* && "$INSTALL_DIR" != / ]] || { echo 'Unsafe install directory.' >&2; exit 64; }
[[ "$STATE_ROOT" == /* && "$STATE_ROOT" != / ]] || { echo 'Unsafe state directory.' >&2; exit 64; }
[[ "$TEST_MODE" == 1 || "$(id -u)" == 0 ]] || { echo 'Root is required.' >&2; exit 1; }

RUN_ROOT="$STATE_ROOT/$RUN_ID"
BACKUP="$RUN_ROOT/backup"
STAGE="$RUN_ROOT/staging"
MARKER="$RUN_ROOT/$ACTION.done"
P1_AGENT=69a5cc23eb0ac8d88060d803f42223f0026ad3bfdc86149e6ef4af24c459b985
P1_REGISTRY=6397de566e08429654144994d4b69f6229d75d2d571cdd11d8c0292f6efd53a8
P1_HELPER=23560656f95e29c9de6b7eda070774f7d356da44cbfbdd52502291bfd81b1969

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
    failed="$RUN_ROOT/failed-1.4.0"
    [[ ! -e "$failed" ]] || { echo 'Failed-runtime preservation path already exists.' >&2; exit 5; }
    mv "$INSTALL_DIR" "$failed"
    mv "$restore" "$INSTALL_DIR"
    install -m 0755 "$BACKUP/deployment-service-reload" "$HELPER"
    assert_hash "$INSTALL_DIR/agent.php" "$P1_AGENT"
    assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$P1_REGISTRY"
    assert_hash "$HELPER" "$P1_HELPER"
    restart_once
    : > "$MARKER"
    echo "ROLLBACK_OK run_id=$RUN_ID restored_version=1.3.4-p1 backup=$BACKUP"
    exit 0
fi

[[ "$(version "$INSTALL_DIR/agent.php")" == 1.3.4-p1 ]] || { echo 'Active Server Agent must be 1.3.4-p1.' >&2; exit 6; }
[[ "$(version "$SOURCE_DIR/agent.php")" == 1.4.0 ]] || { echo 'Candidate Server Agent must be 1.4.0.' >&2; exit 6; }
assert_hash "$INSTALL_DIR/agent.php" "$P1_AGENT"
assert_hash "$INSTALL_DIR/lib/OperationRegistry.php" "$P1_REGISTRY"
assert_hash "$HELPER" "$P1_HELPER"
for file in agent.php lib/ArtifactManager.php lib/ReleaseValidator.php; do "$PHP_BIN" -l "$SOURCE_DIR/$file" >/dev/null; done

mkdir -p "$RUN_ROOT"
[[ ! -e "$BACKUP" ]] || { echo 'This run_id already owns a backup.' >&2; exit 7; }
mkdir -p "$BACKUP"
cp -a "$INSTALL_DIR" "$BACKUP/runtime"
install -m 0755 "$HELPER" "$BACKUP/deployment-service-reload"
snapshot "$BACKUP/runtime" "$BACKUP/SHA256SUMS"
printf 'run_id=%s\nsource_version=1.3.4-p1\ntarget_version=1.4.0\nhelper_sha256=%s\n' "$RUN_ID" "$P1_HELPER" > "$BACKUP/metadata"

cp -a "$BACKUP/runtime" "$STAGE"
install -m 0755 "$SOURCE_DIR/agent.php" "$STAGE/agent.php"
install -m 0644 "$SOURCE_DIR/lib/ArtifactManager.php" "$STAGE/lib/ArtifactManager.php"
install -m 0644 "$SOURCE_DIR/lib/ReleaseValidator.php" "$STAGE/lib/ReleaseValidator.php"
assert_hash "$STAGE/lib/OperationRegistry.php" "$P1_REGISTRY"
changed="$(diff -qr "$BACKUP/runtime" "$STAGE" || true)"
[[ "$(printf '%s\n' "$changed" | sed '/^$/d' | wc -l)" == 3 ]] || { echo 'Candidate is not a three-file overlay.' >&2; exit 8; }
printf '%s\n' "$changed" | grep -q '/agent.php'
printf '%s\n' "$changed" | grep -q '/lib/ArtifactManager.php'
printf '%s\n' "$changed" | grep -q '/lib/ReleaseValidator.php'
if printf '%s\n' "$changed" | grep -Evq '(agent\.php|lib/ArtifactManager\.php|lib/ReleaseValidator\.php)'; then echo 'UNRELATED_RUNTIME_DIFF' >&2; exit 8; fi
cmp -s "$BACKUP/runtime/lib/OperationRegistry.php" "$STAGE/lib/OperationRegistry.php"
[[ ! -e "$STAGE/lib/PipelineExecutor.php" ]] || cmp -s "$BACKUP/runtime/lib/PipelineExecutor.php" "$STAGE/lib/PipelineExecutor.php"
snapshot "$STAGE" "$RUN_ROOT/CANDIDATE-SHA256SUMS"

if [[ "$ACTION" == dry-run ]]; then
    rm -rf -- "$STAGE"
    : > "$MARKER"
    echo "DRY_RUN_OK run_id=$RUN_ID baseline=1.3.4-p1 target=1.4.0 overlay_files=3 backup=$BACKUP"
    exit 0
fi

[[ ! -f "$MARKER" ]] || { echo "ACTIVATION_ALREADY_DONE run_id=$RUN_ID"; exit 0; }
old="$RUN_ROOT/pre-activation"
mv "$INSTALL_DIR" "$old"
mv "$STAGE" "$INSTALL_DIR"
restart_once
[[ "$(version "$INSTALL_DIR/agent.php")" == 1.4.0 ]]
assert_hash "$HELPER" "$P1_HELPER"
: > "$MARKER"
echo "ACTIVATION_OK run_id=$RUN_ID old_version=1.3.4-p1 new_version=1.4.0 backup=$BACKUP"
