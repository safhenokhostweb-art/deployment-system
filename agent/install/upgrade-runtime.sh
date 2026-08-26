#!/usr/bin/env bash
# Official in-place Server Agent runtime upgrade. Source must be the isolated
# deployment-system workspace. The active runtime is replaced atomically while
# config.php, credentials, storage, and the previous runtime remain preserved.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SOURCE_DIR="$(cd "$SCRIPT_DIR/.." && pwd -P)"
INSTALL_DIR=/opt/deployment-server-agent
SERVICE=deployment-server-agent
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
STAGING="${INSTALL_DIR}.staging-${STAMP}"
BACKUP="${INSTALL_DIR}.backup-${STAMP}"
FAILED="${INSTALL_DIR}.failed-${STAMP}"
PRIVILEGED_BACKUP="${INSTALL_DIR}.privileged-backup-${STAMP}"
RUNTIME_HELPER=/usr/local/sbin/deployment-runtime-permissions
RUNTIME_SUDOERS=/etc/sudoers.d/deployment-runtime-permissions
SERVICE_RELOAD_HELPER=/usr/local/sbin/deployment-service-reload
SERVICE_RELOAD_SUDOERS=/etc/sudoers.d/deployment-service-reload
CURRENT_MOVED=false
ACTIVATED=false
PRIVILEGED_ACTIVATED=false

fail() { echo "Server Agent upgrade refused: $1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail 'run as root'
case "$SOURCE_DIR" in
    /opt/deployment-agent/workspaces/deployment-system/agent) ;;
    *) fail "source must be /opt/deployment-agent/workspaces/deployment-system/agent (got $SOURCE_DIR)" ;;
esac
[ -d "$INSTALL_DIR" ] || fail "existing runtime $INSTALL_DIR is required"
[ -f "$INSTALL_DIR/config.php" ] || fail 'existing Server Agent config.php is required'
[ -f "$SOURCE_DIR/agent.php" ] || fail 'Server Agent source is missing'
[ -f "$SCRIPT_DIR/deployment-runtime-permissions" ] || fail 'runtime permission helper source is missing'
[ -f "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" ] || fail 'runtime permission sudoers source is missing'
[ -f "$SCRIPT_DIR/deployment-service-reload" ] || fail 'service reload helper source is missing'
[ -f "$SCRIPT_DIR/deployment-service-reload.sudoers" ] || fail 'service reload sudoers source is missing'
[ ! -e "$STAGING" ] || fail "staging path already exists: $STAGING"
[ ! -e "$BACKUP" ] || fail "backup path already exists: $BACKUP"
[ ! -e "$PRIVILEGED_BACKUP" ] || fail "privileged helper backup path already exists: $PRIVILEGED_BACKUP"

SOURCE_VERSION="$(sed -n "s/^const AGENT_VERSION = '\([^']*\)';/\1/p" "$SOURCE_DIR/agent.php" | head -n 1)"
CURRENT_VERSION="$(sed -n "s/^const AGENT_VERSION = '\([^']*\)';/\1/p" "$INSTALL_DIR/agent.php" | head -n 1)"
[ -n "$SOURCE_VERSION" ] || fail 'source AGENT_VERSION could not be read'
[ -n "$CURRENT_VERSION" ] || fail 'installed AGENT_VERSION could not be read'
php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$SOURCE_VERSION" "$CURRENT_VERSION" \
    || fail "downgrade is forbidden ($CURRENT_VERSION -> $SOURCE_VERSION)"

php -l "$SOURCE_DIR/agent.php" >/dev/null
for file in "$SOURCE_DIR"/lib/*.php; do php -l "$file" >/dev/null; done
bash -n "$SCRIPT_DIR/deployment-runtime-permissions"
visudo -cf "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" >/dev/null
bash -n "$SCRIPT_DIR/deployment-service-reload"
visudo -cf "$SCRIPT_DIR/deployment-service-reload.sudoers" >/dev/null

CONFIG_HASH="$(sha256sum "$INSTALL_DIR/config.php" | awk '{print $1}')"
cp -a "$INSTALL_DIR" "$STAGING"
chown deployment-agent:deployment-agent "$STAGING"
chmod 0750 "$STAGING"
install -d -o deployment-agent -g deployment-agent -m 0750 "$STAGING/lib"
install -o deployment-agent -g deployment-agent -m 0644 "$SOURCE_DIR/agent.php" "$STAGING/agent.php"
for file in "$SOURCE_DIR"/lib/*.php; do
    install -o deployment-agent -g deployment-agent -m 0644 "$file" "$STAGING/lib/$(basename "$file")"
done
chown deployment-agent:deployment-agent "$STAGING/config.php"
chmod 0600 "$STAGING/config.php"

for file in "$STAGING/agent.php" "$STAGING"/lib/*.php "$STAGING/config.php"; do
    php -l "$file" >/dev/null
done
[ "$(sha256sum "$STAGING/config.php" | awk '{print $1}')" = "$CONFIG_HASH" ] \
    || fail 'staged config.php differs from the installed credentials/configuration'

# Keep the exact previous privileged files (or explicit absence markers) so
# the same ERR trap that restores the runtime can restore this trust boundary.
install -d -o root -g root -m 0700 "$PRIVILEGED_BACKUP"
if [ -e "$RUNTIME_HELPER" ]; then cp -a "$RUNTIME_HELPER" "$PRIVILEGED_BACKUP/helper"; else touch "$PRIVILEGED_BACKUP/helper.absent"; fi
if [ -e "$RUNTIME_SUDOERS" ]; then cp -a "$RUNTIME_SUDOERS" "$PRIVILEGED_BACKUP/sudoers"; else touch "$PRIVILEGED_BACKUP/sudoers.absent"; fi
if [ -e "$SERVICE_RELOAD_HELPER" ]; then cp -a "$SERVICE_RELOAD_HELPER" "$PRIVILEGED_BACKUP/service-reload"; else touch "$PRIVILEGED_BACKUP/service-reload.absent"; fi
if [ -e "$SERVICE_RELOAD_SUDOERS" ]; then cp -a "$SERVICE_RELOAD_SUDOERS" "$PRIVILEGED_BACKUP/service-reload-sudoers"; else touch "$PRIVILEGED_BACKUP/service-reload-sudoers.absent"; fi

rollback() {
    code=$?
    trap - ERR
    if [ "$ACTIVATED" = true ]; then
        systemctl stop "$SERVICE" || true
        [ ! -e "$FAILED" ] && mv "$INSTALL_DIR" "$FAILED"
    fi
    if [ "$PRIVILEGED_ACTIVATED" = true ]; then
        if [ -f "$PRIVILEGED_BACKUP/helper" ]; then
            install -o root -g root -m 0755 "$PRIVILEGED_BACKUP/helper" "$RUNTIME_HELPER"
        else
            rm -f "$RUNTIME_HELPER"
        fi
        if [ -f "$PRIVILEGED_BACKUP/sudoers" ]; then
            install -o root -g root -m 0440 "$PRIVILEGED_BACKUP/sudoers" "$RUNTIME_SUDOERS"
        else
            rm -f "$RUNTIME_SUDOERS"
        fi
        if [ -f "$PRIVILEGED_BACKUP/service-reload" ]; then
            install -o root -g root -m 0755 "$PRIVILEGED_BACKUP/service-reload" "$SERVICE_RELOAD_HELPER"
        else
            rm -f "$SERVICE_RELOAD_HELPER"
        fi
        if [ -f "$PRIVILEGED_BACKUP/service-reload-sudoers" ]; then
            install -o root -g root -m 0440 "$PRIVILEGED_BACKUP/service-reload-sudoers" "$SERVICE_RELOAD_SUDOERS"
        else
            rm -f "$SERVICE_RELOAD_SUDOERS"
        fi
    fi
    if [ "$CURRENT_MOVED" = true ] && [ -d "$BACKUP" ]; then
        mv "$BACKUP" "$INSTALL_DIR"
        systemctl restart "$SERVICE" || true
    fi
    echo "Server Agent upgrade failed; previous runtime restored. Failed candidate: $FAILED" >&2
    exit "$code"
}
trap rollback ERR

mv "$INSTALL_DIR" "$BACKUP"
CURRENT_MOVED=true
mv "$STAGING" "$INSTALL_DIR"
ACTIVATED=true
PRIVILEGED_ACTIVATED=true
install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-runtime-permissions" "$RUNTIME_HELPER"
install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" "$RUNTIME_SUDOERS"
visudo -cf "$RUNTIME_SUDOERS" >/dev/null
install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-service-reload" "$SERVICE_RELOAD_HELPER"
install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-service-reload.sudoers" "$SERVICE_RELOAD_SUDOERS"
visudo -cf "$SERVICE_RELOAD_SUDOERS" >/dev/null
systemctl restart "$SERVICE"
sleep 2
systemctl is-active --quiet "$SERVICE"
[ "$(sha256sum "$INSTALL_DIR/config.php" | awk '{print $1}')" = "$CONFIG_HASH" ]
grep -q "^const AGENT_VERSION = '$SOURCE_VERSION';$" "$INSTALL_DIR/agent.php"
cmp -s "$SCRIPT_DIR/deployment-runtime-permissions" "$RUNTIME_HELPER"
cmp -s "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" "$RUNTIME_SUDOERS"
cmp -s "$SCRIPT_DIR/deployment-service-reload" "$SERVICE_RELOAD_HELPER"
cmp -s "$SCRIPT_DIR/deployment-service-reload.sudoers" "$SERVICE_RELOAD_SUDOERS"

trap - ERR
echo "UPGRADE_OK service=$SERVICE version=$CURRENT_VERSION->$SOURCE_VERSION backup=$BACKUP privileged_backup=$PRIVILEGED_BACKUP"
