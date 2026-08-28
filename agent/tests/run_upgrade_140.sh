#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(mktemp -d)"
trap 'rm -rf -- "$ROOT"' EXIT
INSTALL="$ROOT/opt/deployment-server-agent"
SOURCE="$ROOT/source"
STATE="$ROOT/state"
mkdir -p "$INSTALL/lib" "$SOURCE/lib" "$ROOT/usr/local/sbin"
git archive ba0e793:agent | tar -x -C "$INSTALL"
cp agent/agent.php "$SOURCE/agent.php"
cp agent/lib/ArtifactManager.php agent/lib/ReleaseValidator.php "$SOURCE/lib/"
PHP_WIN=/c/laragon/bin/php/php-8.4.16-nts-Win32-vs17-x64/php.exe
"$PHP_WIN" -r '$s=file_get_contents($argv[1]); file_put_contents($argv[2],str_replace("AGENT_VERSION = '\''1.4.0'\''","AGENT_VERSION = '\''1.3.4-p1'\''",$s));' agent/agent.php "$INSTALL/agent.php"
cp agent/lib/OperationRegistry.php "$INSTALL/lib/OperationRegistry.php"
cp agent/install/deployment-service-reload "$ROOT/usr/local/sbin/deployment-service-reload"
export SERVER_AGENT_UPGRADER_PHP_BIN="$PHP_WIN"
cat > "$ROOT/systemctl" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SERVER_AGENT_TEST_LOG"
EOF
chmod +x "$ROOT/systemctl" "$ROOT/usr/local/sbin/deployment-service-reload"
export SERVER_AGENT_RUN_ID=lineage-dry-run SERVER_AGENT_INSTALL_DIR="$INSTALL" SERVER_AGENT_SOURCE_DIR="$SOURCE"
export SERVER_AGENT_STATE_ROOT="$STATE" SERVER_AGENT_SERVICE_HELPER="$ROOT/usr/local/sbin/deployment-service-reload"
export SERVER_AGENT_SYSTEMCTL_BIN="$ROOT/systemctl" SERVER_AGENT_TEST_MODE=1 SERVER_AGENT_TEST_LOG="$ROOT/systemctl.log"
bash agent/install/upgrade-1.4.0.sh dry-run
[[ ! -s "$ROOT/systemctl.log" ]]
[[ "$(sha256sum "$INSTALL/agent.php" | awk '{print $1}')" == 69a5cc23eb0ac8d88060d803f42223f0026ad3bfdc86149e6ef4af24c459b985 ]]
[[ "$(sha256sum "$ROOT/usr/local/sbin/deployment-service-reload" | awk '{print $1}')" == 23560656f95e29c9de6b7eda070774f7d356da44cbfbdd52502291bfd81b1969 ]]

export SERVER_AGENT_RUN_ID=lineage-activate
bash agent/install/upgrade-1.4.0.sh activate
grep -q "AGENT_VERSION = '1.4.0'" "$INSTALL/agent.php"
[[ "$(wc -l < "$ROOT/systemctl.log")" == 2 ]]
printf '{"run_id":"lineage-activate","policy_required":false}\n' > "$ROOT/rollback-attestation.json"
export SERVER_AGENT_ROLLBACK_ATTESTATION="$ROOT/rollback-attestation.json"
export SERVER_AGENT_ROLLBACK_ATTESTATION_SHA256="$(sha256sum "$ROOT/rollback-attestation.json" | awk '{print $1}')"
bash agent/install/upgrade-1.4.0.sh rollback
bash agent/install/upgrade-1.4.0.sh rollback
grep -q "AGENT_VERSION = '1.3.4-p1'" "$INSTALL/agent.php"
[[ "$(wc -l < "$ROOT/systemctl.log")" == 4 ]]
echo 'Server Agent 1.4.0 staging/activation/rollback: ALL PASS'
