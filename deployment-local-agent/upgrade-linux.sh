#!/usr/bin/env bash
# Official Linux Local Agent upgrader.
#
# Only executable runtime source is replaced. config.php, credentials,
# registration state, storage, logs, and the project cache are never copied,
# moved, chmodded, or rewritten.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
SOURCE_DIR="${SCRIPT_DIR}/agent"
INSTALL_DIR="/opt/deployment-agent"
SERVICE_NAME="deployment-agent.service"
EXPECTED_VERSION=""
AGENT_MANAGED_RESTART=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-dir) SOURCE_DIR="$2"; shift 2 ;;
        --install-dir) INSTALL_DIR="$2"; shift 2 ;;
        --service) SERVICE_NAME="$2"; shift 2 ;;
        --expected-version) EXPECTED_VERSION="$2"; shift 2 ;;
        --agent-managed-restart) AGENT_MANAGED_RESTART=1; shift ;;
        *) echo "Unknown argument: $1" >&2; exit 64 ;;
    esac
done

PHP_BIN="${LOCAL_AGENT_UPGRADER_PHP_BIN:-php}"
SYSTEMCTL_BIN="${LOCAL_AGENT_UPGRADER_SYSTEMCTL_BIN:-systemctl}"
TEST_MODE="${LOCAL_AGENT_UPGRADER_TEST_MODE:-0}"

[[ "$INSTALL_DIR" == /* && "$INSTALL_DIR" != "/" && "$INSTALL_DIR" != "/opt" ]] || { echo "Unsafe install directory: ${INSTALL_DIR}" >&2; exit 64; }
[[ "$SOURCE_DIR" == /* && "$SOURCE_DIR" != "/" ]] || { echo "Unsafe source directory: ${SOURCE_DIR}" >&2; exit 64; }

if [[ "$TEST_MODE" != "1" && "$(id -u)" -ne 0 && "$AGENT_MANAGED_RESTART" != "1" ]]; then
    echo "Run as root, or let the Local Agent invoke --agent-managed-restart." >&2
    exit 1
fi

required=(agent.php heartbeat-watchdog.php)
for relative in "${required[@]}"; do
    [[ -f "${SOURCE_DIR}/${relative}" ]] || { echo "Required source file is missing: ${relative}" >&2; exit 2; }
done
[[ -d "${SOURCE_DIR}/lib" ]] || { echo "Required source directory is missing: lib" >&2; exit 2; }
mapfile -t source_libs < <(find "${SOURCE_DIR}/lib" -maxdepth 1 -type f -name '*.php' -printf '%f\n' | LC_ALL=C sort)
[[ ${#source_libs[@]} -gt 0 ]] || { echo "No PHP library files were found in ${SOURCE_DIR}/lib." >&2; exit 2; }

[[ -f "${INSTALL_DIR}/config.php" ]] || { echo "Existing config.php is required: ${INSTALL_DIR}/config.php" >&2; exit 3; }
for relative in "${required[@]}"; do
    [[ -f "${INSTALL_DIR}/${relative}" ]] || { echo "Existing runtime file is missing: ${relative}" >&2; exit 3; }
done
[[ -d "${INSTALL_DIR}/lib" ]] || { echo "Existing runtime directory is missing: lib" >&2; exit 3; }
while IFS= read -r -d '' entry; do
    [[ -f "$entry" && "$entry" == *.php ]] || {
        echo "Unexpected non-PHP entry in the managed runtime library directory: ${entry}" >&2
        exit 3
    }
done < <(find "${INSTALL_DIR}/lib" -mindepth 1 -maxdepth 1 -print0)

extract_version() {
    local file="$1"
    sed -n "s/.*const LOCAL_AGENT_VERSION = '\([^']*\)'.*/\1/p" "$file" | head -n 1
}

OLD_VERSION="$(extract_version "${INSTALL_DIR}/agent.php")"
NEW_VERSION="$(extract_version "${SOURCE_DIR}/agent.php")"
[[ -n "$OLD_VERSION" ]] || { echo "Could not read the current Local Agent version." >&2; exit 4; }
[[ -n "$NEW_VERSION" ]] || { echo "Could not read the new Local Agent version." >&2; exit 4; }
if [[ -n "$EXPECTED_VERSION" && "$NEW_VERSION" != "$EXPECTED_VERSION" ]]; then
    echo "Upgrade package version ${NEW_VERSION} does not match expected version ${EXPECTED_VERSION}." >&2
    exit 4
fi

CONFIG_HASH_BEFORE="$(sha256sum "${INSTALL_DIR}/config.php" | awk '{print $1}')"
CONFIG_OWNER="$(stat -c '%U' "${INSTALL_DIR}/config.php")"
CONFIG_GROUP="$(stat -c '%G' "${INSTALL_DIR}/config.php")"
CONFIG_MODE="$(stat -c '%a' "${INSTALL_DIR}/config.php")"

if [[ "$TEST_MODE" != "1" ]]; then
    [[ "$CONFIG_OWNER" == "deployment-agent" ]] || { echo "config.php must be owned by deployment-agent (found ${CONFIG_OWNER})." >&2; exit 5; }
    [[ "$CONFIG_GROUP" == "deployment-agent" ]] || { echo "config.php must be grouped under deployment-agent (found ${CONFIG_GROUP})." >&2; exit 5; }
    [[ "$CONFIG_MODE" == "600" || "$CONFIG_MODE" == "640" ]] || { echo "config.php permissions must be 0600 or 0640 (found ${CONFIG_MODE})." >&2; exit 5; }
    for file in "${INSTALL_DIR}/agent.php" "${INSTALL_DIR}/heartbeat-watchdog.php" "${INSTALL_DIR}"/lib/*.php; do
        [[ "$(stat -c '%U' "$file")" == "$CONFIG_OWNER" && "$(stat -c '%G' "$file")" == "$CONFIG_GROUP" ]] \
            || { echo "Runtime ownership does not match config.php: ${file}" >&2; exit 5; }
    done
fi

for file in "${SOURCE_DIR}/agent.php" "${SOURCE_DIR}/heartbeat-watchdog.php" "${SOURCE_DIR}"/lib/*.php; do
    "$PHP_BIN" -l "$file" >/dev/null || { echo "PHP syntax validation failed: ${file}" >&2; exit 6; }
done

STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
STATE_ROOT="${INSTALL_DIR}/storage/upgrades"
BACKUP_ROOT="${LOCAL_AGENT_UPGRADER_BACKUP_ROOT:-${STATE_ROOT}/backups}"
STAGE="${STATE_ROOT}/staging-${STAMP}"
BACKUP="${BACKUP_ROOT}/upgrade-${STAMP}"
mkdir -p "$STAGE/lib" "$BACKUP/lib"
if [[ "$TEST_MODE" != "1" && "$(id -u)" -eq 0 ]]; then
    chown -R "${CONFIG_OWNER}:${CONFIG_GROUP}" "$STATE_ROOT"
fi

cleanup() {
    rm -rf -- "$STAGE"
}
trap cleanup EXIT

install -m 0644 "${SOURCE_DIR}/agent.php" "$STAGE/agent.php"
install -m 0644 "${SOURCE_DIR}/heartbeat-watchdog.php" "$STAGE/heartbeat-watchdog.php"
for name in "${source_libs[@]}"; do
    install -m 0644 "${SOURCE_DIR}/lib/${name}" "$STAGE/lib/${name}"
done

for file in "$STAGE/agent.php" "$STAGE/heartbeat-watchdog.php" "$STAGE"/lib/*.php; do
    "$PHP_BIN" -l "$file" >/dev/null || { echo "Staged PHP syntax validation failed: ${file}" >&2; exit 6; }
done

cp -p -- "${INSTALL_DIR}/agent.php" "$BACKUP/agent.php"
cp -p -- "${INSTALL_DIR}/heartbeat-watchdog.php" "$BACKUP/heartbeat-watchdog.php"
cp -p -- "${INSTALL_DIR}"/lib/*.php "$BACKUP/lib/"
cat >"${BACKUP}/upgrade-metadata.txt" <<EOF
old_version=${OLD_VERSION}
new_version=${NEW_VERSION}
config_sha256=${CONFIG_HASH_BEFORE}
config_owner=${CONFIG_OWNER}
config_group=${CONFIG_GROUP}
config_mode=${CONFIG_MODE}
created_at=${STAMP}
EOF

SERVICE_WAS_ACTIVE=0
if [[ "$AGENT_MANAGED_RESTART" != "1" ]] && "$SYSTEMCTL_BIN" is-active --quiet "$SERVICE_NAME"; then
    SERVICE_WAS_ACTIVE=1
fi

ACTIVATION_STARTED=0
rollback() {
    local exit_code=$?
    local failed_command="${BASH_COMMAND:-unknown}"
    trap - ERR
    set +e
    if [[ "$ACTIVATION_STARTED" == "1" ]]; then
        local restore="${STATE_ROOT}/restore-${STAMP}"
        rm -rf -- "$restore"
        mkdir -p "$restore/lib"
        cp -p -- "$BACKUP/agent.php" "$restore/agent.php"
        cp -p -- "$BACKUP/heartbeat-watchdog.php" "$restore/heartbeat-watchdog.php"
        cp -p -- "$BACKUP"/lib/*.php "$restore/lib/"
        mv -f -- "$restore/agent.php" "${INSTALL_DIR}/agent.php"
        mv -f -- "$restore/heartbeat-watchdog.php" "${INSTALL_DIR}/heartbeat-watchdog.php"
        rm -rf -- "${INSTALL_DIR}/lib.upgrade-failed-${STAMP}"
        if [[ -d "${INSTALL_DIR}/lib" ]]; then
            mv -- "${INSTALL_DIR}/lib" "${INSTALL_DIR}/lib.upgrade-failed-${STAMP}"
        fi
        mv -- "$restore/lib" "${INSTALL_DIR}/lib"
        rm -rf -- "$restore" "${INSTALL_DIR}/lib.upgrade-failed-${STAMP}"
    fi
    if [[ "$AGENT_MANAGED_RESTART" != "1" ]]; then
        if [[ "$SERVICE_WAS_ACTIVE" == "1" ]]; then
            "$SYSTEMCTL_BIN" restart "$SERVICE_NAME" >/dev/null 2>&1 || true
        else
            "$SYSTEMCTL_BIN" stop "$SERVICE_NAME" >/dev/null 2>&1 || true
        fi
    fi
    echo "UPGRADE_FAILED old_version=${OLD_VERSION} target_version=${NEW_VERSION} command=${failed_command} backup=${BACKUP}" >&2
    exit "$exit_code"
}
trap rollback ERR

[[ "$(sha256sum "${INSTALL_DIR}/config.php" | awk '{print $1}')" == "$CONFIG_HASH_BEFORE" ]]

if [[ "$AGENT_MANAGED_RESTART" != "1" && "$SERVICE_WAS_ACTIVE" == "1" ]]; then
    "$SYSTEMCTL_BIN" stop "$SERVICE_NAME"
fi

ACTIVATION_STARTED=1
mv -f -- "$STAGE/agent.php" "${INSTALL_DIR}/agent.php"
mv -f -- "$STAGE/heartbeat-watchdog.php" "${INSTALL_DIR}/heartbeat-watchdog.php"
mv -- "${INSTALL_DIR}/lib" "${INSTALL_DIR}/lib.upgrade-old-${STAMP}"
mv -- "$STAGE/lib" "${INSTALL_DIR}/lib"

if [[ "$TEST_MODE" != "1" ]]; then
    chown deployment-agent:deployment-agent "${INSTALL_DIR}/agent.php" "${INSTALL_DIR}/heartbeat-watchdog.php"
    chown -R deployment-agent:deployment-agent "${INSTALL_DIR}/lib"
fi
chmod 0644 "${INSTALL_DIR}/agent.php" "${INSTALL_DIR}/heartbeat-watchdog.php" "${INSTALL_DIR}"/lib/*.php

if [[ "${LOCAL_AGENT_UPGRADER_FAIL_AFTER_ACTIVATION:-0}" == "1" ]]; then
    false
fi

for file in "${INSTALL_DIR}/agent.php" "${INSTALL_DIR}/heartbeat-watchdog.php" "${INSTALL_DIR}"/lib/*.php; do
    "$PHP_BIN" -l "$file" >/dev/null
done
[[ "$(extract_version "${INSTALL_DIR}/agent.php")" == "$NEW_VERSION" ]]
[[ "$(sha256sum "${INSTALL_DIR}/config.php" | awk '{print $1}')" == "$CONFIG_HASH_BEFORE" ]]
[[ "$(stat -c '%U' "${INSTALL_DIR}/config.php")" == "$CONFIG_OWNER" ]]
[[ "$(stat -c '%G' "${INSTALL_DIR}/config.php")" == "$CONFIG_GROUP" ]]
[[ "$(stat -c '%a' "${INSTALL_DIR}/config.php")" == "$CONFIG_MODE" ]]
if [[ "$TEST_MODE" != "1" ]]; then
    for file in "${INSTALL_DIR}/agent.php" "${INSTALL_DIR}/heartbeat-watchdog.php" "${INSTALL_DIR}"/lib/*.php; do
        [[ "$(stat -c '%U' "$file")" == "$CONFIG_OWNER" && "$(stat -c '%G' "$file")" == "$CONFIG_GROUP" ]]
    done
fi

rm -rf -- "${INSTALL_DIR}/lib.upgrade-old-${STAMP}"
ACTIVATION_STARTED=0

if [[ "$AGENT_MANAGED_RESTART" != "1" ]]; then
    if [[ "$SERVICE_WAS_ACTIVE" == "1" ]]; then
        "$SYSTEMCTL_BIN" restart "$SERVICE_NAME"
        "$SYSTEMCTL_BIN" is-active --quiet "$SERVICE_NAME"
    else
        "$SYSTEMCTL_BIN" stop "$SERVICE_NAME" >/dev/null 2>&1 || true
    fi
fi

trap - ERR
echo "UPGRADE_OK old_version=${OLD_VERSION} new_version=${NEW_VERSION} config_sha256=${CONFIG_HASH_BEFORE} backup=${BACKUP} restart_mode=$([[ "$AGENT_MANAGED_RESTART" == "1" ]] && echo agent || echo systemd)"
