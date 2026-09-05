#!/usr/bin/env bash
# Real, non-mocked test for deployment-server-agent-activate — invoked
# directly (never through sudo, since no real root/sudo boundary exists
# on a dev/CI machine) so its own validation, atomic-swap, and
# rollback-on-failure logic are proven for real. SelfUpgradeManager's
# own unprivileged side (download/checksum/extract/validate/lint) is
# covered separately by run_self_upgrade.php; this file is the other
# half of that root-cause fix's proof (Control Job #1 — "Could not
# create the Server Agent upgrade staging directory").
#
# Permission-enforcement scenarios (rollback triggered by a real
# EACCES) are skipped cleanly, not failed, on environments where POSIX
# mode bits aren't actually enforced against the running user — notably
# Windows/MSYS2 git-bash, where chmod succeeds but does not restrict
# the owning user's own access. Every other scenario here (argument
# validation, name allowlist, symlink/traversal rejection, layout
# validation, the successful atomic swap itself) is pure logic with no
# such dependency and always runs for real.
set -Eeuo pipefail

HELPER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)/install/deployment-server-agent-activate"
ROOT="$(mktemp -d)"
trap 'rm -rf -- "$ROOT"' EXIT

passed=0
failed=0
skipped=0
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
check_not() {
    local name="$1"
    shift
    if ! "$@"; then
        printf 'PASS: %s\n' "$name"
        passed=$((passed + 1))
    else
        printf 'FAIL: %s\n' "$name"
        failed=$((failed + 1))
    fi
}
skip() {
    printf 'SKIP: %s\n' "$1"
    skipped=$((skipped + 1))
}

# Detect whether chmod actually restricts the current (owning) user on
# this filesystem, rather than assuming Linux == enforced.
perm_probe="$ROOT/.perm-probe"
mkdir -p "$perm_probe"
chmod 0500 "$perm_probe"
if touch "$perm_probe/canary" 2>/dev/null; then
    PERMISSIONS_ENFORCED=0
else
    PERMISSIONS_ENFORCED=1
fi
chmod 0755 "$perm_probe"
rm -rf -- "$perm_probe"

make_runtime() {
    local root="$1" version="$2"
    mkdir -p "$root/lib"
    printf "<?php const AGENT_VERSION = '%s';\n" "$version" >"$root/agent.php"
    printf '%s\n' '<?php final class PipelineExecutor {}' >"$root/lib/PipelineExecutor.php"
}

run_helper() {
    SERVER_AGENT_INSTALL_DIR="$INSTALL_DIR" SERVER_AGENT_STAGING_ROOT="$STAGING_ROOT" \
        bash "$HELPER" "$@"
}

# ------------------------------------------------------------------
# Scenario 1: successful activation — backup created, staged runtime
# swapped in atomically, staging directory consumed (moved, not left
# behind).
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s1/opt/deployment-server-agent"
STAGING_ROOT="$ROOT/s1/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$STAGING_ROOT/staging-deadbeef/lib"
make_runtime "$STAGING_ROOT/staging-deadbeef" '1.4.2'

output="$(run_helper staging-deadbeef)"
check 'successful activation reports ACTIVATE_OK' bash -c "printf '%s' '$output' | grep -q '^ACTIVATE_OK '"
check 'the new agent.php is now active' grep -q "AGENT_VERSION = '1.4.2'" "$INSTALL_DIR/agent.php"
check 'exactly one backup directory holds the previous runtime' bash -c "[ \"\$(find '$ROOT/s1/opt' -maxdepth 1 -name 'deployment-server-agent.backup-*' | wc -l)\" = 1 ]"
check 'the backup still has the OLD version' bash -c "grep -q \"AGENT_VERSION = '1.4.1'\" \"\$(find '$ROOT/s1/opt' -maxdepth 1 -name 'deployment-server-agent.backup-*')/agent.php\""
check_not 'the staging directory was consumed, not left behind' test -d "$STAGING_ROOT/staging-deadbeef"

# ------------------------------------------------------------------
# Scenario 2: wrong argument count is rejected (exit 64).
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s2/opt/deployment-server-agent"
STAGING_ROOT="$ROOT/s2/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$STAGING_ROOT"
set +e
run_helper >/dev/null 2>&1
rc=$?
set -e
check 'zero arguments is rejected' bash -c "[ $rc -eq 64 ]"

# ------------------------------------------------------------------
# Scenario 3: an arbitrary/invalid staging name is rejected — proves
# the strict allowlist, not merely "path must exist".
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s3/opt/deployment-server-agent"
STAGING_ROOT="$ROOT/s3/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$STAGING_ROOT/evil"
for bad in 'evil' '../evil' 'staging-' 'staging-XYZ12345' 'staging-../../etc' '/etc/passwd'; do
    set +e
    run_helper "$bad" >/dev/null 2>&1
    rc=$?
    set -e
    check "arbitrary staging name '$bad' is rejected" bash -c "[ $rc -eq 65 ]"
done
check 'the active runtime is untouched after every arbitrary-path attempt' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL_DIR/agent.php"

# ------------------------------------------------------------------
# Scenario 4: a symlinked staging entry is rejected outright, even
# with an otherwise-valid name.
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s4/opt/deployment-server-agent"
STAGING_ROOT="$ROOT/s4/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$STAGING_ROOT" "$ROOT/s4/outside-target/lib"
make_runtime "$ROOT/s4/outside-target" '1.4.2'
ln -s "$ROOT/s4/outside-target" "$STAGING_ROOT/staging-c0ffee01" 2>/dev/null || true
if [ -L "$STAGING_ROOT/staging-c0ffee01" ]; then
    set +e
    run_helper staging-c0ffee01 >/dev/null 2>&1
    rc=$?
    set -e
    check 'a symlinked staging entry is rejected' bash -c "[ $rc -eq 66 ]"
    check 'the active runtime is untouched after a symlink attempt' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL_DIR/agent.php"
else
    skip 'symlinked-staging-entry rejection (this environment does not create real symlinks — e.g. Windows/MSYS2 without Developer Mode)'
    skip 'symlink rejection leaves the active runtime untouched (not applicable — no real symlink created)'
fi

# ------------------------------------------------------------------
# Scenario 5: the staging root itself being a symlink must not be
# trusted as a real boundary (traversal defense via a symlinked
# intermediate component).
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s5/opt/deployment-server-agent"
REAL_STAGING="$ROOT/s5/real-staging-elsewhere"
STAGING_ROOT="$ROOT/s5/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$REAL_STAGING/staging-c0ffeec0/lib"
make_runtime "$REAL_STAGING/staging-c0ffeec0" '1.4.2'
ln -s "$REAL_STAGING" "$STAGING_ROOT" 2>/dev/null || true
if [ -L "$STAGING_ROOT" ]; then
    set +e
    run_helper staging-c0ffeec0 >/dev/null 2>&1
    rc=$?
    set -e
    check 'a staging root that is itself a symlink still enforces the layout/device checks' bash -c "[ $rc -ne 0 ]"
else
    skip 'symlinked-staging-root scenario (this environment does not create real symlinks)'
fi

# ------------------------------------------------------------------
# Scenario 6: an incomplete staged layout (missing agent.php / lib) is
# rejected — the helper never trusts the caller's own validation.
# ------------------------------------------------------------------
INSTALL_DIR="$ROOT/s6/opt/deployment-server-agent"
STAGING_ROOT="$ROOT/s6/opt/deployment-server-agent-staging"
make_runtime "$INSTALL_DIR" '1.4.1'
mkdir -p "$STAGING_ROOT/staging-0badf00d"
printf '%s\n' 'not even php' >"$STAGING_ROOT/staging-0badf00d/readme.txt"
set +e
run_helper staging-0badf00d >/dev/null 2>&1
rc=$?
set -e
check 'a staged layout missing agent.php/lib is rejected' bash -c "[ $rc -eq 68 ]"
check 'the active runtime is untouched after an incomplete-layout attempt' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL_DIR/agent.php"

# ------------------------------------------------------------------
# Scenario 7: rollback on activation failure — the second rename
# (staging -> install) is made to fail by removing write permission
# on the staging root itself (so its own entries cannot be unlinked),
# proving the helper restores the backed-up runtime rather than
# leaving the install directory missing or half-swapped. Requires a
# filesystem where chmod is actually enforced against the owning user.
# ------------------------------------------------------------------
if [ "$PERMISSIONS_ENFORCED" -eq 1 ]; then
    INSTALL_DIR="$ROOT/s7/opt/deployment-server-agent"
    STAGING_ROOT="$ROOT/s7/opt/deployment-server-agent-staging"
    make_runtime "$INSTALL_DIR" '1.4.1'
    mkdir -p "$STAGING_ROOT/staging-fee1dead/lib"
    make_runtime "$STAGING_ROOT/staging-fee1dead" '1.4.2'
    chmod 0500 "$STAGING_ROOT"
    set +e
    run_helper staging-fee1dead >/dev/null 2>&1
    rc=$?
    set -e
    chmod 0755 "$STAGING_ROOT"
    check 'a failed second rename is reported with its own distinct exit code' bash -c "[ $rc -eq 71 ]"
    check 'a failed activation restores the backed-up runtime (old version active again)' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL_DIR/agent.php"
    check_not 'a failed activation does not leave a dangling backup directory' bash -c "[ \"\$(find '$ROOT/s7/opt' -maxdepth 1 -name 'deployment-server-agent.backup-*' | wc -l)\" -gt 0 ]"
else
    skip 'rollback-on-activation-failure scenario (chmod is not enforced against the owning user on this filesystem)'
    skip 'rollback restores the previous runtime (not applicable — permissions not enforced)'
    skip 'rollback removes the transient backup directory (not applicable — permissions not enforced)'
fi

# ------------------------------------------------------------------
# Scenario 8: the same-filesystem proof exists in the script itself —
# a real cross-device rename requires a second mounted filesystem this
# test cannot assume CI/dev machines have, so this is a deterministic,
# environment-independent contract check instead of a live one.
# ------------------------------------------------------------------
check 'the script proves same-filesystem before any rename, rather than assuming it' grep -q 'different filesystems' "$HELPER"

printf '\nServer Agent activate helper: %d passed, %d failed, %d skipped\n' "$passed" "$failed" "$skipped"
exit $([ "$failed" -eq 0 ] && echo 0 || echo 1)
