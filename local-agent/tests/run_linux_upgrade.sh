#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
UPGRADER="${ROOT}/deployment-local-agent/upgrade-linux.sh"
TMP_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TMP_ROOT"' EXIT

passed=0
failed=0
check() {
    local name="$1"
    shift
    if "$@"; then
        printf 'PASS: %s\n' "$name"
        passed=$((passed + 1))
    else
        printf 'FAIL: %s\n' "$name"
        failed=$((failed + 1))
    fi
}

make_runtime() {
    local root="$1" version="$2"
    mkdir -p "$root/lib" "$root/storage/cache"
    printf "<?php const LOCAL_AGENT_VERSION = '%s';\n" "$version" >"$root/agent.php"
    printf '%s\n' '<?php return true;' >"$root/heartbeat-watchdog.php"
    printf '%s\n' '<?php final class RuntimeLibrary {}' >"$root/lib/RuntimeLibrary.php"
}

make_source() {
    local root="$1" version="$2"
    mkdir -p "$root/lib"
    printf "<?php const LOCAL_AGENT_VERSION = '%s';\n" "$version" >"$root/agent.php"
    printf '%s\n' '<?php return true;' >"$root/heartbeat-watchdog.php"
    printf '%s\n' '<?php final class UpgradedRuntimeLibrary {}' >"$root/lib/RuntimeLibrary.php"
    printf '%s\n' '<?php final class NewRuntimeLibrary {}' >"$root/lib/NewRuntimeLibrary.php"
}

run_upgrade() {
    local install_dir="$1" source_dir="$2"
    shift 2
    LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$install_dir/storage/upgrades/backups" \
    bash "$UPGRADER" --source-dir "$source_dir" --install-dir "$install_dir" --agent-managed-restart --expected-version 1.4.4 "$@"
}

# Successful activation preserves every non-runtime byte and reports versions.
success_install="$TMP_ROOT/success-install"
success_source="$TMP_ROOT/success-source"
make_runtime "$success_install" 1.4.3
make_source "$success_source" 1.4.4
printf '%s\n' '<?php return ["agent_token" => "unchanged-token", "signing_secret" => "unchanged-secret"];' >"$success_install/config.php"
printf '%s\n' '{"projects":{"deployment-system":{"local_path":"/safe/workspace"}}}' >"$success_install/storage/cache/projects.json"
config_before="$(sha256sum "$success_install/config.php" | awk '{print $1}')"
cache_before="$(sha256sum "$success_install/storage/cache/projects.json" | awk '{print $1}')"
success_output="$(run_upgrade "$success_install" "$success_source")"
check 'successful upgrade reports 1.4.3 -> 1.4.4' grep -q 'UPGRADE_OK old_version=1.4.3 new_version=1.4.4' <<<"$success_output"
check 'successful upgrade activates the new version' grep -q "LOCAL_AGENT_VERSION = '1.4.4'" "$success_install/agent.php"
check 'config.php is byte-for-byte preserved' test "$config_before" = "$(sha256sum "$success_install/config.php" | awk '{print $1}')"
check 'project cache is byte-for-byte preserved' test "$cache_before" = "$(sha256sum "$success_install/storage/cache/projects.json" | awk '{print $1}')"
check 'new lib files are activated' test -f "$success_install/lib/NewRuntimeLibrary.php"
check 'timestamped backup stores the old version' grep -R -q 'old_version=1.4.3' "$success_install/storage/upgrades/backups"

# Version ordering is enforced by the activation boundary itself, not only
# by the Hub queue policy. A stale/replayed package can never downgrade an
# already newer runtime.
downgrade_install="$TMP_ROOT/downgrade-install"
downgrade_source="$TMP_ROOT/downgrade-source"
make_runtime "$downgrade_install" 1.5.4
make_source "$downgrade_source" 1.5.3
printf '%s\n' '<?php return ["token" => "downgrade-preserve"];' >"$downgrade_install/config.php"
downgrade_agent_before="$(sha256sum "$downgrade_install/agent.php" | awk '{print $1}')"
if LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$downgrade_install/storage/upgrades/backups" \
    bash "$UPGRADER" --source-dir "$downgrade_source" --install-dir "$downgrade_install" --agent-managed-restart --expected-version 1.5.3 >/dev/null 2>&1; then
    downgrade_rejected=1
else
    downgrade_rejected=0
fi
check 'Linux upgrader rejects a 1.5.4 to 1.5.3 downgrade' test "$downgrade_rejected" -eq 0
check 'rejected downgrade leaves active runtime unchanged' test "$downgrade_agent_before" = "$(sha256sum "$downgrade_install/agent.php" | awk '{print $1}')"

# Invalid PHP must fail before any active file changes.
syntax_install="$TMP_ROOT/syntax-install"
syntax_source="$TMP_ROOT/syntax-source"
make_runtime "$syntax_install" 1.4.3
make_source "$syntax_source" 1.4.4
printf '%s\n' '<?php return ["token" => "preserve"];' >"$syntax_install/config.php"
printf '%s\n' '<?php this is not valid PHP' >"$syntax_source/lib/RuntimeLibrary.php"
syntax_before="$(sha256sum "$syntax_install/agent.php" | awk '{print $1}')"
if run_upgrade "$syntax_install" "$syntax_source" >/dev/null 2>&1; then syntax_failed=1; else syntax_failed=0; fi
check 'syntax validation failure rejects the upgrade' test "$syntax_failed" -eq 0
check 'syntax failure leaves active runtime unchanged' test "$syntax_before" = "$(sha256sum "$syntax_install/agent.php" | awk '{print $1}')"

# A failure after activation must restore the entire prior runtime and config.
rollback_install="$TMP_ROOT/rollback-install"
rollback_source="$TMP_ROOT/rollback-source"
make_runtime "$rollback_install" 1.4.3
make_source "$rollback_source" 1.4.4
printf '%s\n' '<?php return ["token" => "rollback-preserve"];' >"$rollback_install/config.php"
rollback_agent_before="$(sha256sum "$rollback_install/agent.php" | awk '{print $1}')"
rollback_lib_before="$(sha256sum "$rollback_install/lib/RuntimeLibrary.php" | awk '{print $1}')"
rollback_config_before="$(sha256sum "$rollback_install/config.php" | awk '{print $1}')"
if LOCAL_AGENT_UPGRADER_FAIL_AFTER_ACTIVATION=1 run_upgrade "$rollback_install" "$rollback_source" >/dev/null 2>&1; then rollback_failed=1; else rollback_failed=0; fi
check 'post-activation failure is reported' test "$rollback_failed" -eq 0
check 'rollback restores old agent.php' test "$rollback_agent_before" = "$(sha256sum "$rollback_install/agent.php" | awk '{print $1}')"
check 'rollback restores old lib contents' test "$rollback_lib_before" = "$(sha256sum "$rollback_install/lib/RuntimeLibrary.php" | awk '{print $1}')"
check 'rollback removes newly introduced lib files' test ! -e "$rollback_install/lib/NewRuntimeLibrary.php"
check 'rollback preserves config.php' test "$rollback_config_before" = "$(sha256sum "$rollback_install/config.php" | awk '{print $1}')"

# A failed operator-managed service restart must also restore the old runtime.
restart_install="$TMP_ROOT/restart-install"
restart_source="$TMP_ROOT/restart-source"
fake_systemctl="$TMP_ROOT/fake-systemctl"
restart_state="$TMP_ROOT/restart-state"
make_runtime "$restart_install" 1.4.3
make_source "$restart_source" 1.4.4
printf '%s\n' '<?php return ["token" => "restart-preserve"];' >"$restart_install/config.php"
restart_agent_before="$(sha256sum "$restart_install/agent.php" | awk '{print $1}')"
printf '%s\n' \
    '#!/usr/bin/env bash' \
    'case "$1" in' \
    '    is-active|stop) exit 0 ;;' \
    '    restart)' \
    '        if [[ ! -e "$FAKE_SYSTEMCTL_STATE" ]]; then' \
    '            : >"$FAKE_SYSTEMCTL_STATE"' \
    '            exit 1' \
    '        fi' \
    '        exit 0' \
    '        ;;' \
    '    *) exit 1 ;;' \
    'esac' >"$fake_systemctl"
chmod +x "$fake_systemctl"
if LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$restart_install/storage/upgrades/backups" \
    LOCAL_AGENT_UPGRADER_SYSTEMCTL_BIN="$fake_systemctl" \
    FAKE_SYSTEMCTL_STATE="$restart_state" \
    bash "$UPGRADER" --source-dir "$restart_source" --install-dir "$restart_install" --expected-version 1.4.4 >/dev/null 2>&1; then
    restart_failed=1
else
    restart_failed=0
fi
check 'service restart failure rejects the upgrade' test "$restart_failed" -eq 0
check 'service restart failure rolls back the active runtime' test "$restart_agent_before" = "$(sha256sum "$restart_install/agent.php" | awk '{print $1}')"
check 'rollback attempts to restart the restored runtime' test -f "$restart_state"

# Explicit one-time migration boundary. The 1.5.1 Runner can activate this
# package once its exact source is made available; 1.5.2+ uses the new
# exact-commit materializer for every subsequent Dashboard upgrade.
bootstrap_install="$TMP_ROOT/bootstrap-1.5.2-install"
bootstrap_source="$TMP_ROOT/bootstrap-1.5.2-source"
make_runtime "$bootstrap_install" 1.5.1
make_source "$bootstrap_source" 1.5.2
printf '%s\n' '<?php final class LinuxUpgradePackageMaterializer {}' >"$bootstrap_source/lib/LinuxUpgradePackageMaterializer.php"
printf '%s\n' '<?php return ["token" => "bootstrap-preserve"];' >"$bootstrap_install/config.php"
bootstrap_output="$(
    LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$bootstrap_install/storage/upgrades/backups" \
    bash "$UPGRADER" \
        --source-dir "$bootstrap_source" \
        --install-dir "$bootstrap_install" \
        --agent-managed-restart \
        --expected-version 1.5.2
)"
check 'historical 1.5.1 runtime can bootstrap through the official 1.5.2 package' grep -q 'UPGRADE_OK old_version=1.5.1 new_version=1.5.2' <<<"$bootstrap_output"
check 'bootstrap activates canonical Agent 1.5.2' grep -q "LOCAL_AGENT_VERSION = '1.5.2'" "$bootstrap_install/agent.php"
check 'bootstrap package includes exact-commit upgrade materialization' test -f "$bootstrap_install/lib/LinuxUpgradePackageMaterializer.php"

# Normal post-bootstrap delivery: an installed 1.5.5 runtime activates the
# current official 1.5.6 package through upgrade-linux.sh, with no bootstrap
# script, manual copy, downgrade, or mutable-workspace authority involved.
normal_install="$TMP_ROOT/normal-1.5.6-install"
make_runtime "$normal_install" 1.5.5
printf '%s\n' '<?php return ["token" => "normal-upgrade-preserve"];' >"$normal_install/config.php"
normal_config_before="$(sha256sum "$normal_install/config.php" | awk '{print $1}')"
normal_output="$(
    LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$normal_install/storage/upgrades/backups" \
    bash "$UPGRADER" \
        --source-dir "$ROOT/deployment-local-agent/agent" \
        --install-dir "$normal_install" \
        --agent-managed-restart \
        --expected-version 1.5.6
)"
check 'installed 1.5.5 normally upgrades to official 1.5.6' grep -q 'UPGRADE_OK old_version=1.5.5 new_version=1.5.6' <<<"$normal_output"
check 'normal upgrade activates canonical Agent 1.5.6' grep -q "LOCAL_AGENT_VERSION = '1.5.6'" "$normal_install/agent.php"
check 'normal 1.5.5 to 1.5.6 upgrade preserves config.php' test "$normal_config_before" = "$(sha256sum "$normal_install/config.php" | awk '{print $1}')"

printf '\nLinux upgrader: %d passed, %d failed\n' "$passed" "$failed"
test "$failed" -eq 0
