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

printf '\nLinux upgrader: %d passed, %d failed\n' "$passed" "$failed"
test "$failed" -eq 0
