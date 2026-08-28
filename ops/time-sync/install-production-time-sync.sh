#!/usr/bin/env bash
set -Eeuo pipefail

if [ "$#" -ne 2 ]; then
    echo 'usage: install-production-time-sync.sh CANDIDATE_DIR BACKUP_DIR' >&2
    exit 64
fi

CANDIDATE_DIR="${1%/}"
BACKUP_DIR="${2%/}"
LOCK_FILE=/run/lock/deployment-time-sync-migration.lock
OLD_SCRIPT=/usr/local/bin/timesync-github.sh
DISABLED_SCRIPT=/usr/local/bin/timesync-github.sh.disabled-by-chrony
CHRONY_DEB="$(find "$BACKUP_DIR/packages" -maxdepth 1 -type f -name 'chrony_*.deb' -print -quit)"
TIMESYNCD_DEB="$(find "$BACKUP_DIR/packages" -maxdepth 1 -type f -name 'systemd-timesyncd_*.deb' -print -quit)"
installed=0
changed=0

exec 9>"$LOCK_FILE"
/usr/bin/flock -n 9 || { echo 'another time-sync migration is already running' >&2; exit 75; }

for path in \
    "$CANDIDATE_DIR/chrony.conf" \
    "$CANDIDATE_DIR/chrony-bootstep.conf" \
    "$CANDIDATE_DIR/chrony-bootstep-guard" \
    "$CANDIDATE_DIR/chrony-bootstep-guard.service" \
    "$CANDIDATE_DIR/chrony.service.d-production.conf" \
    "$CANDIDATE_DIR/chrony-health-journal" \
    "$CANDIDATE_DIR/chrony-health-journal.service" \
    "$CANDIDATE_DIR/chrony-health-journal.timer" \
    "$BACKUP_DIR/files/timesyncd.conf" \
    "$BACKUP_DIR/files/timesync-github.sh" \
    "$BACKUP_DIR/files/root.crontab" \
    "$CHRONY_DEB" \
    "$TIMESYNCD_DEB"; do
    [ -f "$path" ] || { echo "required file missing: $path" >&2; exit 66; }
done

for path in \
    /etc/chrony/chrony.conf \
    /etc/chrony/chrony-bootstep.conf \
    /etc/systemd/system/chrony-bootstep-guard.service \
    /etc/systemd/system/chrony-health-journal.service \
    /etc/systemd/system/chrony-health-journal.timer \
    /etc/systemd/system/chrony.service.d/production.conf \
    /usr/local/sbin/chrony-bootstep-guard \
    /usr/local/sbin/chrony-health-journal \
    "$DISABLED_SCRIPT"; do
    [ ! -e "$path" ] || { echo "baseline path already exists: $path" >&2; exit 65; }
done
[ -f "$OLD_SCRIPT" ] || { echo "baseline time-sync script missing: $OLD_SCRIPT" >&2; exit 65; }

rollback() {
    local original_code=$?
    trap - ERR
    if [ "$changed" -eq 0 ]; then
        echo "ROLLBACK_SKIPPED no_host_changes original_exit=$original_code" >&2
        exit "$original_code"
    fi
    set +e
    /usr/bin/logger -t deployment-time-sync "status=rollback_started backup=$BACKUP_DIR exit_code=$original_code"
    /usr/bin/systemctl disable --now chrony-health-journal.timer chrony-bootstep-guard.service chrony.service >/dev/null 2>&1
    /usr/bin/rm -f \
        /etc/systemd/system/chrony-health-journal.timer \
        /etc/systemd/system/chrony-health-journal.service \
        /etc/systemd/system/chrony-bootstep-guard.service \
        /etc/systemd/system/chrony.service.d/production.conf \
        /usr/local/sbin/chrony-health-journal \
        /usr/local/sbin/chrony-bootstep-guard \
        /etc/chrony/chrony.conf \
        /etc/chrony/chrony-bootstep.conf
    if [ -e "$DISABLED_SCRIPT" ]; then
        /usr/bin/mv -f "$DISABLED_SCRIPT" "$OLD_SCRIPT"
    elif [ ! -e "$OLD_SCRIPT" ]; then
        /usr/bin/cp -a "$BACKUP_DIR/files/timesync-github.sh" "$OLD_SCRIPT"
    fi
    /usr/bin/crontab "$BACKUP_DIR/files/root.crontab"
    if [ "$installed" -eq 1 ]; then
        /usr/bin/apt-get purge -y chrony >/dev/null 2>&1
        /usr/bin/apt-get install -y "$TIMESYNCD_DEB" >/dev/null 2>&1
    fi
    /usr/bin/cp -a "$BACKUP_DIR/files/timesyncd.conf" /etc/systemd/timesyncd.conf
    /usr/bin/systemctl daemon-reload
    /usr/bin/systemctl unmask chrony.service >/dev/null 2>&1
    /usr/bin/systemctl unmask systemd-timesyncd.service >/dev/null 2>&1
    /usr/bin/systemctl enable --now systemd-timesyncd.service >/dev/null 2>&1
    /usr/bin/logger -t deployment-time-sync "status=rollback_finished backup=$BACKUP_DIR"
    echo "ROLLBACK_FINISHED backup=$BACKUP_DIR original_exit=$original_code" >&2
    exit "$original_code"
}
trap rollback ERR

(cd "$BACKUP_DIR/packages" && /usr/bin/sha256sum -c SHA256SUMS)
/usr/bin/bash -n "$CANDIDATE_DIR/chrony-bootstep-guard" "$CANDIDATE_DIR/chrony-health-journal"

/usr/bin/systemctl show nginx php8.3-fpm deployment-agent deployment-server-agent \
    -p Id -p ExecMainStartTimestamp -p ActiveEnterTimestamp -p ExecReload -p NRestarts \
    > "$BACKUP_DIR/pre-application-service-times.txt"
/usr/bin/sha256sum /opt/deployment-server-agent/agent.php \
    /opt/deployment-server-agent/lib/OperationRegistry.php \
    /usr/local/sbin/deployment-service-reload > "$BACKUP_DIR/pre-install-hotfix-hashes.txt"

# Prevent package post-install hooks from starting chrony with its vendor
# defaults. The service is unmasked only after our configuration parses.
changed=1
/usr/bin/systemctl mask chrony.service >/dev/null
installed=1
DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y "$CHRONY_DEB"

/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony.conf" /etc/chrony/chrony.conf
/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony-bootstep.conf" /etc/chrony/chrony-bootstep.conf
/usr/bin/install -o root -g root -m 0755 "$CANDIDATE_DIR/chrony-bootstep-guard" /usr/local/sbin/chrony-bootstep-guard
/usr/bin/install -o root -g root -m 0755 "$CANDIDATE_DIR/chrony-health-journal" /usr/local/sbin/chrony-health-journal
/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony-bootstep-guard.service" /etc/systemd/system/chrony-bootstep-guard.service
/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony-health-journal.service" /etc/systemd/system/chrony-health-journal.service
/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony-health-journal.timer" /etc/systemd/system/chrony-health-journal.timer
/usr/bin/install -d -o root -g root -m 0755 /etc/systemd/system/chrony.service.d
/usr/bin/install -o root -g root -m 0644 "$CANDIDATE_DIR/chrony.service.d-production.conf" /etc/systemd/system/chrony.service.d/production.conf
/usr/bin/install -d -o _chrony -g _chrony -m 0755 /var/log/chrony /run/chrony

/usr/sbin/chronyd -p -f /etc/chrony/chrony.conf >/dev/null
/usr/sbin/chronyd -p -f /etc/chrony/chrony-bootstep.conf >/dev/null
/usr/bin/systemctl daemon-reload
/usr/bin/systemctl unmask chrony.service >/dev/null
/usr/bin/systemd-analyze verify \
    /etc/systemd/system/chrony-bootstep-guard.service \
    /etc/systemd/system/chrony-health-journal.service \
    /etc/systemd/system/chrony-health-journal.timer

/usr/bin/systemctl enable chrony.service chrony-bootstep-guard.service chrony-health-journal.timer >/dev/null
/usr/bin/systemctl start chrony.service
/usr/bin/chronyc waitsync 40 0.100 1000 1

tracking="$(/usr/bin/chronyc -n tracking)"
sources="$(/usr/bin/chronyc -n sources)"
printf '%s\n' "$tracking" | /usr/bin/grep -Fq 'Leap status     : Normal'
usable_sources="$(printf '%s\n' "$sources" | /usr/bin/awk '$1 ~ /^\^[+*]/ {count++} END {print count+0}')"
[ "$usable_sources" -ge 2 ]

# Disable the unsafe source only after chrony has passed its synchronization
# gate. The original bytes remain both in the backup and in the renamed file.
cron_tmp="$(/usr/bin/mktemp /tmp/root.crontab.without-github-time.XXXXXX)"
/usr/bin/crontab -l | /usr/bin/grep -vF '/usr/local/bin/timesync-github.sh' > "$cron_tmp"
/usr/bin/crontab "$cron_tmp"
/usr/bin/rm -f "$cron_tmp"
if [ -e "$OLD_SCRIPT" ]; then
    /usr/bin/mv "$OLD_SCRIPT" "$DISABLED_SCRIPT"
    /usr/bin/chmod 0600 "$DISABLED_SCRIPT"
fi

/usr/bin/systemctl start chrony-health-journal.timer
/usr/bin/systemctl start chrony-health-journal.service
/usr/bin/systemctl is-active --quiet chrony.service
/usr/bin/systemctl is-enabled --quiet chrony.service
! /usr/bin/systemctl is-active --quiet systemd-timesyncd.service
! /usr/bin/crontab -l | /usr/bin/grep -Fq 'timesync-github.sh'
[ ! -e "$OLD_SCRIPT" ]
[ -f "$DISABLED_SCRIPT" ]

/usr/bin/timedatectl status > "$BACKUP_DIR/post-timedatectl-status.txt"
/usr/bin/chronyc -n tracking > "$BACKUP_DIR/post-chrony-tracking.txt"
/usr/bin/chronyc -n sources > "$BACKUP_DIR/post-chrony-sources.txt"
/usr/bin/systemctl show nginx php8.3-fpm deployment-agent deployment-server-agent \
    -p Id -p ExecMainStartTimestamp -p ActiveEnterTimestamp -p ExecReload -p NRestarts \
    > "$BACKUP_DIR/post-application-service-times.txt"
/usr/bin/sha256sum /opt/deployment-server-agent/agent.php \
    /opt/deployment-server-agent/lib/OperationRegistry.php \
    /usr/local/sbin/deployment-service-reload > "$BACKUP_DIR/post-install-hotfix-hashes.txt"
/usr/bin/cmp "$BACKUP_DIR/pre-install-hotfix-hashes.txt" "$BACKUP_DIR/post-install-hotfix-hashes.txt"

origin_code="$(/usr/bin/curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 15 --resolve deployment.safhenokhost.ir:80:127.0.0.1 http://deployment.safhenokhost.ir/)"
cdn_code="$(/usr/bin/curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 20 https://deployment.safhenokhost.ir/)"
case "$origin_code:$cdn_code" in
    2??:2??|2??:3??|3??:2??|3??:3??) ;;
    *) echo "health gate failed: origin=$origin_code cdn=$cdn_code" >&2; exit 1 ;;
esac

trap - ERR
/usr/bin/logger -t deployment-time-sync "status=installed backup=$BACKUP_DIR usable_sources=$usable_sources origin=$origin_code cdn=$cdn_code runtime_step=disabled"
echo "TIME_SYNC_INSTALLED backup=$BACKUP_DIR usable_sources=$usable_sources origin=$origin_code cdn=$cdn_code"
printf '%s\n' "$tracking"
printf '%s\n' "$sources"
