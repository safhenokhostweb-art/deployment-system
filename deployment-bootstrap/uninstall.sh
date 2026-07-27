#!/usr/bin/env bash
# One-Click Deployment Bootstrap — حذف نصب.
#
# استفاده:
#   sudo ./uninstall.sh                 غیرفعال کردن سرویس Agent (پیش‌فرض، غیرمخرب، قابل بازگشت)
#   sudo ./uninstall.sh --services-only  حذف سرویس Agent + Cron + sudoers + Supervisor (فایل‌ها و رکوردهای دیتابیس دست‌نخورده می‌مانند)
#   sudo ./uninstall.sh --remove-agent   موارد بالا + حذف کامل فایل‌های Agent و کاربر سیستمی (رکوردهای دیتابیس دست‌نخورده می‌مانند)
#   sudo ./uninstall.sh --purge          موارد بالا + حذف رکوردهای دیتابیس، فقط از طریق منطق موجود پروژه (نیازمند تایید صریح)
#
# هیچ‌کدام از این حالت‌ها هرگز SQL خام اجرا نمی‌کنند یا رکوردی را برخلاف
# قوانین یکپارچگی موجود (مثلاً Server ای که هنوز Project دارد) حذف
# نمی‌کنند — این همان محدودیت‌هایی است که خود پنل هم روی Server/Project/
# Agent/Deployment Target اعمال می‌کند.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="/opt/deployment-agent"
SERVICE_NAME="deployment-agent"
SERVICE_USER="deployment-agent"

MODE="disable-only"
case "${1:-}" in
    "") ;;
    --services-only) MODE="services-only" ;;
    --remove-agent) MODE="remove-agent" ;;
    --purge) MODE="purge" ;;
    *)
        echo "گزینه ناشناخته: ${1}"
        echo "استفاده: sudo ./uninstall.sh [--services-only|--remove-agent|--purge]"
        exit 1
        ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    echo "این اسکریپت باید با sudo اجرا شود."
    exit 1
fi

say() { echo "$1"; }

# --- همیشه: توقف و غیرفعال‌سازی سرویس Agent -----------------------------

if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files "${SERVICE_NAME}.service" >/dev/null 2>&1; then
    systemctl stop "$SERVICE_NAME" 2>/dev/null || true
    systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
    say "سرویس $SERVICE_NAME متوقف و غیرفعال شد."
else
    say "سرویس $SERVICE_NAME پیدا نشد (شاید قبلاً حذف شده)."
fi

if [ "$MODE" = "disable-only" ]; then
    say ""
    say "فقط سرویس Agent غیرفعال شد — فایل‌ها، تنظیمات Cron/sudoers/Supervisor و رکوردهای دیتابیس دست‌نخورده ماندند."
    say "برای حذف بیشتر: sudo ./uninstall.sh --services-only"
    exit 0
fi

# --- services-only و بالاتر: Cron / sudoers / Supervisor / واحد systemd -

CONFIG_PATH="$SCRIPT_DIR/config.env"
if [ -f "$CONFIG_PATH" ]; then
    # shellcheck disable=SC1090
    set -a
    source "$CONFIG_PATH"
    set +a
fi

UNIT_PATH="/etc/systemd/system/${SERVICE_NAME}.service"
if [ -f "$UNIT_PATH" ]; then
    rm -f "$UNIT_PATH"
    systemctl daemon-reload
    say "واحد systemd ($UNIT_PATH) حذف شد."
fi

SUDOERS_PATH="/etc/sudoers.d/${SERVICE_USER}"
if [ -f "$SUDOERS_PATH" ]; then
    rm -f "$SUDOERS_PATH"
    say "فایل sudoers ($SUDOERS_PATH) حذف شد."
fi

if crontab -l 2>/dev/null | grep -qF "artisan schedule:run"; then
    (crontab -l 2>/dev/null | grep -vF "artisan schedule:run") | crontab -
    say "خط Cron مربوط به Laravel Scheduler حذف شد."
fi

if command -v supervisorctl >/dev/null 2>&1; then
    SUPERVISOR_CONF="/etc/supervisor/conf.d/deployment-service-worker.conf"
    if [ -f "$SUPERVISOR_CONF" ]; then
        supervisorctl stop deployment-service-worker:* >/dev/null 2>&1 || true
        rm -f "$SUPERVISOR_CONF"
        supervisorctl reread >/dev/null 2>&1 || true
        supervisorctl update >/dev/null 2>&1 || true
        say "تنظیمات Queue Worker (Supervisor) حذف شد."
    fi
fi

if [ "$MODE" = "services-only" ]; then
    say ""
    say "سرویس‌ها (Agent، Cron، sudoers، Supervisor) حذف شدند."
    say "فایل‌های Agent در $INSTALL_DIR و رکوردهای دیتابیس دست‌نخورده ماندند."
    say "برای حذف کامل فایل‌های Agent: sudo ./uninstall.sh --remove-agent"
    exit 0
fi

# --- remove-agent و بالاتر: فایل‌ها و کاربر سیستمی ------------------------

if [ -d "$INSTALL_DIR" ]; then
    rm -rf "$INSTALL_DIR"
    say "پوشه Agent ($INSTALL_DIR) حذف شد."
fi

if id "$SERVICE_USER" >/dev/null 2>&1; then
    userdel "$SERVICE_USER" 2>/dev/null || true
    say "کاربر سیستمی $SERVICE_USER حذف شد."
fi

if [ "$MODE" = "remove-agent" ]; then
    say ""
    say "فایل‌های Agent و کاربر سیستمی حذف شدند."
    say "رکوردهای Server/Agent/Project/Environment/Deployment Target در دیتابیس پنل دست‌نخورده ماندند."
    say "برای حذف آن‌ها هم (در صورت نیاز): sudo ./uninstall.sh --purge"
    exit 0
fi

# --- purge: حذف رکوردهای دیتابیس، فقط از طریق منطق موجود پروژه ----------

: "${DEPLOYMENT_APP_PATH:?DEPLOYMENT_APP_PATH در config.env تنظیم نشده است — --purge بدون آن ممکن نیست}"

say ""
say "=== حالت --purge ==="
say "این حالت تلاش می‌کند رکوردهای زیر را که توسط همین Bootstrap ساخته شده‌اند حذف کند:"
say "  Server: ${SERVER_NAME:-؟}"
say "  Agent: ${AGENT_NAME:-؟}"
say "  Project: ${PROJECT_NAME:-؟} (${PROJECT_KEY:-؟})"
say "  Environment: ${ENVIRONMENT_NAME:-؟} (غیرفعال می‌شود — پنل امکان حذف کامل Environment را ندارد)"
say "  Deployment Target: ${TARGET_NAME:-؟}"
say ""
say "دقیقاً مثل پنل، هر رکوردی که وابستگی فعال دارد (مثلاً Server ای با Project دیگر، یا Agent ای با تاریخچه Job) حذف نخواهد شد."
say "این عملیات قابل بازگشت نیست."
say ""

if [ -t 0 ]; then
    read -r -p "برای تایید، عبارت PURGE را دقیقاً وارد کنید: " CONFIRMATION
    if [ "$CONFIRMATION" != "PURGE" ]; then
        say "لغو شد — چیزی از دیتابیس حذف نشد."
        exit 1
    fi
else
    say "خطا: --purge فقط در حالت تعاملی (Terminal) قابل اجراست تا تایید صریح گرفته شود."
    exit 1
fi

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

PURGE_REPORT="$(cd "$DEPLOYMENT_APP_PATH" && "$PHP_BIN" artisan tinker --execute='
    $actor = "deployment:uninstall";
    $report = [];

    $server = \App\Models\Server::where("name", getenv("SERVER_NAME"))->first();
    $project = \App\Models\Project::where("project_key", getenv("PROJECT_KEY"))->first();
    $agent = $server ? \App\Models\Agent::where("server_id", $server->id)->orderByRaw("CASE WHEN status = \"approved\" THEN 0 ELSE 1 END")->first() : null;
    $environment = $project ? \App\Models\Environment::where("project_id", $project->id)->where("key", getenv("ENVIRONMENT_KEY"))->first() : null;
    $target = $environment ? \App\Models\DeploymentTarget::where("environment_id", $environment->id)->where("name", getenv("TARGET_NAME"))->first() : null;

    if ($target === null) {
        $report[] = "Deployment Target: از قبل موجود نبود.";
    } elseif ($target->deploymentTasks()->exists()) {
        $report[] = "Deployment Target: حذف نشد — تاریخچه Deploy دارد.";
    } else {
        \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "deployment_target.deleted", subjectType: \App\Models\DeploymentTarget::class, subjectId: $target->id);
        $target->delete();
        $report[] = "Deployment Target: حذف شد.";
    }

    if ($agent === null) {
        $report[] = "Agent: از قبل موجود نبود.";
    } else {
        if ($agent->status === \App\Models\Agent::STATUS_APPROVED) {
            $agent->update(["status" => \App\Models\Agent::STATUS_DISABLED]);
            \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "agent.disabled", subjectType: \App\Models\Agent::class, subjectId: $agent->id);
        }
        if ($agent->agentJobs()->exists()) {
            $report[] = "Agent: فقط غیرفعال شد — تاریخچه Job دارد و طبق قوانین پنل قابل حذف نیست.";
        } else {
            \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "agent.deleted", subjectType: \App\Models\Agent::class, subjectId: $agent->id, metadata: ["server_id" => $agent->server_id]);
            $agent->delete();
            $report[] = "Agent: حذف شد.";
        }
    }

    if ($environment === null) {
        $report[] = "Environment: از قبل موجود نبود.";
    } elseif ($environment->status === \App\Models\Environment::STATUS_ACTIVE) {
        $environment->update(["status" => \App\Models\Environment::STATUS_DISABLED]);
        \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "environment.status_changed", subjectType: \App\Models\Environment::class, subjectId: $environment->id, metadata: ["status" => "disabled"]);
        $report[] = "Environment: غیرفعال شد (پنل امکان حذف کامل Environment را ندارد، فقط غیرفعال‌سازی).";
    } else {
        $report[] = "Environment: از قبل غیرفعال بود.";
    }

    if ($project === null) {
        $report[] = "Project: از قبل موجود نبود.";
    } elseif ($project->deployments()->exists() || $project->environments()->exists()) {
        $report[] = "Project: حذف نشد — همچنان Deployment یا Environment وابسته دارد (چون Environment قابل حذف کامل نیست، Project هم قابل حذف کامل نخواهد بود مگر آن‌را دستی از دیتابیس حذف کنید).";
    } else {
        \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "project.deleted", subjectType: \App\Models\Project::class, subjectId: $project->id);
        $project->delete();
        $report[] = "Project: حذف شد.";
    }

    if ($server === null) {
        $report[] = "Server: از قبل موجود نبود.";
    } elseif ($server->projects()->exists() || $server->deployments()->exists() || $server->agents()->exists() || $server->deploymentTargets()->exists()) {
        $report[] = "Server: حذف نشد — همچنان Project/Agent/Deployment Target وابسته دارد.";
    } else {
        \App\Models\AuditLogEntry::record(actorType: \App\Models\AuditLogEntry::ACTOR_SYSTEM, actorIdentifier: $actor, action: "server.deleted", subjectType: \App\Models\Server::class, subjectId: $server->id);
        $server->delete();
        $report[] = "Server: حذف شد.";
    }

    foreach ($report as $line) {
        echo $line . PHP_EOL;
    }
' 2>&1)"

say ""
say "$PURGE_REPORT"
say ""
say "=== پایان --purge ==="
