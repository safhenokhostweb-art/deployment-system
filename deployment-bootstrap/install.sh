#!/usr/bin/env bash
# One-Click Deployment Bootstrap — نصب کامل و خودکار.
#
# استفاده:
#   chmod +x install.sh
#   sudo ./install.sh
#
# هیچ Secret واقعی در این فایل یا در config.env نیست — Agent Token،
# Signing Secret و Webhook Secret همگی در همین اجرا، توسط
# «php artisan deployment:bootstrap»، تازه ساخته می‌شوند.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# فایل ZIP دانلودشده روی Windows/macOS ممکن است Permission اجرا را برای
# verify.sh/uninstall.sh حفظ نکند — کاربر طبق README فقط install.sh را
# chmod +x می‌کند، پس همینجا برای بقیه اسکریپت‌های خودمان هم تضمین می‌شود.
chmod +x "$SCRIPT_DIR/verify.sh" "$SCRIPT_DIR/uninstall.sh" 2>/dev/null || true

INSTALL_LOG="/var/log/deployment-bootstrap-install.log"
INSTALL_DIR="/opt/deployment-agent"
SERVICE_NAME="deployment-agent"
SERVICE_USER="deployment-agent"

say() {
    echo "$1"
    echo "$1" >> "$INSTALL_LOG" 2>/dev/null || true
}

fail() {
    say ""
    say "خطا: $1"
    say "لاگ کامل نصب: $INSTALL_LOG"
    say "برای تلاش مجدد: sudo ./install.sh"
    exit 1
}

trap 'fail "خطای غیرمنتظره در خط ${LINENO}"' ERR

# --- Phase A: پیش‌نیازها ---------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
    echo "این installer باید با sudo اجرا شود: sudo ./install.sh"
    exit 1
fi

: > "$INSTALL_LOG" 2>/dev/null || true
say "=== شروع One-Click Deployment Bootstrap — $(date '+%Y-%m-%d %H:%M:%S') ==="

for cmd in git curl; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        fail "دستور «$cmd» روی این سیستم پیدا نشد. ابتدا آن را نصب کنید."
    fi
done

if ! command -v systemctl >/dev/null 2>&1; then
    fail "systemd روی این سیستم پیدا نشد. این installer فقط از توزیع‌های مبتنی بر systemd پشتیبانی می‌کند."
fi

# --- Phase B: config.env ----------------------------------------------

CONFIG_PATH="$SCRIPT_DIR/config.env"
if [ ! -f "$CONFIG_PATH" ]; then
    if [ ! -f "$SCRIPT_DIR/config.example.env" ]; then
        fail "نه config.env و نه config.example.env در $SCRIPT_DIR پیدا نشد."
    fi
    cp "$SCRIPT_DIR/config.example.env" "$CONFIG_PATH"
    say "config.env از روی config.example.env ساخته شد."

    if [ -t 0 ]; then
        say "مقادیر پیش‌فرض config.env همان مقادیر سرور فعلی است. اگر می‌خواهید ویرایش کنید:"
        say "  nano $CONFIG_PATH"
        read -r -p "برای ادامه Enter را بزنید (یا Ctrl+C برای توقف و ویرایش دستی)... " _ || true
    fi
else
    say "از config.env موجود در $CONFIG_PATH استفاده می‌شود."
fi

# shellcheck disable=SC1090
set -a
source "$CONFIG_PATH"
set +a

: "${DEPLOYMENT_APP_PATH:?DEPLOYMENT_APP_PATH در config.env تنظیم نشده است}"
: "${REPOSITORY_PATH:?REPOSITORY_PATH در config.env تنظیم نشده است}"

if [ ! -f "$DEPLOYMENT_APP_PATH/artisan" ]; then
    fail "فایل artisan در DEPLOYMENT_APP_PATH ($DEPLOYMENT_APP_PATH) پیدا نشد — مسیر را در config.env بررسی کنید."
fi

# --- Phase C: بررسی وضعیت Git (فقط گزارش — بررسی امن واقعی در خود artisan command انجام می‌شود) ---

if [ -d "$REPOSITORY_PATH/.git" ]; then
    if [ -n "$(git -C "$REPOSITORY_PATH" status --porcelain 2>/dev/null || true)" ]; then
        say "هشدار: Working Tree در $REPOSITORY_PATH تغییرات ذخیره‌نشده دارد:"
        git -C "$REPOSITORY_PATH" status --porcelain 2>/dev/null | while IFS= read -r line; do say "  $line"; done
    fi
else
    say "هشدار: هنوز مخزن Git در $REPOSITORY_PATH وجود ندارد — این فقط برای اجرای واقعی Pipeline لازم است، نه برای همین ثبت اولیه."
fi

# --- Phase D: پیدا کردن PHP CLI ----------------------------------------

PHP_BIN="${PHP_BINARY:-}"
if [ -z "$PHP_BIN" ] || ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    PHP_BIN=""
    for candidate in php php8.3 php8.4 php8.2 php8.1; do
        if command -v "$candidate" >/dev/null 2>&1; then
            PHP_BIN="$(command -v "$candidate")"
            break
        fi
    done
fi
if [ -z "$PHP_BIN" ]; then
    fail "PHP CLI پیدا نشد."
fi
say "PHP یافت شد: $PHP_BIN"

# --- Phase E: اجرای Artisan Bootstrap Command --------------------------

SECRETS_FILE="$(mktemp /tmp/deployment-bootstrap-secrets-XXXXXX.json)"
chmod 600 "$SECRETS_FILE"

say "در حال اجرای php artisan deployment:bootstrap ..."
if ! (cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan deployment:bootstrap \
    --config="$CONFIG_PATH" \
    --non-interactive \
    ${ADMIN_EMAIL:+--admin-email="$ADMIN_EMAIL"} \
    --secrets-output="$SECRETS_FILE" | tee -a "$INSTALL_LOG"); then
    rm -f "$SECRETS_FILE"
    fail "اجرای deployment:bootstrap ناموفق بود — پیام بالا را بررسی کنید."
fi

# --- Phase F: نصب فایل‌های Agent ---------------------------------------

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
    say "کاربر سیستمی $SERVICE_USER ایجاد شد."
else
    say "کاربر سیستمی $SERVICE_USER از قبل وجود دارد."
fi

mkdir -p "$INSTALL_DIR/lib" "$INSTALL_DIR/storage/logs"
cp "$SCRIPT_DIR/files/agent/agent.php" "$INSTALL_DIR/agent.php"
cp "$SCRIPT_DIR"/files/agent/lib/*.php "$INSTALL_DIR/lib/"
say "فایل‌های Agent در $INSTALL_DIR کپی شدند."

# AGENT_CONFIG معادل null یعنی این یک اجرای مجدد بود و Secret تازه‌ای
# ساخته نشد — config.php موجود (اگر باشد) دست‌نخورده می‌ماند؛ در غیر
# این صورت از روی داده‌های امن Secrets File ساخته می‌شود.
if "$PHP_BIN" -r '
$data = json_decode(file_get_contents($argv[1]), true);
exit(($data["agent_config"] ?? null) === null ? 1 : 0);
' "$SECRETS_FILE"; then
    "$PHP_BIN" -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $cfg = $data["agent_config"];
    $lines = ["<?php", "", "return ["];
    foreach (["service_base_url", "server_id", "agent_token", "signing_secret"] as $k) {
        $lines[] = "    " . var_export($k, true) . " => " . var_export($cfg[$k], true) . ",";
    }
    $lines[] = "    \x27poll_interval_seconds\x27 => " . $cfg["poll_interval_seconds"] . ",";
    $lines[] = "    \x27request_timeout_seconds\x27 => " . $cfg["request_timeout_seconds"] . ",";
    $lines[] = "    \x27default_step_timeout_seconds\x27 => " . $cfg["default_step_timeout_seconds"] . ",";
    $lines[] = "    \x27projects\x27 => [";
    foreach ($cfg["projects"] as $key => $p) {
        $lines[] = "        " . var_export($key, true) . " => [\x27working_directory\x27 => " . var_export($p["working_directory"], true) . ", \x27php_binary\x27 => " . var_export($p["php_binary"], true) . "],";
    }
    $lines[] = "    ],";
    $lines[] = "    \x27log_dir\x27 => __DIR__ . \x27/storage/logs\x27,";
    $lines[] = "];";
    $lines[] = "";
    file_put_contents($argv[2], implode("\n", $lines));
    ' "$SECRETS_FILE" "$INSTALL_DIR/config.php"
    chmod 600 "$INSTALL_DIR/config.php"
    say "config.php تازه ساخته شد (Permission 600)."
else
    say "config.php موجود دست‌نخورده باقی ماند (این یک اجرای مجدد بود، Secret تازه‌ای ساخته نشد)."
fi

shred -u "$SECRETS_FILE" 2>/dev/null || rm -f "$SECRETS_FILE"

mkdir -p "$INSTALL_DIR/projects/${PROJECT_KEY}"
chown -R "$SERVICE_USER":"$SERVICE_USER" "$INSTALL_DIR"
chmod 750 "$INSTALL_DIR"
[ -f "$INSTALL_DIR/config.php" ] && chmod 600 "$INSTALL_DIR/config.php"

# --- Phase G: systemd service -------------------------------------------

UNIT_PATH="/etc/systemd/system/${SERVICE_NAME}.service"
if [ -f "$UNIT_PATH" ]; then
    cp "$UNIT_PATH" "${UNIT_PATH}.bak.$(date +%s)"
fi

sed \
    -e "s#CHANGE_ME_DEPLOY_USER#${SERVICE_USER}#g" \
    -e "s#/CHANGE_ME/path/to/deployment-system/agent#${INSTALL_DIR}#g" \
    -e "s#/usr/bin/php #${PHP_BIN} #g" \
    "$SCRIPT_DIR/files/systemd/deployment-agent.service.example" > "$UNIT_PATH"

# The deployment pipeline runs tightly constrained sudo helpers (see
# agent/install/upgrade-runtime.sh). sudo requires NoNewPrivileges=false;
# the sudoers allowlists still reject every other elevation attempt. Same
# dedicated drop-in pattern as upgrade-runtime.sh and the production fix.
# Written idempotently (cat > overwrites) so re-running this installer is
# safe and never duplicates anything or touches user-created drop-ins.
install -d -o root -g root -m 0755 "/etc/systemd/system/${SERVICE_NAME}.service.d"
cat > "/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf" <<'EOF'
[Service]
# sudo is constrained to two literal root-owned helpers in /etc/sudoers.d.
NoNewPrivileges=false
EOF
chmod 0644 "/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf"

systemctl daemon-reload
systemctl enable "$SERVICE_NAME" >/dev/null
systemctl restart "$SERVICE_NAME"
sleep 2

if ! systemctl is-active --quiet "$SERVICE_NAME"; then
    fail "سرویس $SERVICE_NAME بالا نیامد. journalctl -u $SERVICE_NAME -n 50 --no-pager را بررسی کنید."
fi
say "سرویس $SERVICE_NAME فعال شد و enable شد (اجرای خودکار پس از Boot)."

# --- Phase H: sudoers محدود برای Restart سرویس‌ها در Pipeline -----------

SUDOERS_PATH="/etc/sudoers.d/${SERVICE_USER}"
SUDOERS_TMP="$(mktemp)"
{
    echo "# ساخته‌شده توسط deployment-bootstrap/install.sh — فقط دو دستور مشخص، بدون رمز"
    echo "${SERVICE_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload ${PHP_FPM_SERVICE}, /usr/bin/systemctl reload ${WEB_SERVER_SERVICE}"
} > "$SUDOERS_TMP"

if visudo -c -f "$SUDOERS_TMP" >/dev/null 2>&1; then
    install -m 0440 "$SUDOERS_TMP" "$SUDOERS_PATH"
    say "دسترسی sudo محدود (فقط reload ${PHP_FPM_SERVICE} و ${WEB_SERVER_SERVICE}) برای ${SERVICE_USER} تنظیم شد."
else
    say "هشدار: فایل sudoers نامعتبر تشخیص داده شد — این مرحله رد شد (Pipeline بدون این مجوز، در مرحله Restart سرویس‌ها با خطا مواجه خواهد شد)."
fi
rm -f "$SUDOERS_TMP"

# --- Phase I: Scheduler (cron) و Queue Worker (supervisor) --------------

CRON_LINE="* * * * * cd ${DEPLOYMENT_APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
if ! (crontab -l 2>/dev/null | grep -qF "artisan schedule:run"); then
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
    say "Cron برای Laravel Scheduler اضافه شد (هر دقیقه)."
else
    say "Cron برای Laravel Scheduler از قبل وجود دارد — دست‌نخورده ماند."
fi

if command -v supervisorctl >/dev/null 2>&1; then
    SUPERVISOR_CONF="/etc/supervisor/conf.d/deployment-service-worker.conf"
    sed \
        -e "s#/CHANGE_ME/path/to/deployment-system/service#${DEPLOYMENT_APP_PATH}#g" \
        -e "s#CHANGE_ME_DEPLOY_USER#${SERVICE_USER}#g" \
        "$SCRIPT_DIR/files/systemd/queue-worker.supervisor.conf.example" > "$SUPERVISOR_CONF"
    supervisorctl reread >/dev/null 2>&1 || true
    supervisorctl update >/dev/null 2>&1 || true
    supervisorctl start deployment-service-worker:* >/dev/null 2>&1 || true
    say "Queue Worker از طریق Supervisor تنظیم شد."
else
    say "هشدار: Supervisor روی این سیستم نصب نیست — Queue Worker فعال نشد."
    say "برای نصب (در صورت دسترسی شبکه): apt-get install -y supervisor"
    say "سپس دوباره ./install.sh را اجرا کنید."
fi

say "در حال اجرای verify.sh ..."
if ! "$SCRIPT_DIR/verify.sh"; then
    say "verify.sh برخی موارد را ناموفق گزارش کرد — جزئیات را در بالا ببینید."
fi

trap - ERR

echo ""
echo "=============================================="
echo "نصب کامل شد."
echo "پنل: ${DEPLOYMENT_PANEL_URL:-؟}"
echo "لاگ کامل نصب: $INSTALL_LOG"
echo "=============================================="
