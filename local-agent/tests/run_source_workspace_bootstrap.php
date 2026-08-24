<?php

declare(strict_types=1);

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/InvalidProjectCacheException.php';
require __DIR__ . '/../lib/ProjectConfigCache.php';
require __DIR__ . '/../lib/LocalProjectStateReporter.php';
require __DIR__ . '/../lib/SourceWorkspaceManager.php';

use LocalAgent\GitManager;
use LocalAgent\Logger;
use LocalAgent\LocalProjectStateReporter;
use LocalAgent\ProjectConfigCache;
use LocalAgent\SourceWorkspaceManager;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/source-workspace-bootstrap-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$previousGlobalConfig = getenv('GIT_CONFIG_GLOBAL');
putenv('GIT_CONFIG_GLOBAL=' . $root . '/isolated-global.gitconfig');

$git = new GitManager();
$logger = new Logger($root . '/logs');
$manager = new SourceWorkspaceManager($git, $logger);
$failures = [];
$passed = 0;

$check = static function (string $label, bool $condition) use (&$failures, &$passed): void {
    $condition ? $passed++ : $failures[] = $label;
};
$run = static function (array $command, string $cwd) use ($git): void {
    $result = $git->run($command, $cwd, 60);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . trim($result['output']));
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $entryPath = $path . '/' . $entry;
        if (is_dir($entryPath) && !is_link($entryPath)) {
            $removeTree($entryPath);
        } else {
            @chmod($entryPath, 0666);
            @unlink($entryPath);
        }
    }
    @rmdir($path);
};

try {
    $projectKeys = ['deployment-system', 'saas-platform', 'customer-site'];
    $rawProjects = [];
    $remotePaths = [];

    foreach ($projectKeys as $projectKey) {
        $source = $root . '/fixtures/' . $projectKey;
        $remote = $root . '/remotes/' . $projectKey . '.bundle';
        mkdir($source, 0700, true);
        if (!is_dir(dirname($remote))) {
            mkdir(dirname($remote), 0700, true);
        }
        $run(['git', 'init', '--initial-branch=main'], $source);
        $run(['git', 'config', 'user.email', 'agent@example.test'], $source);
        $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $source);
        file_put_contents($source . '/project.txt', $projectKey . "\n");
        $run(['git', 'add', 'project.txt'], $source);
        $run(['git', 'commit', '-m', 'fixture'], $source);
        // A bundle is a complete local Git remote and avoids any network or
        // platform-specific local transport helper in this contract test.
        $run(['git', 'bundle', 'create', $remote, 'main'], $source);

        $remotePaths[$projectKey] = $remote;
        $rawProjects[] = [
            'project_key' => $projectKey,
            'source_workspace_path' => $root . '/workspaces/' . $projectKey,
            'repository_url' => $remote,
            'remote_name' => 'origin',
            'branch' => 'main',
            'application_root' => '.',
        ];
    }

    // The same response-to-cache path used by a real Hub sync: the cache
    // knows customer-site's path while no workspace directory exists yet.
    $cache = new ProjectConfigCache($root . '/cache/projects.json');
    $cache->save(ProjectConfigCache::fromApiResponse($rawProjects));
    $cachedProjects = $cache->load() ?? [];
    $created = $manager->reconcile($cachedProjects);

    foreach ($projectKeys as $projectKey) {
        $workspace = $root . '/workspaces/' . $projectKey;
        $check("first sync creates {$projectKey} workspace", ($created[$projectKey] ?? null) === 'created' && is_dir($workspace . '/.git'));
        $check("{$projectKey} checks out configured branch", $git->currentBranch($workspace) === 'main');
        $check("{$projectKey} reports a valid SHA", preg_match('/^[0-9a-f]{40}$/', $git->lastCommitHash($workspace)) === 1);
    }

    $reportedStates = (new LocalProjectStateReporter())->collect($git, $cachedProjects, $logger);
    $reportedKeys = array_column($reportedStates, 'project_key');
    $safeDirectoryResult = $git->run(['git', 'config', '--global', '--get-all', 'safe.directory'], $root, 30);
    $safeDirectories = preg_split('/\r?\n/', trim($safeDirectoryResult['output'])) ?: [];
    foreach ($projectKeys as $projectKey) {
        $check("heartbeat state includes {$projectKey} after bootstrap", in_array($projectKey, $reportedKeys, true));
        $expectedPath = $root . '/workspaces/' . $projectKey;
        $check("{$projectKey} is registered as safe.directory", in_array($expectedPath, array_map(static fn (string $path): string => str_replace('\\', '/', $path), $safeDirectories), true));
        $check("{$projectKey} workspace is writable by the Agent user", is_writable($expectedPath));
    }

    // Prove healthy reconciliation performs no clone/fetch: make the remote
    // unavailable and preserve an untracked marker in the existing checkout.
    $existingKey = 'deployment-system';
    $existingWorkspace = $root . '/workspaces/' . $existingKey;
    file_put_contents($existingWorkspace . '/reuse-marker.txt', 'keep');
    $unavailableRemote = $remotePaths[$existingKey] . '.offline';
    rename($remotePaths[$existingKey], $unavailableRemote);
    $reused = $manager->reconcileProject($existingKey, $cachedProjects[$existingKey]);
    $check('existing workspace is reused without cloning again', $reused === 'reused');
    $check('existing workspace content survives reuse', is_file($existingWorkspace . '/reuse-marker.txt'));
    rename($unavailableRemote, $remotePaths[$existingKey]);

    // A failed repair must put the original workspace back exactly where it
    // was; network failure may leave diagnostic clone data, but never remove
    // or strand the pre-existing directory in quarantine.
    $failedRepairKey = 'saas-platform';
    $failedRepairWorkspace = $root . '/workspaces/' . $failedRepairKey;
    $removeTree($failedRepairWorkspace);
    mkdir($failedRepairWorkspace, 0700, true);
    file_put_contents($failedRepairWorkspace . '/must-survive.txt', 'preserve on clone failure');
    $failedRepairRemote = $remotePaths[$failedRepairKey] . '.offline';
    rename($remotePaths[$failedRepairKey], $failedRepairRemote);
    $cloneFailureRaised = false;
    try {
        $manager->reconcileProject($failedRepairKey, $cachedProjects[$failedRepairKey]);
    } catch (RuntimeException) {
        $cloneFailureRaised = true;
    }
    $check('network/clone failure is surfaced', $cloneFailureRaised);
    $check('network/clone failure restores the existing workspace and its data', is_file($failedRepairWorkspace . '/must-survive.txt'));
    rename($failedRepairRemote, $remotePaths[$failedRepairKey]);

    // A non-Git directory is preserved in quarantine, then replaced by a
    // verified clone at the authoritative path.
    $corruptKey = 'customer-site';
    $corruptWorkspace = $root . '/workspaces/' . $corruptKey;
    $removeTree($corruptWorkspace);
    mkdir($corruptWorkspace, 0700, true);
    file_put_contents($corruptWorkspace . '/operator-data.txt', 'preserve me');
    $repair = $manager->reconcileProject($corruptKey, $cachedProjects[$corruptKey]);
    $quarantines = glob($root . '/workspaces/.workspace-repair-customer-site-*') ?: [];
    $preserved = false;
    foreach ($quarantines as $quarantine) {
        $preserved = $preserved || is_file($quarantine . '/operator-data.txt');
    }
    $check('corrupt workspace is repaired with a verified clone', $repair === 'repaired' && is_dir($corruptWorkspace . '/.git'));
    $check('corrupt workspace data is preserved in recoverable quarantine', $preserved);

    $productionRejected = false;
    try {
        $manager->reconcileProject('customer-site', [
            'source_workspace_path' => '/var/www/customer-site',
            'repository_url' => $remotePaths['customer-site'],
            'remote_name' => 'origin',
            'branch' => 'main',
        ]);
    } catch (RuntimeException) {
        $productionRejected = true;
    }
    $check('production_path cannot be used as a source workspace', $productionRejected);

    $traversalRejected = false;
    try {
        $manager->reconcileProject('customer-site', [
            'source_workspace_path' => '/opt/deployment-agent/workspaces/../../../var/www/customer-site',
            'repository_url' => $remotePaths['customer-site'],
            'remote_name' => 'origin',
            'branch' => 'main',
        ]);
    } catch (RuntimeException) {
        $traversalRejected = true;
    }
    $check('path traversal cannot disguise a production source path', $traversalRejected);

    $mirror = __DIR__ . '/../../deployment-local-agent/agent/lib/SourceWorkspaceManager.php';
    $check('shipped Local Agent manager mirrors source implementation', hash_file('sha256', __DIR__ . '/../lib/SourceWorkspaceManager.php') === hash_file('sha256', $mirror));
} finally {
    $previousGlobalConfig === false ? putenv('GIT_CONFIG_GLOBAL') : putenv('GIT_CONFIG_GLOBAL=' . $previousGlobalConfig);
    $removeTree($root);
}

if ($failures !== []) {
    fwrite(STDERR, "Source workspace bootstrap failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Source workspace bootstrap: {$passed} passed, 0 failed.\n";
