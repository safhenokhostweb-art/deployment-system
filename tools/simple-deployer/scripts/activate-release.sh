#!/usr/bin/env bash
#
# activate-release.sh — runs ON the target VPS (via `ssh ... bash activate-release.sh ...`),
# never on Windows. Extracts an already-uploaded, checksum-verified
# artifact and atomically activates it, WITHOUT ever running `composer
# install`/`npm ci` on production (the build already happened, isolated,
# on the developer's own machine — see SimpleDeploy.Build.ps1).
#
# Two activation modes, selected with --activation-mode:
#   working_directory  — the CURRENT, real production layout for every
#                         project this tool targets today (confirmed by
#                         auditing each project's own DEPLOY.md/
#                         DEPLOYMENT.md — none of them use a releases/
#                         current symlink layout yet). --project-path IS
#                         the live directory nginx/php-fpm already points
#                         at; activation replaces it via a rename-based
#                         swap (current -> timestamped backup, new
#                         release -> current), matching this project's
#                         own PHP ArtifactManager's proven two-step
#                         rename pattern.
#   releases_symlink    — the standard releases/<ts>-<sha> + `current`
#                         symlink layout, offered for a project once (or
#                         if) its web server config is updated to point
#                         through `current` instead of a flat directory.
#
# Safety:
#   - set -Eeuo pipefail: any unset variable, any failing command (even
#     inside a pipeline), or any error trap aborts the script immediately
#     — no step ever silently continues past a failure.
#   - checksum verified BEFORE extraction.
#   - ZIP-Slip / path-traversal guard: every entry name in the archive is
#     inspected BEFORE `unzip` ever runs — any entry starting with "/",
#     containing "..", or looking like a Windows drive letter aborts the
#     whole activation.
#   - NEVER runs `composer install`/`npm ci` — the artifact already
#     contains vendor/ and public/build/, built off-server.
#   - a failure at any point AFTER the swap (migrate, health check)
#     triggers an automatic rollback to the exact previous, still-intact
#     release.

set -Eeuo pipefail

PROJECT_PATH=""
ARCHIVE_PATH=""
EXPECTED_CHECKSUM=""
COMMIT_SHA=""
ACTIVATION_MODE="working_directory"
PRESERVE_PATHS=".env,storage,public/storage"
RETENTION_COUNT=3
RUN_MIGRATIONS="false"
PHP_BINARY="php"
HEALTH_CHECK_URL=""
DATABASE_BACKUP="false"

log() { printf '[activate-release] %s\n' "$1"; }
fail() { printf '[activate-release] خطا: %s\n' "$1" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --project-path) PROJECT_PATH="$2"; shift 2 ;;
        --uploaded-archive) ARCHIVE_PATH="$2"; shift 2 ;;
        --checksum) EXPECTED_CHECKSUM="$2"; shift 2 ;;
        --commit-sha) COMMIT_SHA="$2"; shift 2 ;;
        --activation-mode) ACTIVATION_MODE="$2"; shift 2 ;;
        --preserve-paths) PRESERVE_PATHS="$2"; shift 2 ;;
        --retention-count) RETENTION_COUNT="$2"; shift 2 ;;
        --run-migrations) RUN_MIGRATIONS="$2"; shift 2 ;;
        --php-binary) PHP_BINARY="$2"; shift 2 ;;
        --health-check-url) HEALTH_CHECK_URL="$2"; shift 2 ;;
        --database-backup) DATABASE_BACKUP="$2"; shift 2 ;;
        *) fail "آرگومان ناشناخته: $1" ;;
    esac
done

[ -n "$PROJECT_PATH" ] || fail "--project-path الزامی است."
[ -n "$ARCHIVE_PATH" ] || fail "--uploaded-archive الزامی است."
[ -n "$EXPECTED_CHECKSUM" ] || fail "--checksum الزامی است."
[ -n "$COMMIT_SHA" ] || fail "--commit-sha الزامی است."
[ "$ACTIVATION_MODE" = "working_directory" ] || [ "$ACTIVATION_MODE" = "releases_symlink" ] || fail "activation-mode نامعتبر: $ACTIVATION_MODE"
[ -f "$ARCHIVE_PATH" ] || fail "فایل Artifact پیدا نشد: $ARCHIVE_PATH"

command -v unzip >/dev/null 2>&1 || fail "دستور unzip روی این سرور پیدا نشد."
command -v sha256sum >/dev/null 2>&1 || fail "دستور sha256sum روی این سرور پیدا نشد."

log "بررسی checksum..."
ACTUAL_CHECKSUM="$(sha256sum "$ARCHIVE_PATH" | awk '{print $1}')"
if [ "$ACTUAL_CHECKSUM" != "$EXPECTED_CHECKSUM" ]; then
    fail "عدم تطابق checksum: انتظار $EXPECTED_CHECKSUM، دریافت $ACTUAL_CHECKSUM — احتمال خرابی یا دستکاری فایل در انتقال."
fi
log "checksum تأیید شد."

log "بررسی امنیتی محتویات Artifact (ZIP-Slip / path traversal)..."
while IFS= read -r entry; do
    case "$entry" in
        /*) fail "ورودی ناامن در Artifact (مسیر مطلق): $entry" ;;
        *..*) fail "ورودی ناامن در Artifact (path traversal): $entry" ;;
        [A-Za-z]:*) fail "ورودی ناامن در Artifact (مسیر درایو ویندوز): $entry" ;;
    esac
done < <(unzip -Z1 "$ARCHIVE_PATH")
log "محتویات Artifact امن تأیید شد."

TIMESTAMP="$(date -u +%Y%m%d%H%M%S)"
SHORT_SHA="${COMMIT_SHA:0:12}"
RELEASE_ID="${TIMESTAMP}-${SHORT_SHA}"

PROJECT_ROOT="$(dirname "$PROJECT_PATH")"
STAGING_DIR="${PROJECT_PATH%/}.staging-${RELEASE_ID}"

rollback_performed=0
release_activated=0

cleanup_staging() {
    if [ -d "$STAGING_DIR" ]; then
        rm -rf -- "$STAGING_DIR"
    fi
}
trap cleanup_staging EXIT

log "استخراج release جدید به مسیر موقت: $STAGING_DIR"
rm -rf -- "$STAGING_DIR"
mkdir -p -- "$STAGING_DIR"
unzip -q "$ARCHIVE_PATH" -d "$STAGING_DIR"

# ---- Determine the CURRENTLY LIVE directory to preserve paths from ----
if [ "$ACTIVATION_MODE" = "releases_symlink" ]; then
    CURRENT_LINK="${PROJECT_ROOT}/current"
    if [ -L "$CURRENT_LINK" ]; then
        LIVE_DIR="$(readlink -f "$CURRENT_LINK")"
    else
        LIVE_DIR=""
    fi
else
    LIVE_DIR="$PROJECT_PATH"
fi

if [ -n "$LIVE_DIR" ] && [ -d "$LIVE_DIR" ]; then
    log "کپی مسیرهای preserve از نسخه فعلی ($LIVE_DIR)..."
    IFS=',' read -ra PRESERVE_ARRAY <<< "$PRESERVE_PATHS"
    for relative_path in "${PRESERVE_ARRAY[@]}"; do
        relative_path="$(echo "$relative_path" | xargs)"
        [ -n "$relative_path" ] || continue
        source_path="${LIVE_DIR}/${relative_path}"
        if [ -e "$source_path" ]; then
            dest_path="${STAGING_DIR}/${relative_path}"
            mkdir -p -- "$(dirname "$dest_path")"
            rm -rf -- "$dest_path"
            cp -a -- "$source_path" "$dest_path"
            log "  حفظ شد: $relative_path"
        fi
    done
else
    log "نسخه فعلی برای این پروژه پیدا نشد — این احتمالاً اولین Deploy است؛ مسیرهای preserve کپی نشدند."
fi

# vendor/ and public/build/ always come from the NEW artifact — never
# preserved from the live directory, exactly like the existing PHP
# ArtifactManager already guarantees for artifact-based deploys.

# ---- Database backup (optional, before migrate) ----
if [ "$DATABASE_BACKUP" = "true" ] && [ -f "${STAGING_DIR}/.env" ]; then
    DB_CONNECTION="$(grep -E '^DB_CONNECTION=' "${STAGING_DIR}/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
    if [ "$DB_CONNECTION" = "mysql" ] || [ "$DB_CONNECTION" = "mariadb" ]; then
        DB_DATABASE="$(grep -E '^DB_DATABASE=' "${STAGING_DIR}/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
        DB_USERNAME="$(grep -E '^DB_USERNAME=' "${STAGING_DIR}/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
        DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "${STAGING_DIR}/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
        DB_HOST="$(grep -E '^DB_HOST=' "${STAGING_DIR}/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
        [ -n "$DB_HOST" ] || DB_HOST="127.0.0.1"
        BACKUP_PATH="${PROJECT_ROOT}/db-backups"
        mkdir -p -- "$BACKUP_PATH"
        if command -v mysqldump >/dev/null 2>&1 && [ -n "$DB_DATABASE" ]; then
            log "پشتیبان‌گیری از دیتابیس قبل از migrate..."
            MYSQL_PWD="$DB_PASSWORD" mysqldump -h "$DB_HOST" -u "$DB_USERNAME" "$DB_DATABASE" > "${BACKUP_PATH}/${RELEASE_ID}.sql" \
                || fail "پشتیبان‌گیری از دیتابیس با خطا مواجه شد — Deploy متوقف شد (database_backup=true بود)."
            log "پشتیبان دیتابیس ذخیره شد: ${BACKUP_PATH}/${RELEASE_ID}.sql"
        else
            log "هشدار: mysqldump پیدا نشد یا DB_DATABASE خالی است — پشتیبان‌گیری دیتابیس رد شد."
        fi
    else
        log "database_backup=true اما DB_CONNECTION ('$DB_CONNECTION') پشتیبانی‌شده نیست (فقط mysql/mariadb) — رد شد."
    fi
fi

# ---- Atomic swap ----
BACKUP_DIR=""
PREVIOUS_RELEASE_DIR=""

perform_swap() {
    if [ "$ACTIVATION_MODE" = "releases_symlink" ]; then
        RELEASES_DIR="${PROJECT_ROOT}/releases"
        mkdir -p -- "$RELEASES_DIR"
        RELEASE_DIR="${RELEASES_DIR}/${RELEASE_ID}"
        mv -T -- "$STAGING_DIR" "$RELEASE_DIR"

        CURRENT_LINK="${PROJECT_ROOT}/current"
        if [ -L "$CURRENT_LINK" ]; then
            PREVIOUS_RELEASE_DIR="$(readlink -f "$CURRENT_LINK")"
        fi

        TMP_LINK="${PROJECT_ROOT}/current.tmp-${RELEASE_ID}"
        ln -s -- "$RELEASE_DIR" "$TMP_LINK"
        mv -T -- "$TMP_LINK" "$CURRENT_LINK"
        LIVE_TARGET="$RELEASE_DIR"
    else
        if [ -d "$PROJECT_PATH" ]; then
            BACKUP_DIR="${PROJECT_PATH%/}.backup-${RELEASE_ID}"
            mv -T -- "$PROJECT_PATH" "$BACKUP_DIR"
        fi
        mv -T -- "$STAGING_DIR" "$PROJECT_PATH"
        LIVE_TARGET="$PROJECT_PATH"
    fi
}

perform_rollback() {
    log "در حال rollback به نسخه قبلی..."
    if [ "$ACTIVATION_MODE" = "releases_symlink" ]; then
        if [ -n "$PREVIOUS_RELEASE_DIR" ] && [ -d "$PREVIOUS_RELEASE_DIR" ]; then
            TMP_LINK="${PROJECT_ROOT}/current.rollback-${RELEASE_ID}"
            ln -s -- "$PREVIOUS_RELEASE_DIR" "$TMP_LINK"
            mv -T -- "$TMP_LINK" "${PROJECT_ROOT}/current"
            log "symlink «current» به نسخه قبلی ($PREVIOUS_RELEASE_DIR) بازگردانده شد."
        else
            log "هشدار: نسخه قبلی برای rollback پیدا نشد — symlink «current» دست‌نخورده باقی می‌ماند (اولین Deploy بود)."
        fi
    else
        if [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
            rm -rf -- "${PROJECT_PATH}.failed-${RELEASE_ID}"
            [ -d "$PROJECT_PATH" ] && mv -T -- "$PROJECT_PATH" "${PROJECT_PATH}.failed-${RELEASE_ID}"
            mv -T -- "$BACKUP_DIR" "$PROJECT_PATH"
            log "نسخه قبلی از «$BACKUP_DIR» بازگردانده شد."
        else
            log "هشدار: نسخه قبلی برای rollback پیدا نشد (اولین Deploy بود) — چیزی برای بازگردانی وجود ندارد."
        fi
    fi
    rollback_performed=1
}

log "در حال فعال‌سازی release جدید (swap اتمیک، حالت: $ACTIVATION_MODE)..."
perform_swap
release_activated=1
log "release جدید فعال شد: $LIVE_TARGET"

post_swap_failure() {
    log "خطا پس از فعال‌سازی — شروع rollback خودکار."
    perform_rollback
    fail "Deploy با خطا مواجه شد و به نسخه قبلی بازگردانده شد — به لاگ‌های بالا نگاه کنید."
}

# From this point on, ANY failure triggers an automatic rollback — set -e
# alone would just exit; this trap makes that exit perform the rollback
# first. Cleared again right before the function returns successfully.
trap post_swap_failure ERR

if [ -f "${LIVE_TARGET}/artisan" ]; then
    if [ "$RUN_MIGRATIONS" = "true" ]; then
        log "اجرای migrate --force..."
        (cd "$LIVE_TARGET" && "$PHP_BINARY" artisan migrate --force)
    else
        log "run_migrations=false — از migrate صرف‌نظر شد."
    fi

    log "پاک‌سازی و بازسازی cacheها (package discovery در همین مرحله انجام می‌شود)..."
    (cd "$LIVE_TARGET" && "$PHP_BINARY" artisan optimize:clear)
    (cd "$LIVE_TARGET" && "$PHP_BINARY" artisan optimize)

    log "queue:restart..."
    (cd "$LIVE_TARGET" && "$PHP_BINARY" artisan queue:restart)
else
    log "artisan در release جدید پیدا نشد — این پروژه Laravel نیست یا build کامل نبود؛ مراحل مخصوص Laravel رد شد."
fi

log "اصلاح permissionها..."
if [ -d "${LIVE_TARGET}/storage" ]; then
    chmod -R ug+rwX -- "${LIVE_TARGET}/storage" 2>/dev/null || log "هشدار: تنظیم permission روی storage/ کامل موفق نبود."
fi
if [ -d "${LIVE_TARGET}/bootstrap/cache" ]; then
    chmod -R ug+rwX -- "${LIVE_TARGET}/bootstrap/cache" 2>/dev/null || log "هشدار: تنظیم permission روی bootstrap/cache کامل موفق نبود."
fi

if [ -n "$HEALTH_CHECK_URL" ]; then
    log "اجرای health check: $HEALTH_CHECK_URL"
    if command -v curl >/dev/null 2>&1; then
        if ! curl -fsS --max-time 15 -o /dev/null "$HEALTH_CHECK_URL"; then
            # NOT `fail` here: fail() calls `exit`, and bash's ERR trap
            # (post_swap_failure, set above) does NOT fire for an explicit
            # exit — only for a plain command that returns non-zero on its
            # own. Confirmed empirically: this previously left the broken
            # release live with no rollback on a failed health check, even
            # though the very next log line claims one happened. Calling
            # post_swap_failure directly guarantees the same rollback the
            # migrate-step failure path already gets for free from set -e.
            log "health check موفق نبود (curl به $HEALTH_CHECK_URL دسترسی نداشت یا خطای HTTP دریافت کرد)."
            post_swap_failure
        fi
        log "health check موفق بود."
    else
        log "هشدار: curl روی این سرور پیدا نشد — health check رد شد."
    fi
else
    log "health_check_url تنظیم نشده — health check رد شد."
fi

# Success — cancel the rollback-on-error trap before retention cleanup,
# which is a housekeeping step and must never itself trigger a rollback
# of an already-successful deploy.
trap - ERR

log "نگهداری release/backup: حداقل $RETENTION_COUNT مورد قبلی حفظ می‌شود..."
if [ "$ACTIVATION_MODE" = "releases_symlink" ]; then
    RELEASES_DIR="${PROJECT_ROOT}/releases"
    if [ -d "$RELEASES_DIR" ]; then
        # shellcheck disable=SC2012
        ls -1dt "${RELEASES_DIR}"/*/ 2>/dev/null | tail -n +"$((RETENTION_COUNT + 1))" | while IFS= read -r old_release; do
            log "  حذف release قدیمی: $old_release"
            rm -rf -- "$old_release"
        done
    fi
else
    # shellcheck disable=SC2012
    ls -1dt "${PROJECT_PATH}".backup-*/ 2>/dev/null | tail -n +"$((RETENTION_COUNT + 1))" | while IFS= read -r old_backup; do
        log "  حذف backup قدیمی: $old_backup"
        rm -rf -- "$old_backup"
    done
fi

log "Deploy با موفقیت کامل شد. commit فعال: $COMMIT_SHA"
exit 0
