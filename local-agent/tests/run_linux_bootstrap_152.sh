#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
BOOTSTRAP="$ROOT/deployment-local-agent/bootstrap-1.5.2-linux.sh"
TMP_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TMP_ROOT"' EXIT
SEED="$TMP_ROOT/seed"
REMOTE="$TMP_ROOT/remote.git"
WORKSPACE="$TMP_ROOT/workspace"
INSTALL="$TMP_ROOT/install"
mkdir -p "$SEED/deployment-local-agent" "$INSTALL/lib" "$INSTALL/storage/cache"
cp -R "$ROOT/deployment-local-agent/." "$SEED/deployment-local-agent/"
git -C "$SEED" init -q --initial-branch=master
git -C "$SEED" config user.email bootstrap@example.test
git -C "$SEED" config user.name 'Bootstrap Test'
git -C "$SEED" add deployment-local-agent
git -C "$SEED" commit -qm 'official 1.5.2 package'
COMMIT="$(git -C "$SEED" rev-parse HEAD)"
git clone -q --bare "$SEED" "$REMOTE"
git clone -q "$REMOTE" "$WORKSPACE"
APPROVED_REPOSITORY="$(git -C "$WORKSPACE" remote get-url origin)"

printf "%s\n" "<?php const LOCAL_AGENT_VERSION = '1.5.1';" >"$INSTALL/agent.php"
printf '%s\n' '<?php return true;' >"$INSTALL/heartbeat-watchdog.php"
printf '%s\n' '<?php final class HistoricalRuntime {}' >"$INSTALL/lib/HistoricalRuntime.php"
printf '%s\n' '<?php return ["token" => "preserved"];' >"$INSTALL/config.php"
CONFIG_HASH="$(sha256sum "$INSTALL/config.php" | awk '{print $1}')"

if bash "$BOOTSTRAP" --workspace "$WORKSPACE" --expected-repository "$APPROVED_REPOSITORY" --branch master --expected-commit "$COMMIT" --expected-version 1.5.3 --install-dir "$INSTALL" --agent-managed-restart >/dev/null 2>&1; then
    echo 'FAIL: arbitrary bootstrap target version was accepted' >&2
    exit 1
fi
if bash "$BOOTSTRAP" --workspace "$WORKSPACE" --expected-repository "$SEED" --branch master --expected-commit "$COMMIT" --install-dir "$INSTALL" --agent-managed-restart >/dev/null 2>&1; then
    echo 'FAIL: repository identity mismatch was accepted' >&2
    exit 1
fi
if bash "$BOOTSTRAP" --workspace "$WORKSPACE" --expected-repository "$APPROVED_REPOSITORY" --branch master --expected-commit "$(printf 'f%.0s' {1..40})" --install-dir "$INSTALL" --agent-managed-restart >/dev/null 2>&1; then
    echo 'FAIL: unavailable exact commit was accepted' >&2
    exit 1
fi
if bash "$BOOTSTRAP" --workspace "$WORKSPACE" --expected-repository "$APPROVED_REPOSITORY" --branch unrelated --expected-commit "$COMMIT" --install-dir "$INSTALL" --agent-managed-restart >/dev/null 2>&1; then
    echo 'FAIL: commit outside the requested branch was accepted' >&2
    exit 1
fi

# The persistent workspace is transport only. Its mutable contents must not
# influence the package materialized from the approved remote exact commit.
printf "%s\n" "<?php const LOCAL_AGENT_VERSION = '9.9.9';" >"$WORKSPACE/deployment-local-agent/agent/agent.php"

output="$(
    LOCAL_AGENT_UPGRADER_TEST_MODE=1 \
    LOCAL_AGENT_UPGRADER_PHP_BIN="${LOCAL_AGENT_TEST_PHP_BIN:-php}" \
    LOCAL_AGENT_UPGRADER_BACKUP_ROOT="$INSTALL/storage/upgrades/backups" \
    bash "$BOOTSTRAP" \
        --workspace "$WORKSPACE" \
        --expected-repository "$APPROVED_REPOSITORY" \
        --remote origin \
        --branch master \
        --expected-commit "$COMMIT" \
        --expected-version 1.5.2 \
        --install-dir "$INSTALL" \
        --agent-managed-restart
)"
grep -q "BOOTSTRAP_PACKAGE_OK version=1.5.2 source_commit=$COMMIT" <<<"$output"
grep -q 'UPGRADE_OK old_version=1.5.1 new_version=1.5.2' <<<"$output"
grep -q "LOCAL_AGENT_VERSION = '1.5.2'" "$INSTALL/agent.php"
test -f "$INSTALL/lib/LinuxUpgradePackageMaterializer.php"
test "$CONFIG_HASH" = "$(sha256sum "$INSTALL/config.php" | awk '{print $1}')"
grep -q "LOCAL_AGENT_VERSION = '9.9.9'" "$WORKSPACE/deployment-local-agent/agent/agent.php"
! grep -Eq '(/api/|curl|wget)' "$BOOTSTRAP"

if bash "$BOOTSTRAP" --workspace "$WORKSPACE" --expected-repository "$APPROVED_REPOSITORY" --branch master --expected-commit "$COMMIT" --expected-version 1.5.2 --install-dir "$INSTALL" --agent-managed-restart >/dev/null 2>&1; then
    echo 'FAIL: historical bootstrap was reused from an installed 1.5.2 runtime' >&2
    exit 1
fi

echo 'Linux 1.5.2 exact-commit bootstrap: 12 passed, 0 failed'
