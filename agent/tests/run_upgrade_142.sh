#!/usr/bin/env bash
# Real, non-mocked dry-run/activate/rollback test for the 1.4.1 -> 1.4.2
# bridge (agent/install/upgrade-1.4.2.sh) — mirrors run_upgrade_140.sh's
# own structure exactly. Baseline (1.4.1) content comes from the real
# 757e1b5 commit via `git archive`; candidate (1.4.2) content comes
# from this repository's own git INDEX (never a raw working-tree copy,
# which on a core.autocrlf=true checkout would be CRLF and could never
# match the canonical LF hashes hardcoded in upgrade-1.4.2.sh — the
# exact class of bug this whole release exists to fix).
set -Eeuo pipefail
ROOT="$(mktemp -d)"
trap 'rm -rf -- "$ROOT"' EXIT
INSTALL="$ROOT/opt/deployment-server-agent"
SOURCE="$ROOT/source"
STATE="$ROOT/state"
mkdir -p "$INSTALL/lib" "$SOURCE/lib" "$SOURCE/install" "$ROOT/usr/local/sbin" "$ROOT/etc/sudoers.d"

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
cd "$REPO_ROOT"

# Baseline: the real, official 1.4.1 release commit's agent/ subtree —
# written file-by-file via `git show` (canonical LF blob content),
# never `git archive | tar -x`, which was empirically found to apply
# this checkout's own core.autocrlf conversion and silently reproduce
# the exact CRLF-hash bug this whole release exists to fix.
git show 757e1b5:agent/agent.php > "$INSTALL/agent.php"
git show 757e1b5:agent/lib/OperationRegistry.php > "$INSTALL/lib/OperationRegistry.php"
git show 757e1b5:agent/lib/PipelineExecutor.php > "$INSTALL/lib/PipelineExecutor.php"
git show 757e1b5:agent/lib/SelfUpgradeManager.php > "$INSTALL/lib/SelfUpgradeManager.php"
cp "$INSTALL/lib/OperationRegistry.php" "$ROOT/registry-backup.php"

# Candidate: this repository's own staged (git index) 1.4.2 content —
# canonical LF bytes, matching upgrade-1.4.2.sh's hardcoded hashes
# regardless of this checkout's own core.autocrlf setting.
git show :agent/agent.php > "$SOURCE/agent.php"
git show :agent/lib/SelfUpgradeManager.php > "$SOURCE/lib/SelfUpgradeManager.php"
git show :agent/install/deployment-server-agent-activate > "$SOURCE/install/deployment-server-agent-activate"
git show :agent/install/deployment-server-agent-activate.sudoers > "$SOURCE/install/deployment-server-agent-activate.sudoers"

PHP_WIN=/c/laragon/bin/php/php-8.3-Win32-vs16-x64/php.exe
cp agent/install/deployment-service-reload "$ROOT/usr/local/sbin/deployment-service-reload"
export SERVER_AGENT_UPGRADER_PHP_BIN="$PHP_WIN"
cat > "$ROOT/systemctl" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SERVER_AGENT_TEST_LOG"
EOF
cat > "$ROOT/visudo" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$ROOT/systemctl" "$ROOT/visudo" "$ROOT/usr/local/sbin/deployment-service-reload"

export SERVER_AGENT_INSTALL_DIR="$INSTALL" SERVER_AGENT_SOURCE_DIR="$SOURCE"
export SERVER_AGENT_STATE_ROOT="$STATE" SERVER_AGENT_SERVICE_HELPER="$ROOT/usr/local/sbin/deployment-service-reload"
export SERVER_AGENT_ACTIVATE_HELPER="$ROOT/usr/local/sbin/deployment-server-agent-activate"
export SERVER_AGENT_ACTIVATE_SUDOERS="$ROOT/etc/sudoers.d/deployment-server-agent-activate"
export SERVER_AGENT_SERVICE_OWNER="$(id -u):$(id -g)"
export SERVER_AGENT_SYSTEMCTL_BIN="$ROOT/systemctl" SERVER_AGENT_TEST_MODE=1 SERVER_AGENT_TEST_LOG="$ROOT/systemctl.log"
export PATH="$ROOT:$PATH"

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

# ------------------------------------------------------------------
# dry-run: hash-verifies everything, touches nothing live.
# ------------------------------------------------------------------
export SERVER_AGENT_RUN_ID=bridge-142-dry-run
output="$(bash agent/install/upgrade-1.4.2.sh dry-run)"
check 'dry-run reports DRY_RUN_OK' bash -c "printf '%s' '$output' | grep -q '^DRY_RUN_OK '"
check 'dry-run does not restart the service' bash -c "[ ! -s '$ROOT/systemctl.log' ]"
check 'dry-run leaves the active runtime at 1.4.1' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL/agent.php"
check 'dry-run does not install the activation helper' bash -c "[ ! -f '$ROOT/usr/local/sbin/deployment-server-agent-activate' ]"
check 'dry-run does not provision the staging area' bash -c "[ ! -d '$ROOT/opt/deployment-server-agent-staging' ]"

# ------------------------------------------------------------------
# activate: a FRESH run_id (the dry-run's own backup directory already
# exists under its own run_id — see the prior audit's finding that
# reusing the same run_id fails at "This run_id already owns a
# backup").
# ------------------------------------------------------------------
export SERVER_AGENT_RUN_ID=bridge-142-activate
output="$(bash agent/install/upgrade-1.4.2.sh activate)"
check 'activate reports ACTIVATION_OK' bash -c "printf '%s' '$output' | grep -q '^ACTIVATION_OK '"
check 'activate updates the active runtime to 1.4.2' grep -q "AGENT_VERSION = '1.4.2'" "$INSTALL/agent.php"
check 'activate installs the new SelfUpgradeManager.php' grep -q 'deployment-server-agent-activate' "$INSTALL/lib/SelfUpgradeManager.php"
check 'activate preserves OperationRegistry.php byte-for-byte' cmp -s "$ROOT/registry-backup.php" "$INSTALL/lib/OperationRegistry.php"
check 'activate installs the activation helper' test -f "$ROOT/usr/local/sbin/deployment-server-agent-activate"
check 'activate installs the activation sudoers fragment' test -f "$ROOT/etc/sudoers.d/deployment-server-agent-activate"
check 'activate provisions the permanent staging area' test -d "$ROOT/opt/deployment-server-agent-staging"
check 'activate restarts the service exactly once (restart + is-active, 2 log lines)' bash -c "[ \"\$(wc -l < '$ROOT/systemctl.log')\" = 2 ]"

# ------------------------------------------------------------------
# rollback: requires a hashed, run_id-bound Hub attestation; restores
# the exact pre-activation 1.4.1 runtime; idempotent on a second call.
# ------------------------------------------------------------------
printf '{"run_id":"bridge-142-activate","policy_required":false}\n' > "$ROOT/rollback-attestation.json"
export SERVER_AGENT_ROLLBACK_ATTESTATION="$ROOT/rollback-attestation.json"
export SERVER_AGENT_ROLLBACK_ATTESTATION_SHA256="$(sha256sum "$ROOT/rollback-attestation.json" | awk '{print $1}')"
bash agent/install/upgrade-1.4.2.sh rollback
bash agent/install/upgrade-1.4.2.sh rollback
check 'rollback restores the previous 1.4.1 runtime' grep -q "AGENT_VERSION = '1.4.1'" "$INSTALL/agent.php"
check 'rollback preserves the newly-installed activation helper (harmless, left in place)' test -f "$ROOT/usr/local/sbin/deployment-server-agent-activate"
check 'rollback preserves the provisioned staging area (harmless, left in place)' test -d "$ROOT/opt/deployment-server-agent-staging"
check 'rollback restarts the service exactly once more (idempotent marker skips the second call entirely)' bash -c "[ \"\$(wc -l < '$ROOT/systemctl.log')\" = 4 ]"

# ------------------------------------------------------------------
# Rejection scenarios: wrong baseline, wrong candidate — each in its
# own isolated root so a rejection can never be masked by state left
# over from the scenarios above.
# ------------------------------------------------------------------
WRONG_BASELINE_ROOT="$ROOT/wrong-baseline"
mkdir -p "$WRONG_BASELINE_ROOT/opt/deployment-server-agent/lib" "$WRONG_BASELINE_ROOT/state"
git show :agent/agent.php > "$WRONG_BASELINE_ROOT/opt/deployment-server-agent/agent.php"
git show :agent/lib/OperationRegistry.php > "$WRONG_BASELINE_ROOT/opt/deployment-server-agent/lib/OperationRegistry.php"
check 'a baseline that is already 1.4.2 (not 1.4.1) is rejected with the exact expected reason' bash -c "
    export SERVER_AGENT_INSTALL_DIR='$WRONG_BASELINE_ROOT/opt/deployment-server-agent'
    export SERVER_AGENT_STATE_ROOT='$WRONG_BASELINE_ROOT/state'
    export SERVER_AGENT_SERVICE_HELPER='$ROOT/usr/local/sbin/deployment-service-reload'
    export SERVER_AGENT_RUN_ID=bridge-142-wrong-baseline
    output=\"\$(bash agent/install/upgrade-1.4.2.sh dry-run 2>&1)\"
    [ \$? -ne 0 ] && printf '%s' \"\$output\" | grep -q 'Active Server Agent must be 1.4.1'
"

WRONG_CANDIDATE_ROOT="$ROOT/wrong-candidate"
mkdir -p "$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent/lib" "$WRONG_CANDIDATE_ROOT/source/lib" "$WRONG_CANDIDATE_ROOT/state"
git show 757e1b5:agent/agent.php > "$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent/agent.php"
git show 757e1b5:agent/lib/OperationRegistry.php > "$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent/lib/OperationRegistry.php"
git show 757e1b5:agent/lib/PipelineExecutor.php > "$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent/lib/PipelineExecutor.php"
git show 757e1b5:agent/lib/SelfUpgradeManager.php > "$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent/lib/SelfUpgradeManager.php"
# A candidate still reporting 1.4.1 (unchanged) instead of 1.4.2.
git show 757e1b5:agent/agent.php > "$WRONG_CANDIDATE_ROOT/source/agent.php"
git show 757e1b5:agent/lib/SelfUpgradeManager.php > "$WRONG_CANDIDATE_ROOT/source/lib/SelfUpgradeManager.php"
check 'a candidate that is still 1.4.1 (not 1.4.2) is rejected with the exact expected reason' bash -c "
    export SERVER_AGENT_INSTALL_DIR='$WRONG_CANDIDATE_ROOT/opt/deployment-server-agent'
    export SERVER_AGENT_SOURCE_DIR='$WRONG_CANDIDATE_ROOT/source'
    export SERVER_AGENT_STATE_ROOT='$WRONG_CANDIDATE_ROOT/state'
    export SERVER_AGENT_SERVICE_HELPER='$ROOT/usr/local/sbin/deployment-service-reload'
    export SERVER_AGENT_RUN_ID=bridge-142-wrong-candidate
    output=\"\$(bash agent/install/upgrade-1.4.2.sh dry-run 2>&1)\"
    [ \$? -ne 0 ] && printf '%s' \"\$output\" | grep -q 'Candidate Server Agent must be 1.4.2'
"

printf '\nServer Agent 1.4.1 -> 1.4.2 bridge: %d passed, %d failed\n' "$passed" "$failed"
exit $([ "$failed" -eq 0 ] && echo 0 || echo 1)
