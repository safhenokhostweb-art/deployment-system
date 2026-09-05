<?php

declare(strict_types=1);

/**
 * Platform-independent contract test for root-owned helpers. The production
 * helpers intentionally use fixed absolute Linux paths and cannot be executed
 * safely by the unprivileged Windows test environment, so this test verifies
 * their closed allowlists and their exact dispatch contracts from source.
 *
 * Usage: php tests/run_privileged_helpers.php
 */

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, array &$failures, int &$passCount): void
{
    if ($condition) {
        $passCount++;

        return;
    }

    $failures[] = $description;
}

$runtime = (string) file_get_contents(__DIR__ . '/../install/deployment-runtime-permissions');
$runtimeSudoers = (string) file_get_contents(__DIR__ . '/../install/deployment-runtime-permissions.sudoers');
$service = (string) file_get_contents(__DIR__ . '/../install/deployment-service-reload');
$serviceSudoers = (string) file_get_contents(__DIR__ . '/../install/deployment-service-reload.sudoers');
$pipeline = (string) file_get_contents(__DIR__ . '/../lib/PipelineExecutor.php');
$agent = (string) file_get_contents(__DIR__ . '/../agent.php');
$upgrade = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(__DIR__ . '/../install/upgrade-runtime.sh'));

check('deployment-system remains an explicit runtime-permissions project key', str_contains($runtime, 'deployment-system)'), $failures, $passCount);
check('saas-platform is an explicit runtime-permissions project key', str_contains($runtime, 'saas-platform)'), $failures, $passCount);
check('customer-site is an explicit runtime-permissions project key', str_contains($runtime, 'customer-site)'), $failures, $passCount);
check('unknown runtime-permissions project keys are rejected with a concise diagnostic', str_contains($runtime, "*) fail \"\$project_key\" validate - 'invalid project key' 65 ;;"), $failures, $passCount);
check('saas-platform runtime permissions use only its application root', str_contains($runtime, 'ROOT=/var/www/saas-platform'), $failures, $passCount);
check('saas-platform uses a dedicated shared runtime root', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/saas-platform-shared'), $failures, $passCount);
foreach (['storage/app', 'storage/logs', 'public/storage', 'public/uploads'] as $persistentPath) {
    check("saas persistent contract includes {$persistentPath}", str_contains($runtime, '"$PERSISTENT_ROOT/' . $persistentPath . '"'), $failures, $passCount);
}
check('saas persistent targets must already exist and cannot silently become empty state', str_contains($runtime, "'required persistent target missing' 68") && str_contains($runtime, "'persistent target must not be a symlink' 67"), $failures, $passCount);
foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $runtimePath) {
    check("saas release-local runtime permissions explicitly allow {$runtimePath}", str_contains($runtime, '"$ROOT/' . $runtimePath . '"'), $failures, $passCount);
}
check('saas runtime paths are verified writable as www-data', str_contains($runtime, 'runuser -u www-data -- test -w "$path"') && str_contains($runtime, 'saas-platform ]; then'), $failures, $passCount);
check('saas source tree is verified non-writable outside approved runtime paths', str_contains($runtime, 'if [ "$project_key" = customer-site ] || [ "$project_key" = saas-platform ]; then') && str_contains($runtime, '"$ROOT/public/storage"') && str_contains($runtime, '"$ROOT/public/uploads"'), $failures, $passCount);
check('runtime helper resolves and confines every approved path below its root', str_contains($runtime, 'realpath -e -- "$path"') && str_contains($runtime, 'case "$resolved" in "$ROOT"/*)'), $failures, $passCount);
check('runtime helper never mutates the whole application root', !str_contains($runtime, 'paths=("$ROOT")') && !str_contains($runtime, 'chown "$OWNER:$GROUP" -- "$ROOT"') && !str_contains($runtime, 'chmod "$DIRECTORY_MODE" -- "$ROOT"'), $failures, $passCount);
check('deployment-system helper normalizes the SQLite directory and primary database file', str_contains($runtime, '"$ROOT/database"') && str_contains($runtime, '"$ROOT/database/database.sqlite"'), $failures, $passCount);
check('runtime helper normalizes runtime directories through a fixed project mode', str_contains($runtime, 'chmod "$DIRECTORY_MODE" -- "$target"'), $failures, $passCount);
check('runtime helper normalizes runtime files through a fixed project mode', str_contains($runtime, 'chmod "$FILE_MODE" -- "$target"') && str_contains($runtime, '-type f'), $failures, $passCount);
check('runtime helper tolerates only disappearing runtime entries during traversal', str_contains($runtime, '-ignore_readdir_race') && str_contains($runtime, '[ ! -e "$target" ] || fail'), $failures, $passCount);
check('runtime helper emits diagnostics only through one concise failure helper', str_contains($runtime, 'fail() {') && str_contains($runtime, 'deployment-runtime-permissions: project=%s phase=%s path=%s operation=%s exit_code=%s') && substr_count($runtime, 'printf ') === 1, $failures, $passCount);
check('missing SQLite is diagnosed before realpath validation with exit code 68', str_contains($runtime, 'for path in "${files[@]}"; do') && str_contains($runtime, "[ -f \"\$path\" ] || fail \"\$project_key\" validate \"\$path\" 'required database.sqlite existence check' 68"), $failures, $passCount);
check('realpath and path-confinement failures retain distinct diagnostics and exit codes', str_contains($runtime, 'realpath 66') && str_contains($runtime, "'path confinement' 67"), $failures, $passCount);
check('find directory and file failures report their processing phase', str_contains($runtime, "'find directory' 1") && str_contains($runtime, "'find file' 1"), $failures, $passCount);
check('persistent chown and chmod failures emit exact-operation diagnostics', str_contains($runtime, 'fail "$project_key" normalize "$target" chown 1') && str_contains($runtime, 'fail "$project_key" normalize "$target" chmod 1') && str_contains($runtime, 'fail "$project_key" normalize "$path" chown 1') && str_contains($runtime, 'fail "$project_key" normalize "$path" chmod 1'), $failures, $passCount);
check('runtime sudoers allows only the three literal approved arguments', str_contains($runtimeSudoers, 'deployment-runtime-permissions deployment-system') && str_contains($runtimeSudoers, 'deployment-runtime-permissions saas-platform') && str_contains($runtimeSudoers, 'deployment-runtime-permissions customer-site'), $failures, $passCount);
check('customer uploads root is bootstrapped idempotently outside the release', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/customer-site-shared') && str_contains($runtime, 'UPLOADS_ROOT="$PERSISTENT_ROOT/uploads"') && str_contains($runtime, 'install -d'), $failures, $passCount);
check('customer shared and uploads roots reject pre-existing symlinks', str_contains($runtime, '[ ! -L "$PERSISTENT_ROOT" ]') && str_contains($runtime, '[ ! -L "$UPLOADS_ROOT" ]'), $failures, $passCount);
check('customer uploads use deployment-agent:www-data and setgid 2775/0664, never 777', str_contains($runtime, 'OWNER=deployment-agent') && str_contains($runtime, 'GROUP=www-data') && str_contains($runtime, 'DIRECTORY_MODE=2775') && str_contains($runtime, 'FILE_MODE=0664') && !preg_match('/\b0?777\b/', $runtime), $failures, $passCount);
foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $runtimePath) {
    check("customer runtime permissions explicitly allow {$runtimePath}", str_contains($runtime, '"$ROOT/' . $runtimePath . '"'), $failures, $passCount);
}
check('customer runtime paths are verified writable as www-data', str_contains($runtime, 'runuser -u www-data -- test -w "$path"') && str_contains($runtime, "'www-data write access' 70"), $failures, $passCount);
check('customer source tree remains outside recursive permission normalization', !str_contains($runtime, 'paths=("$ROOT")'), $failures, $passCount);
check('customer source tree is verified non-writable by www-data outside approved runtime paths', str_contains($runtime, 'unexpected_writable=') && str_contains($runtime, 'runuser -u www-data -- find -P "$ROOT"') && str_contains($runtime, "'unapproved www-data write access' 72"), $failures, $passCount);
check('runtime helper confines a project persistent root alongside the release root', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/deployment-system/persistent') && str_contains($runtime, 'case "$resolved" in "$ROOT"/*) return 0') && str_contains($runtime, 'case "$resolved" in "$PERSISTENT_ROOT"|"$PERSISTENT_ROOT"/*) return 0'), $failures, $passCount);
check('runtime helper normalizes the persistent root as one approved path', str_contains($runtime, '"$PERSISTENT_ROOT")'), $failures, $passCount);
check('runtime helper confines both deployment-system and saas persistent roots explicitly', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/deployment-system/persistent') && str_contains($runtime, 'PERSISTENT_ROOT=/var/www/saas-platform-shared'), $failures, $passCount);

check('deployment-system remains an explicit service-reload project key', str_contains($service, 'deployment-system)'), $failures, $passCount);
check('saas-platform is an explicit service-reload project key', str_contains($service, 'saas-platform)'), $failures, $passCount);
check('unknown service-reload project keys are rejected', str_contains($service, 'exit 65'), $failures, $passCount);
check('ordinary deployments never reload shared PHP-FPM', !preg_match('/systemctl\s+reload\s+php[^\s]*/', $service), $failures, $passCount);
check('nginx reload is guarded by a syntax-checked expanded-config hash', str_contains($service, '/usr/sbin/nginx -T') && str_contains($service, 'nginx-config.sha256') && str_contains($service, 'if [ "$current_hash" = "$previous_hash" ]; then'), $failures, $passCount);
check('first conditional-nginx invocation records a baseline without reload', strpos($service, 'persist_nginx_hash "$current_hash"') < strpos($service, '/usr/bin/systemctl reload nginx'), $failures, $passCount);
check('changed nginx configuration reloads before its new hash is persisted', strpos($service, '/usr/bin/systemctl reload nginx') < strrpos($service, 'persist_nginx_hash "$current_hash"'), $failures, $passCount);
check('conditional nginx reload is serialized across concurrent project deploys', str_contains($service, '/usr/bin/flock -x 9'), $failures, $passCount);
check('saas service reload restarts reverb by its exact simple-program name', str_contains($service, 'supervisorctl restart reverb'), $failures, $passCount);
check('saas service reload restarts the exact platform-worker group selector', str_contains($service, "supervisorctl restart 'saas-platform-worker:*'"), $failures, $passCount);
check('saas service reload restarts the exact install-worker group selector', str_contains($service, "supervisorctl restart 'saas-install-worker:*'"), $failures, $passCount);
check('saas service reload never uses broad Supervisor commands', !str_contains($service, 'supervisorctl restart all') && !str_contains($service, 'supervisorctl restart *'), $failures, $passCount);
check('saas restore worker state is queried with its exact group selector', str_contains($service, "supervisorctl status 'saas-restore-worker:*'"), $failures, $passCount);
check('restore group status is parsed member-by-member for multiple lines', str_contains($service, 'while read -r restore_program restore_state _; do') && str_contains($service, 'saas-restore-worker:*)'), $failures, $passCount);
check('a stopped restore group is not started', str_contains($service, 'STOPPED|EXITED|FATAL|BACKOFF) ;;') && !str_contains($service, 'STOPPED) /usr/bin/supervisorctl restart'), $failures, $passCount);
check('a running restore group is restarted with its exact group selector', str_contains($service, 'if [ "$restore_running" = true ]; then') && str_contains($service, "supervisorctl restart 'saas-restore-worker:*'"), $failures, $passCount);
check('unexpected restore state fails closed', str_contains($service, 'Unexpected saas-restore-worker state: $restore_state') && str_contains($service, 'exit 70'), $failures, $passCount);
check('deployment-system service reload restarts its exact queue-worker group selector', str_contains($service, "supervisorctl restart 'deployment-service-worker:*'"), $failures, $passCount);
check('unrelated Supervisor programs are absent from the service helper', !str_contains($service, 'customer-site-worker'), $failures, $passCount);
check('service sudoers allows only the two literal approved arguments', str_contains($serviceSudoers, 'deployment-service-reload deployment-system') && str_contains($serviceSudoers, 'deployment-service-reload saas-platform'), $failures, $passCount);
check('runtime upgrade targets only the Server Agent runtime', str_contains($upgrade, 'INSTALL_DIR=/opt/deployment-server-agent') && str_contains($upgrade, 'SERVICE=deployment-server-agent'), $failures, $passCount);
check('runtime upgrade never targets or restarts the Local Agent', !str_contains($upgrade, "INSTALL_DIR=/opt/deployment-agent\n") && !str_contains($upgrade, "SERVICE=deployment-agent\n"), $failures, $passCount);
check('runtime upgrade accepts source only from the isolated deployment-system workspace', str_contains($upgrade, '/opt/deployment-agent/workspaces/deployment-system/agent)'), $failures, $passCount);
check('runtime upgrade preserves and verifies config.php', str_contains($upgrade, 'CONFIG_HASH=') && substr_count($upgrade, 'sha256sum "$INSTALL_DIR/config.php"') >= 2, $failures, $passCount);
check('runtime upgrade restores restrictive runtime ownership and modes in staging', str_contains($upgrade, 'chown deployment-agent:deployment-agent "$STAGING"') && str_contains($upgrade, 'chmod 0750 "$STAGING"') && str_contains($upgrade, 'chmod 0600 "$STAGING/config.php"'), $failures, $passCount);
check('runtime upgrade stages and atomically renames the runtime', str_contains($upgrade, 'STAGING="${INSTALL_DIR}.staging-${STAMP}"') && str_contains($upgrade, 'mv "$INSTALL_DIR" "$BACKUP"') && str_contains($upgrade, 'mv "$STAGING" "$INSTALL_DIR"'), $failures, $passCount);
check('runtime upgrade automatically restores the backup on activation failure', str_contains($upgrade, 'trap rollback ERR') && str_contains($upgrade, 'mv "$BACKUP" "$INSTALL_DIR"'), $failures, $passCount);
check('runtime upgrade validates and atomically installs the persistent-runtime helper allowlist', str_contains($upgrade, 'bash -n "$SCRIPT_DIR/deployment-runtime-permissions"') && str_contains($upgrade, 'visudo -cf "$SCRIPT_DIR/deployment-runtime-permissions.sudoers"') && str_contains($upgrade, 'install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-runtime-permissions" "$RUNTIME_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" "$RUNTIME_SUDOERS"'), $failures, $passCount);
check('runtime upgrade backs up and rolls back both privileged runtime files', str_contains($upgrade, 'PRIVILEGED_BACKUP=') && str_contains($upgrade, 'PRIVILEGED_ACTIVATED=true') && str_contains($upgrade, 'install -o root -g root -m 0755 "$PRIVILEGED_BACKUP/helper" "$RUNTIME_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$PRIVILEGED_BACKUP/sudoers" "$RUNTIME_SUDOERS"'), $failures, $passCount);
check('runtime upgrade rejects downgrades', str_contains($upgrade, 'version_compare($argv[1], $argv[2], ">=")'), $failures, $passCount);
check('runtime upgrade requires service-reload source before touching anything live', str_contains($upgrade, "[ -f \"\$SCRIPT_DIR/deployment-service-reload\" ] || fail 'service reload helper source is missing'") && str_contains($upgrade, "[ -f \"\$SCRIPT_DIR/deployment-service-reload.sudoers\" ] || fail 'service reload sudoers source is missing'"), $failures, $passCount);
check('runtime upgrade validates service-reload syntax before staging', str_contains($upgrade, 'bash -n "$SCRIPT_DIR/deployment-service-reload"') && str_contains($upgrade, 'visudo -cf "$SCRIPT_DIR/deployment-service-reload.sudoers"'), $failures, $passCount);
check('runtime upgrade atomically installs service-reload with root:root and the exact executable/sudoers modes', str_contains($upgrade, 'install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-service-reload" "$SERVICE_RELOAD_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-service-reload.sudoers" "$SERVICE_RELOAD_SUDOERS"') && str_contains($upgrade, 'visudo -cf "$SERVICE_RELOAD_SUDOERS"'), $failures, $passCount);
check('runtime upgrade backs up the previously installed service-reload helper and sudoers, or records their absence', str_contains($upgrade, 'cp -a "$SERVICE_RELOAD_HELPER" "$PRIVILEGED_BACKUP/service-reload"') && str_contains($upgrade, 'touch "$PRIVILEGED_BACKUP/service-reload.absent"') && str_contains($upgrade, 'cp -a "$SERVICE_RELOAD_SUDOERS" "$PRIVILEGED_BACKUP/service-reload-sudoers"') && str_contains($upgrade, 'touch "$PRIVILEGED_BACKUP/service-reload-sudoers.absent"'), $failures, $passCount);
check('runtime upgrade rolls back service-reload to its exact prior state, or removes it if it was absent', str_contains($upgrade, 'install -o root -g root -m 0755 "$PRIVILEGED_BACKUP/service-reload" "$SERVICE_RELOAD_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$PRIVILEGED_BACKUP/service-reload-sudoers" "$SERVICE_RELOAD_SUDOERS"') && str_contains($upgrade, 'rm -f "$SERVICE_RELOAD_HELPER"') && str_contains($upgrade, 'rm -f "$SERVICE_RELOAD_SUDOERS"'), $failures, $passCount);
check('runtime upgrade verifies the activated service-reload helper and sudoers byte-for-byte against source', str_contains($upgrade, 'cmp -s "$SCRIPT_DIR/deployment-service-reload" "$SERVICE_RELOAD_HELPER"') && str_contains($upgrade, 'cmp -s "$SCRIPT_DIR/deployment-service-reload.sudoers" "$SERVICE_RELOAD_SUDOERS"'), $failures, $passCount);
check('service-reload install targets one fixed absolute path each, not an arbitrary copy capability', substr_count($upgrade, 'SERVICE_RELOAD_HELPER=/usr/local/sbin/deployment-service-reload') === 1 && substr_count($upgrade, 'SERVICE_RELOAD_SUDOERS=/etc/sudoers.d/deployment-service-reload') === 1, $failures, $passCount);
check('the pre-existing runtime-permissions helper install/backup/rollback is unmodified by the service-reload extension', str_contains($upgrade, 'install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-runtime-permissions" "$RUNTIME_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" "$RUNTIME_SUDOERS"') && str_contains($upgrade, 'install -o root -g root -m 0755 "$PRIVILEGED_BACKUP/helper" "$RUNTIME_HELPER"') && str_contains($upgrade, 'install -o root -g root -m 0440 "$PRIVILEGED_BACKUP/sudoers" "$RUNTIME_SUDOERS"'), $failures, $passCount);
check('PipelineExecutor resolves saas runtime permissions only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$job['project_key']]"), $failures, $passCount);
check('PipelineExecutor resolves saas service reload only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-service-reload', \$job['project_key']]"), $failures, $passCount);
check('standalone config_cache normalizes permissions only through the fixed helper argv', str_contains($agent, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', (string) \$projectKey]"), $failures, $passCount);
check('standalone config_cache runs permission normalization only after command success', str_contains($agent, "\$result['exitCode'] === 0") && str_contains($agent, 'OperationRegistry::requiresRuntimePermissionsAfter($operationType)'), $failures, $passCount);

// ------------------------------------------------------------------
// Root-cause fix (1.4.1 -> 1.4.2, Control Job #1): the new privileged
// activation helper and SelfUpgradeManager's exact dispatch contract
// to both it and the pre-existing restart helper — never previously
// checked in this file.
// ------------------------------------------------------------------
$restart = (string) file_get_contents(__DIR__ . '/../install/deployment-server-agent-restart');
$restartSudoers = (string) file_get_contents(__DIR__ . '/../install/deployment-server-agent-restart.sudoers');
$activate = (string) file_get_contents(__DIR__ . '/../install/deployment-server-agent-activate');
$activateSudoers = (string) file_get_contents(__DIR__ . '/../install/deployment-server-agent-activate.sudoers');
$selfUpgradeManager = (string) file_get_contents(__DIR__ . '/../lib/SelfUpgradeManager.php');

check('the restart helper accepts zero arguments only', str_contains($restart, 'if [ "$#" -ne 0 ]') && str_contains($restart, 'exit 64'), $failures, $passCount);
check('the restart helper restarts exactly one fixed systemd unit', str_contains($restart, 'systemctl restart deployment-server-agent.service'), $failures, $passCount);
check('the restart sudoers grants NOPASSWD for exactly the argument-free restart invocation', trim($restartSudoers) === 'deployment-agent ALL=(root) NOPASSWD: /usr/local/sbin/deployment-server-agent-restart', $failures, $passCount);

check('the activate helper accepts exactly one argument', str_contains($activate, 'if [ "$#" -ne 1 ]') && str_contains($activate, 'exit 64'), $failures, $passCount);
check('the activate helper strictly validates the staging name against a hex-only pattern', str_contains($activate, '^staging-[0-9a-f]{8,32}$'), $failures, $passCount);
check('the activate helper rejects a symlinked staging path', str_contains($activate, '[ ! -L "$staging_path" ]'), $failures, $passCount);
check('the activate helper rejects a symlinked install directory', str_contains($activate, '[ ! -L "$INSTALL_DIR" ]'), $failures, $passCount);
check('the activate helper confines the resolved staging path to a direct child of the staging root', str_contains($activate, 'escapes the staging root') && str_contains($activate, 'must be a direct child of the staging root'), $failures, $passCount);
check('the activate helper re-validates the staged layout itself, never trusting the caller', str_contains($activate, "-f \"\$resolved_staging/agent.php\"") && str_contains($activate, "-d \"\$resolved_staging/lib\""), $failures, $passCount);
check('the activate helper proves same-filesystem via device ids before any rename', str_contains($activate, 'stat -c %d') && str_contains($activate, 'different filesystems'), $failures, $passCount);
check('the activate helper backs up the active runtime aside before swapping in the candidate', str_contains($activate, '.backup-') && str_contains($activate, 'mv -- "$INSTALL_DIR" "$backup_path"'), $failures, $passCount);
check('the activate helper rolls back to the backup if the final swap fails', str_contains($activate, 'mv -- "$backup_path" "$INSTALL_DIR"') && str_contains($activate, 'exit 71'), $failures, $passCount);
check('the activate helper never accepts a free-form command — argv is the staging basename only', !str_contains($activate, 'eval') && !str_contains($activate, '"$@"'), $failures, $passCount);
check('the activate sudoers grants NOPASSWD only for the staging-prefixed argument shape', trim($activateSudoers) === 'deployment-agent ALL=(root) NOPASSWD: /usr/local/sbin/deployment-server-agent-activate staging-*', $failures, $passCount);

check('SelfUpgradeManager invokes the restart helper only through the fixed, argument-free helper argv', str_contains($selfUpgradeManager, "['sudo', '-n', '/usr/local/sbin/deployment-server-agent-restart']"), $failures, $passCount);
check('SelfUpgradeManager invokes the activation helper only through the fixed helper argv plus the staging basename', str_contains($selfUpgradeManager, "['sudo', '-n', '/usr/local/sbin/deployment-server-agent-activate', \$stagingName]"), $failures, $passCount);
check('SelfUpgradeManager never assembles the activation helper argv from anything but the staging basename it generated itself', !str_contains($selfUpgradeManager, 'deployment-server-agent-activate\' . '), $failures, $passCount);
check('SelfUpgradeManager stages inside the pre-provisioned staging root, never a new sibling directly under the install dir\'s own parent', str_contains($selfUpgradeManager, "dirname(\$installDir) . '/deployment-server-agent-staging'") && !str_contains($selfUpgradeManager, "\$installDir . '.staging-'"), $failures, $passCount);
check('SelfUpgradeManager fails closed with an actionable message when the staging area was never provisioned', str_contains($selfUpgradeManager, 'has not been provisioned'), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
