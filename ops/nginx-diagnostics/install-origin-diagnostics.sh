#!/usr/bin/env bash
set -Eeuo pipefail

candidate_dir="${1:?candidate directory is required}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="/root/deployment-system-backups/nginx-diagnostic-${stamp}"
changed=0

vhosts=(deployment-system saas-platform customer-site)
new_files=(
  /etc/nginx/conf.d/10-origin-diagnostic-log.conf
  /etc/nginx/snippets/origin-diagnostic-access.conf
  /etc/logrotate.d/nginx-origin-diagnostic
)

rollback() {
  local rc="${1:-1}" prior_file
  trap - ERR
  if (( changed )); then
    for vhost in "${vhosts[@]}"; do
      install -o root -g root -m 0644 \
        "${backup_dir}/sites-available/${vhost}" "/etc/nginx/sites-available/${vhost}"
    done
    for path in "${new_files[@]}"; do
      prior_file="${backup_dir}/new-files/$(basename "$path")"
      if [[ -f "$prior_file" ]]; then
        cp -a -- "$prior_file" "$path"
      else
        rm -f -- "$path"
      fi
    done
    if [[ -f "${backup_dir}/nginx-config.sha256" ]]; then
      install -o root -g root -m 0644 "${backup_dir}/nginx-config.sha256" \
        /var/lib/deployment-agent/nginx-config.sha256
    else
      rm -f -- /var/lib/deployment-agent/nginx-config.sha256
    fi
    nginx -t
    systemctl reload nginx
  fi
  printf 'NGINX_DIAGNOSTIC_ROLLBACK backup=%s rc=%s\n' "$backup_dir" "$rc" >&2
  exit "$rc"
}
trap 'rollback $?' ERR

for file in \
  10-origin-diagnostic-log.conf \
  origin-diagnostic-access.conf \
  nginx-origin-diagnostic.logrotate \
  deployment-system.vhost \
  saas-platform.vhost \
  customer-site.vhost; do
  [[ -s "${candidate_dir}/${file}" ]]
done

install -d -o root -g root -m 0700 /root/deployment-system-backups
mkdir -m 0700 "$backup_dir"
mkdir -m 0700 "${backup_dir}/sites-available" "${backup_dir}/new-files"
for vhost in "${vhosts[@]}"; do
  cp -a "/etc/nginx/sites-available/${vhost}" "${backup_dir}/sites-available/${vhost}"
done
for path in "${new_files[@]}"; do
  if [[ -f "$path" ]]; then
    cp -a -- "$path" "${backup_dir}/new-files/$(basename "$path")"
  fi
done
if [[ -f /var/lib/deployment-agent/nginx-config.sha256 ]]; then
  cp -a /var/lib/deployment-agent/nginx-config.sha256 "${backup_dir}/nginx-config.sha256"
fi
systemctl show nginx php8.3-fpm -p Id -p ActiveEnterTimestamp -p ExecMainStartTimestamp \
  >"${backup_dir}/service-start-times.txt"
sha256sum \
  /opt/deployment-server-agent/agent.php \
  /opt/deployment-server-agent/lib/OperationRegistry.php \
  /usr/local/sbin/deployment-service-reload \
  >"${backup_dir}/hotfix-sha256.txt"
nginx -T >"${backup_dir}/nginx-before.txt" 2>"${backup_dir}/nginx-before.stderr"

# Validate candidate invariants before touching the active configuration.
grep -q 'escape=json' "${candidate_dir}/10-origin-diagnostic-log.conf"
grep -q '\$request_id' "${candidate_dir}/10-origin-diagnostic-log.conf"
! grep -Eqi '\$http_(authorization|cookie)|\$request_body|\$args|\$query_string|\$request_uri' \
  "${candidate_dir}/10-origin-diagnostic-log.conf"
for vhost in "${vhosts[@]}"; do
  grep -q 'include snippets/origin-diagnostic-access.conf;' "${candidate_dir}/${vhost}.vhost"
done

install -d -o root -g adm -m 0750 /var/log/nginx-diagnostic
changed=1
install -o root -g root -m 0644 "${candidate_dir}/10-origin-diagnostic-log.conf" \
  /etc/nginx/conf.d/10-origin-diagnostic-log.conf
install -o root -g root -m 0644 "${candidate_dir}/origin-diagnostic-access.conf" \
  /etc/nginx/snippets/origin-diagnostic-access.conf
install -o root -g root -m 0644 "${candidate_dir}/nginx-origin-diagnostic.logrotate" \
  /etc/logrotate.d/nginx-origin-diagnostic
for vhost in "${vhosts[@]}"; do
  install -o root -g root -m 0644 "${candidate_dir}/${vhost}.vhost" \
    "/etc/nginx/sites-available/${vhost}"
done

nginx -t
logrotate --debug /etc/logrotate.d/nginx-origin-diagnostic >"${backup_dir}/logrotate-debug.txt" 2>&1
systemctl reload nginx

for attempt in 1 2 3; do
  curl --silent --show-error --fail --max-time 10 \
    --resolve deployment.safhenokhost.ir:80:127.0.0.1 \
    -o /dev/null http://deployment.safhenokhost.ir/
  curl --silent --show-error --fail --max-time 10 \
    --resolve panel.safhenokhost.ir:80:127.0.0.1 \
    -o /dev/null http://panel.safhenokhost.ir/
  curl --silent --show-error --fail --max-time 10 \
    --resolve safhenokhost.ir:80:127.0.0.1 \
    -o /dev/null http://safhenokhost.ir/
  curl --silent --show-error --fail --max-time 15 -o /dev/null \
    https://deployment.safhenokhost.ir/
  curl --silent --show-error --fail --max-time 15 -o /dev/null \
    https://panel.safhenokhost.ir/
  curl --silent --show-error --fail --max-time 15 -o /dev/null \
    https://safhenokhost.ir/
done

test -s /var/log/nginx-diagnostic/origin-access.log
chown www-data:adm /var/log/nginx-diagnostic/origin-access.log
chmod 0640 /var/log/nginx-diagnostic/origin-access.log
tail -n 25 /var/log/nginx-diagnostic/origin-access.log | grep -q '"request_id":"[^"]\+"'
tail -n 25 /var/log/nginx-diagnostic/origin-access.log | grep -q '"host":"deployment.safhenokhost.ir"'

# Keep the Agent hotfix's Nginx fingerprint aligned with this intentional reload.
nginx -T 2>/dev/null | sha256sum | awk '{print $1}' \
  >"${backup_dir}/nginx-config.sha256.new"
install -o root -g root -m 0644 "${backup_dir}/nginx-config.sha256.new" \
  /var/lib/deployment-agent/nginx-config.sha256

sha256sum \
  /opt/deployment-server-agent/agent.php \
  /opt/deployment-server-agent/lib/OperationRegistry.php \
  /usr/local/sbin/deployment-service-reload \
  >"${backup_dir}/hotfix-sha256.after.txt"
cmp "${backup_dir}/hotfix-sha256.txt" "${backup_dir}/hotfix-sha256.after.txt"
systemctl show nginx php8.3-fpm -p Id -p ActiveEnterTimestamp -p ExecMainStartTimestamp \
  >"${backup_dir}/service-start-times.after.txt"

trap - ERR
printf 'NGINX_DIAGNOSTIC_INSTALLED backup=%s\n' "$backup_dir"
