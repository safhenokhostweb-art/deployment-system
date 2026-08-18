#!/usr/bin/env bash
# Official in-place runtime upgrade. It copies only executable Agent source;
# config.php (identity/credentials) and storage remain untouched.
set -Eeuo pipefail

INSTALL_DIR=/opt/deployment-agent
SOURCE_DIR=/var/www/deployment-system/agent
SERVICE=deployment-agent
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="${INSTALL_DIR}.backup-${STAMP}"
ETC_BACKUP="/root/deployment-agent-runtime-${STAMP}"

[ "$(id -u)" -eq 0 ] || { echo 'Run as root.' >&2; exit 1; }
[ -f "$INSTALL_DIR/config.php" ] || { echo 'Existing Agent config.php is required.' >&2; exit 1; }
[ -f "$SOURCE_DIR/agent.php" ] || { echo 'Repository Agent source is missing.' >&2; exit 1; }
php -l "$SOURCE_DIR/agent.php" >/dev/null
for file in "$SOURCE_DIR"/lib/*.php; do php -l "$file" >/dev/null; done

# Identity compatibility: config.php is preserved untouched by design, and
# agent.php resolves the canonical `agent_id` with legacy `server_id` /
# `local_agent_id` fallbacks, so a legacy-only config keeps working after
# this upgrade. Inform only — never fail an upgrade over a legacy config.
if ! grep -q "'agent_id'" "$INSTALL_DIR/config.php"; then
    echo "NOTICE: $INSTALL_DIR/config.php has no canonical 'agent_id' — using its legacy identity key (server_id/local_agent_id). Rotate to 'agent_id' when possible."
fi

cp -a "$INSTALL_DIR" "$BACKUP"
mkdir -p "$ETC_BACKUP"
for path in /usr/local/sbin/deployment-runtime-permissions /usr/local/sbin/deployment-service-reload /etc/sudoers.d/deployment-runtime-permissions /etc/sudoers.d/deployment-service-reload /etc/systemd/system/deployment-agent.service.d/10-privileged-helpers.conf; do
    [ -e "$path" ] && cp -a --parents "$path" "$ETC_BACKUP"
done
rollback() { cp -a "$BACKUP/." "$INSTALL_DIR/"; systemctl restart "$SERVICE" || true; }
trap rollback ERR

install -d -o deployment-agent -g deployment-agent -m 0750 "$INSTALL_DIR/lib"
install -o deployment-agent -g deployment-agent -m 0644 "$SOURCE_DIR/agent.php" "$INSTALL_DIR/agent.php"
for file in "$SOURCE_DIR"/lib/*.php; do install -o deployment-agent -g deployment-agent -m 0644 "$file" "$INSTALL_DIR/lib/$(basename "$file")"; done
chown deployment-agent:deployment-agent "$INSTALL_DIR/config.php"
chmod 0600 "$INSTALL_DIR/config.php"

# The service remains unprivileged. NoNewPrivileges must be disabled solely
# so sudo can invoke the two exact commands constrained below; sudoers still
# rejects every other elevation attempt.
install -o root -g root -m 0755 "$SOURCE_DIR/install/deployment-runtime-permissions" /usr/local/sbin/deployment-runtime-permissions
install -o root -g root -m 0755 "$SOURCE_DIR/install/deployment-service-reload" /usr/local/sbin/deployment-service-reload
install -o root -g root -m 0440 "$SOURCE_DIR/install/deployment-runtime-permissions.sudoers" /etc/sudoers.d/deployment-runtime-permissions
install -o root -g root -m 0440 "$SOURCE_DIR/install/deployment-service-reload.sudoers" /etc/sudoers.d/deployment-service-reload
visudo -cf /etc/sudoers.d/deployment-runtime-permissions
visudo -cf /etc/sudoers.d/deployment-service-reload
install -d -o root -g root -m 0755 /etc/systemd/system/deployment-agent.service.d
cat > /etc/systemd/system/deployment-agent.service.d/10-privileged-helpers.conf <<'EOF'
[Service]
# sudo is constrained to two literal root-owned helpers in /etc/sudoers.d.
NoNewPrivileges=false
EOF
chmod 0644 /etc/systemd/system/deployment-agent.service.d/10-privileged-helpers.conf
systemctl daemon-reload
systemctl restart "$SERVICE"
systemctl is-active --quiet "$SERVICE"
trap - ERR
echo "UPGRADE_OK backup=$BACKUP"
