#!/usr/bin/env bash
# One-Click Deployment Bootstrap — بررسی سلامت نصب.
#
# استفاده:
#   sudo ./verify.sh
#
# هیچ تغییری در سیستم ایجاد نمی‌کند — فقط وضعیت فعلی را می‌خواند و گزارش
# می‌دهد. توسط install.sh در پایان نصب هم به‌صورت خودکار اجرا می‌شود.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="/opt/deployment-server-agent"
SERVICE_NAME="deployment-server-agent"

PASS=0
FAIL=0

ok() {
    echo "  [OK] $1"
    PASS=$((PASS + 1))
}

bad() {
    echo "  [ناموفق] $1"
    FAIL=$((FAIL + 1))
}

section() {
    echo ""
    echo "--- $1 ---"
}

CONFIG_PATH="$SCRIPT_DIR/config.env"
if [ ! -f "$CONFIG_PATH" ]; then
    echo "خطا: config.env در $SCRIPT_DIR پیدا نشد — ابتدا install.sh را اجرا کنید."
    exit 1
fi

# shellcheck disable=SC1090
set -a
source "$CONFIG_PATH"
set +a

: "${DEPLOYMENT_APP_PATH:?DEPLOYMENT_APP_PATH در config.env تنظیم نشده است}"

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
    echo "خطا: PHP CLI پیدا نشد."
    exit 1
fi

# --- بخش ۱: خود Laravel ------------------------------------------------

section "پنل Laravel"

if (cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan --version >/dev/null 2>&1); then
    ok "دستور artisan اجرا می‌شود."
else
    bad "دستور artisan اجرا نشد — مسیر DEPLOYMENT_APP_PATH یا نصب Laravel را بررسی کنید."
fi

MIGRATE_STATUS="$(cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan migrate:status 2>&1 || true)"
if echo "$MIGRATE_STATUS" | grep -qi "pending"; then
    bad "برخی Migration ها هنوز اجرا نشده‌اند (php artisan migrate --force را دستی اجرا کنید)."
else
    ok "همه Migration ها اجرا شده‌اند."
fi

# --- بخش ۲: رکوردهای ثبت‌شده (Server/Agent/Project/Environment/Target) --

section "رکوردهای ثبت‌شده در پنل"

RECORDS_JSON="$(cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan tinker --execute='
    $server = \App\Models\Server::where("name", getenv("SERVER_NAME"))->first();
    $project = \App\Models\Project::where("project_key", getenv("PROJECT_KEY"))->first();
    $agent = $server ? \App\Models\Agent::where("server_id", $server->id)->orderByRaw("CASE WHEN status = \"approved\" THEN 0 ELSE 1 END")->first() : null;
    $environment = $project ? \App\Models\Environment::where("project_id", $project->id)->where("key", getenv("ENVIRONMENT_KEY"))->first() : null;
    $target = $environment ? \App\Models\DeploymentTarget::where("environment_id", $environment->id)->where("name", getenv("TARGET_NAME"))->first() : null;
    echo json_encode([
        "server" => $server ? ["id" => $server->public_id] : null,
        "agent" => $agent ? ["id" => $agent->public_id, "status" => $agent->status, "last_seen_at" => optional($agent->last_seen_at)->toIso8601String(), "online" => $agent->isOnline()] : null,
        "project" => $project ? ["id" => $project->public_id, "branch" => $project->branch, "repository" => $project->repository] : null,
        "environment" => $environment ? ["id" => $environment->id] : null,
        "target" => $target ? ["id" => $target->id, "server_id" => $target->server_id, "agent_id" => $target->agent_id] : null,
    ]);
' 2>/dev/null | tail -1)"

get_field() {
    "$PHP_BIN" -r '
        $data = json_decode($argv[1], true);
        $path = explode(".", $argv[2]);
        $value = $data;
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        echo $value === null ? "" : (is_bool($value) ? ($value ? "1" : "") : $value);
    ' "$RECORDS_JSON" "$1"
}

if [ -n "$(get_field server.id)" ]; then
    ok "Server ثبت شده است (#$(get_field server.id))."
else
    bad "Server با نام «${SERVER_NAME:-؟}» پیدا نشد."
fi

if [ -n "$(get_field agent.id)" ]; then
    if [ "$(get_field agent.status)" = "approved" ]; then
        ok "Agent ثبت و تایید شده است (#$(get_field agent.id))."
    else
        bad "Agent پیدا شد اما وضعیت آن «$(get_field agent.status)» است، نه approved."
    fi

    if [ -n "$(get_field agent.last_seen_at)" ]; then
        ok "Agent حداقل یک Heartbeat فرستاده است (آخرین بار: $(get_field agent.last_seen_at))."
    else
        bad "Agent هنوز هیچ Heartbeat ای نفرستاده — سرویس $SERVICE_NAME را بررسی کنید."
    fi

    if [ "$(get_field agent.online)" = "1" ]; then
        ok "Agent هم‌اکنون Online است."
    else
        bad "Agent هم‌اکنون Offline است (آخرین Heartbeat قدیمی‌تر از حد مجاز است)."
    fi
else
    bad "هیچ Agent ای برای این Server پیدا نشد."
fi

if [ -n "$(get_field project.id)" ]; then
    ok "Project ثبت شده است (#$(get_field project.id))."

    if [ "$(get_field project.branch)" = "${DEPLOY_BRANCH:-}" ]; then
        ok "Branch پروژه با DEPLOY_BRANCH (${DEPLOY_BRANCH:-؟}) مطابقت دارد."
    else
        bad "Branch پروژه «$(get_field project.branch)» است، نه «${DEPLOY_BRANCH:-؟}»."
    fi
else
    bad "Project با کلید «${PROJECT_KEY:-؟}» پیدا نشد."
fi

if [ -n "$(get_field environment.id)" ]; then
    ok "Environment ثبت شده است."
else
    bad "Environment با کلید «${ENVIRONMENT_KEY:-؟}» پیدا نشد."
fi

if [ -n "$(get_field target.id)" ]; then
    ok "Deployment Target ثبت شده است."
    if [ -n "$(get_field target.agent_id)" ]; then
        ok "Deployment Target به یک Agent متصل است."
    else
        bad "Deployment Target به هیچ Agent ای متصل نیست."
    fi
else
    bad "Deployment Target با نام «${TARGET_NAME:-؟}» پیدا نشد."
fi

# --- بخش ۳: Webhook -----------------------------------------------------

section "GitHub Webhook"

if (cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan route:list --name=webhooks.github 2>/dev/null | grep -q "webhooks/github"); then
    ok "Route دریافت Webhook در پنل ثبت شده است."
else
    bad "Route دریافت Webhook (webhooks.github) پیدا نشد."
fi

# --- بخش ۴: سرویس‌های سیستمی --------------------------------------------

section "سرویس‌های سیستمی"

if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet "$SERVICE_NAME"; then
        ok "سرویس $SERVICE_NAME (Agent) فعال است."
    else
        bad "سرویس $SERVICE_NAME فعال نیست (systemctl status $SERVICE_NAME را بررسی کنید)."
    fi

    if systemctl is-enabled --quiet "$SERVICE_NAME" 2>/dev/null; then
        ok "سرویس $SERVICE_NAME برای اجرای خودکار پس از Boot فعال (enabled) است."
    else
        bad "سرویس $SERVICE_NAME enable نشده است."
    fi

    # drop-in مخصوص sudo helpers محدود — بدون آن (NoNewPrivileges=true)
    # حتی sudo های کاملاً محدود شده هم در Pipeline کار نمی‌کنند.
    DROPIN_PATH="/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf"
    if [ -f "$DROPIN_PATH" ] && grep -q '^NoNewPrivileges=false$' "$DROPIN_PATH"; then
        ok "drop-in sudo helpers ($DROPIN_PATH) نصب و NoNewPrivileges=false است."
    else
        bad "drop-in $DROPIN_PATH پیدا نشد یا NoNewPrivileges=false ندارد — sudo های محدود Pipeline کار نخواهند کرد."
    fi
else
    bad "systemd روی این سیستم پیدا نشد."
fi

if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    ok "Cron مربوط به Laravel Scheduler نصب است."
else
    bad "Cron مربوط به Laravel Scheduler پیدا نشد."
fi

if command -v supervisorctl >/dev/null 2>&1; then
    if supervisorctl status 2>/dev/null | grep -q "deployment-service-worker.*RUNNING"; then
        ok "Queue Worker (Supervisor) در حال اجراست."
    else
        bad "Queue Worker (Supervisor) در حال اجرا نیست."
    fi
else
    bad "Supervisor روی این سیستم نصب نیست — Queue Worker بررسی نشد."
fi

# --- بخش ۵: HTTPS پنل ----------------------------------------------------

section "HTTPS پنل"

if [ -n "${DEPLOYMENT_PANEL_URL:-}" ]; then
    if curl --fail --silent --show-error --max-time 10 "${DEPLOYMENT_PANEL_URL}/login" >/dev/null 2>&1; then
        ok "پنل روی ${DEPLOYMENT_PANEL_URL}/login در دسترس است."
    else
        bad "پنل روی ${DEPLOYMENT_PANEL_URL}/login در دسترس نیست."
    fi

    LOGIN_HTML="$(curl --silent --max-time 10 "${DEPLOYMENT_PANEL_URL}/login" 2>/dev/null || true)"
    if echo "$LOGIN_HTML" | grep -qi 'action="https://'; then
        ok "فرم ورود روی HTTPS ثبت می‌شود."
    elif echo "$LOGIN_HTML" | grep -qi 'action="http://'; then
        bad "فرم ورود روی HTTP (نه HTTPS) ثبت می‌شود — URL::forceScheme('https') را بررسی کنید."
    else
        ok "فرم ورود بدون Scheme صریح (نسبی) است — با HTTPS پنل سازگار است."
    fi
else
    bad "DEPLOYMENT_PANEL_URL در config.env تنظیم نشده است."
fi

# --- بخش ۶: عدم افشای Secret ---------------------------------------------

section "بررسی عدم افشای Secret"

if [ -f "$INSTALL_DIR/config.php" ]; then
    PERMS="$(stat -c '%a' "$INSTALL_DIR/config.php" 2>/dev/null || echo '?')"
    if [ "$PERMS" = "600" ]; then
        ok "Permission فایل config.php کارگزار برابر 600 است."
    else
        bad "Permission فایل config.php کارگزار برابر $PERMS است، نه 600 — دستی اصلاح کنید: chmod 600 $INSTALL_DIR/config.php"
    fi
else
    bad "فایل config.php کارگزار در $INSTALL_DIR پیدا نشد."
fi

if [ -f "/var/log/deployment-bootstrap-install.log" ] && grep -qiE "agent_token|signing_secret" /var/log/deployment-bootstrap-install.log 2>/dev/null; then
    bad "لاگ نصب حاوی رشته agent_token/signing_secret است — دستی بررسی و پاک‌سازی کنید."
else
    ok "لاگ نصب حاوی Secret شناخته‌شده نیست."
fi

# --- جمع‌بندی -------------------------------------------------------------

echo ""
echo "=============================================="
echo "نتیجه: $PASS مورد موفق، $FAIL مورد ناموفق."
echo "=============================================="

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi

exit 0
