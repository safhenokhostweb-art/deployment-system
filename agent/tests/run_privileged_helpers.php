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

check('deployment-system remains an explicit runtime-permissions project key', str_contains($runtime, 'deployment-system)'), $failures, $passCount);
check('saas-platform is an explicit runtime-permissions project key', str_contains($runtime, 'saas-platform)'), $failures, $passCount);
check('unknown runtime-permissions project keys are rejected', str_contains($runtime, '*) exit 65 ;;'), $failures, $passCount);
check('saas-platform runtime permissions use only its application root', str_contains($runtime, 'ROOT=/var/www/saas-platform'), $failures, $passCount);
check('saas-platform runtime permissions include storage only as an approved path', str_contains($runtime, '"$ROOT/storage"'), $failures, $passCount);
check('saas-platform runtime permissions include bootstrap/cache only as an approved path', str_contains($runtime, '"$ROOT/bootstrap/cache"'), $failures, $passCount);
check('saas-platform runtime permissions include the real public/storage directory only as an approved path', str_contains($runtime, '"$ROOT/public/storage"'), $failures, $passCount);
check('runtime helper resolves and confines every approved path below its root', str_contains($runtime, 'realpath -e -- "$path"') && str_contains($runtime, 'case "$resolved" in "$ROOT"/*)'), $failures, $passCount);
check('runtime helper never recursively operates on the whole application root', !str_contains($runtime, 'find -P "$ROOT" '), $failures, $passCount);
check('deployment-system helper normalizes the SQLite directory and primary database file', str_contains($runtime, '"$ROOT/database"') && str_contains($runtime, '"$ROOT/database/database.sqlite"'), $failures, $passCount);
check('runtime helper normalizes runtime directories to www-data writable modes', str_contains($runtime, 'chmod 0775 -- "$target"'), $failures, $passCount);
check('runtime helper normalizes SQLite and WAL/SHM/journal files to www-data writable modes', str_contains($runtime, 'chmod 0664 -- "$target"') && str_contains($runtime, '-type f'), $failures, $passCount);
check('runtime helper tolerates only disappearing runtime entries during traversal', str_contains($runtime, '-ignore_readdir_race') && str_contains($runtime, '[ ! -e "$target" ] || exit 1'), $failures, $passCount);
check('runtime sudoers allows only the two literal approved arguments', str_contains($runtimeSudoers, 'deployment-runtime-permissions deployment-system') && str_contains($runtimeSudoers, 'deployment-runtime-permissions saas-platform'), $failures, $passCount);

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
check('unrelated Supervisor programs are absent from the service helper', !str_contains($service, 'customer-site-worker') && !str_contains($service, 'deployment-service-worker'), $failures, $passCount);
check('service sudoers allows only the two literal approved arguments', str_contains($serviceSudoers, 'deployment-service-reload deployment-system') && str_contains($serviceSudoers, 'deployment-service-reload saas-platform'), $failures, $passCount);
check('PipelineExecutor resolves saas runtime permissions only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-runtime-permissions', \$job['project_key']]"), $failures, $passCount);
check('PipelineExecutor resolves saas service reload only through the fixed helper argv', str_contains($pipeline, "['sudo', '-n', '/usr/local/sbin/deployment-service-reload', \$job['project_key']]"), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
