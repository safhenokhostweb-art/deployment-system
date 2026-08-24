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
$upgrade = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(__DIR__ . '/../install/upgrade-runtime.sh'));

check('deployment-system remains an explicit runtime-permissions project key', str_contains($runtime, 'deployment-system)'), $failures, $passCount);
check('saas-platform is an explicit runtime-permissions project key', str_contains($runtime, 'saas-platform)'), $failures, $passCount);
check('customer-site is an explicit runtime-permissions project key', str_contains($runtime, 'customer-site)'), $failures, $passCount);
check('unknown runtime-permissions project keys are rejected with a concise diagnostic', str_contains($runtime, "*) fail \"\$project_key\" validate - 'invalid project key' 65 ;;"), $failures, $passCount);
check('saas-platform runtime permissions use only its application root', str_contains($runtime, 'ROOT=/var/www/saas-platform'), $failures, $passCount);
check('saas-platform runtime permissions include storage only as an approved path', str_contains($runtime, '"$ROOT/storage"'), $failures, $passCount);
check('saas-platform runtime permissions include bootstrap/cache only as an approved path', str_contains($runtime, '"$ROOT/bootstrap/cache"'), $failures, $passCount);
check('saas-platform runtime permissions include the real public/storage directory only as an approved path', str_contains($runtime, '"$ROOT/public/storage"'), $failures, $passCount);
check('runtime helper resolves and confines every approved path below its root', str_contains($runtime, 'realpath -e -- "$path"') && str_contains($runtime, 'case "$resolved" in "$ROOT"/*)'), $failures, $passCount);
check('runtime helper never recursively operates on the whole application root', !str_contains($runtime, 'find -P "$ROOT" '), $failures, $passCount);
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
check('runtime helper confines a project persistent root alongside the release root', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/deployment-system/persistent') && str_contains($runtime, 'case "$resolved" in "$ROOT"/*) return 0') && str_contains($runtime, 'case "$resolved" in "$PERSISTENT_ROOT"|"$PERSISTENT_ROOT"/*) return 0'), $failures, $passCount);
check('runtime helper normalizes the persistent root as one approved path', str_contains($runtime, '"$PERSISTENT_ROOT")'), $failures, $passCount);
check('runtime helper never normalizes a project without persistent configuration', str_contains($runtime, 'PERSISTENT_ROOT=/var/www/deployment-system/persistent') && !str_contains($runtime, 'PERSISTENT_ROOT=/var/www/saas-platform'), $failures, $passCount);

check('deployment-system remains an explicit service-reload project key', str_contains($service, 'deployment-system)'), $failures, $passCount);
check('saas-platform is an explicit service-reload project key', str_contains($service, 'saas-platform)'), $failures, $passCount);
check('unknown service-reload project keys are rejected', str_contains($service, 'exit 65'), $failures, $passCount);
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
check('PipelineExecutor resolves saas runtime permissions only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$job['project_key']]"), $failures, $passCount);
check('PipelineExecutor resolves saas service reload only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-service-reload', \$job['project_key']]"), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
