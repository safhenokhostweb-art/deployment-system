<?php

declare(strict_types=1);

/**
 * Regression coverage for the persistent-workspace branch-mismatch fix: a
 * correct, correctly-remoted checkout on a branch other than the Hub's
 * expected one must be preserved exactly as found (no quarantine, no
 * reclone, no checkout/reset/switch) — real developer workspaces
 * (source_workspace_path points directly at the developer's own working
 * directory) may legitimately be on a different branch mid-work. A path
 * that is genuinely invalid (not a Git repo, or the wrong repository) must
 * still go through the existing quarantine-then-reclone repair unchanged.
 *
 * Build-time safety for this is covered separately and is NOT re-tested
 * here: run_source_workspace_contract.php already proves
 * WorkspaceSourcePreparer::prepareRemoteCommit() independently rejects a
 * branch mismatch (its own 'Source workspace branch mismatch' case)
 * regardless of what this class classifies a workspace as at sync time.
 */

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/InvalidProjectCacheException.php';
require __DIR__ . '/../lib/ProjectConfigCache.php';
require __DIR__ . '/../lib/SourceWorkspaceManager.php';

use LocalAgent\GitManager;
use LocalAgent\Logger;
use LocalAgent\ProjectConfigCache;
use LocalAgent\SourceWorkspaceManager;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/source-workspace-branch-preservation-' . bin2hex(random_bytes(6));
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
$noReconciliationArtifacts = static function (string $workspaceRoot, string $baseName) use ($root): bool {
    $repair = glob($workspaceRoot . '/.workspace-repair-' . $baseName . '-*') ?: [];
    $clone = glob($workspaceRoot . '/.workspace-clone-' . $baseName . '-*') ?: [];

    return $repair === [] && $clone === [];
};

try {
    $projectKey = 'saas-platform';
    $source = $root . '/fixtures/' . $projectKey;
    $remote = $root . '/remotes/' . $projectKey . '.bundle';
    $workspaceRoot = $root . '/workspaces';
    mkdir($source, 0700, true);
    mkdir(dirname($remote), 0700, true);
    $run(['git', 'init', '--initial-branch=main'], $source);
    $run(['git', 'config', 'user.email', 'agent@example.test'], $source);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $source);
    file_put_contents($source . '/project.txt', "fixture\n");
    $run(['git', 'add', 'project.txt'], $source);
    $run(['git', 'commit', '-m', 'fixture'], $source);
    $run(['git', 'bundle', 'create', $remote, 'main'], $source);

    $projectConfig = ProjectConfigCache::fromApiResponse([[
        'project_key' => $projectKey,
        'source_workspace_path' => $workspaceRoot . '/' . $projectKey,
        'repository_url' => $remote,
        'remote_name' => 'origin',
        'branch' => 'main',
    ]])[$projectKey];

    // --- 1: correct repo + expected branch => reused ---
    $created = $manager->reconcileProject($projectKey, $projectConfig);
    $workspace = $workspaceRoot . '/' . $projectKey;
    $check('1: fresh clone on the expected branch is created', $created === 'created' && $git->currentBranch($workspace) === 'main');
    $reused = $manager->reconcileProject($projectKey, $projectConfig);
    $check('1: correct repo + expected branch is reused, not recreated', $reused === 'reused');

    // Move the fixture workspace onto a different real, legitimate branch —
    // exactly the audit/review-branch scenario found on the real machine.
    $run(['git', 'checkout', '-b', 'audit/artifact-deployment-readiness'], $workspace);
    $headBeforeAnyTest = $git->lastCommitHash($workspace);

    // --- 2: different branch + clean tree => preserved, no quarantine ---
    $result = $manager->reconcileProject($projectKey, $projectConfig);
    $check('2: different branch (clean tree) is preserved, not quarantined', $result === 'preserved');
    $check('2: no quarantine/clone artifact was created', $noReconciliationArtifacts($workspaceRoot, $projectKey));
    $check('2: branch is untouched', $git->currentBranch($workspace) === 'audit/artifact-deployment-readiness');
    $check('2: HEAD is untouched', $git->lastCommitHash($workspace) === $headBeforeAnyTest);

    // --- 3: different branch + modified tracked file => preserved ---
    file_put_contents($workspace . '/project.txt', "modified by developer\n");
    $result = $manager->reconcileProject($projectKey, $projectConfig);
    $check('3: different branch with modified file is preserved', $result === 'preserved');
    $check('3: no quarantine/clone artifact was created', $noReconciliationArtifacts($workspaceRoot, $projectKey));
    $check('3: modified content survives unchanged', file_get_contents($workspace . '/project.txt') === "modified by developer\n");
    $check('3: HEAD is untouched (no reset)', $git->lastCommitHash($workspace) === $headBeforeAnyTest);

    // --- 4: different branch + untracked file => preserved ---
    file_put_contents($workspace . '/untracked-scratch.txt', "developer scratch file\n");
    $result = $manager->reconcileProject($projectKey, $projectConfig);
    $check('4: different branch with untracked file is preserved', $result === 'preserved');
    $check('4: no quarantine/clone artifact was created', $noReconciliationArtifacts($workspaceRoot, $projectKey));
    $check('4: untracked file survives unchanged (no clean)', is_file($workspace . '/untracked-scratch.txt') && file_get_contents($workspace . '/untracked-scratch.txt') === "developer scratch file\n");

    // --- 5: different branch + staged file => preserved ---
    $run(['git', 'add', 'project.txt'], $workspace);
    $statusBefore = $git->run(['git', 'status', '--porcelain'], $workspace, 30)['output'];
    $result = $manager->reconcileProject($projectKey, $projectConfig);
    $statusAfter = $git->run(['git', 'status', '--porcelain'], $workspace, 30)['output'];
    $check('5: different branch with staged file is preserved', $result === 'preserved');
    $check('5: no quarantine/clone artifact was created', $noReconciliationArtifacts($workspaceRoot, $projectKey));
    $check('5: staged index state is byte-identical after reconciliation', $statusBefore === $statusAfter);
    $check('5: HEAD is still untouched', $git->lastCommitHash($workspace) === $headBeforeAnyTest);

    // --- 6: correct repo, but WRONG remote => still genuinely invalid (existing repair path) ---
    $wrongRemoteKey = 'customer-site';
    $wrongRemoteWorkspace = $workspaceRoot . '/' . $wrongRemoteKey;
    $otherRemoteSource = $root . '/fixtures/other-remote';
    $otherRemote = $root . '/remotes/other-remote.bundle';
    mkdir($otherRemoteSource, 0700, true);
    $run(['git', 'init', '--initial-branch=main'], $otherRemoteSource);
    $run(['git', 'config', 'user.email', 'agent@example.test'], $otherRemoteSource);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $otherRemoteSource);
    file_put_contents($otherRemoteSource . '/other.txt', "other\n");
    $run(['git', 'add', 'other.txt'], $otherRemoteSource);
    $run(['git', 'commit', '-m', 'other fixture'], $otherRemoteSource);
    $run(['git', 'bundle', 'create', $otherRemote, 'main'], $otherRemoteSource);
    // Clone from the WRONG remote so remote.origin.url genuinely mismatches
    // what the Hub expects for customer-site — a real, distinct repository,
    // not just a different branch of the right one.
    $run(['git', 'clone', '--origin', 'origin', '--branch', 'main', '--', $otherRemote, $wrongRemoteWorkspace], $root);
    $customerSiteConfig = ProjectConfigCache::fromApiResponse([[
        'project_key' => $wrongRemoteKey,
        'source_workspace_path' => $wrongRemoteWorkspace,
        'repository_url' => $remote,
        'remote_name' => 'origin',
        'branch' => 'main',
    ]])[$wrongRemoteKey];
    $result = $manager->reconcileProject($wrongRemoteKey, $customerSiteConfig);
    $wrongRemoteQuarantines = glob($workspaceRoot . '/.workspace-repair-' . $wrongRemoteKey . '-*') ?: [];
    $check('6: wrong remote is still treated as genuinely invalid', $result === 'repaired');
    $check('6: wrong-remote workspace was quarantined (not silently preserved)', $wrongRemoteQuarantines !== []);
    $check('6: repaired workspace now points at the correct remote', $git->currentBranch($wrongRemoteWorkspace) === 'main' && is_file($wrongRemoteWorkspace . '/project.txt'));

    // --- 7: non-Git directory => existing safe behavior preserved ---
    $nonGitKey = 'non-git-project';
    $nonGitWorkspace = $workspaceRoot . '/' . $nonGitKey;
    mkdir($nonGitWorkspace, 0700, true);
    file_put_contents($nonGitWorkspace . '/operator-data.txt', "preserve me\n");
    $nonGitConfig = ProjectConfigCache::fromApiResponse([[
        'project_key' => $nonGitKey,
        'source_workspace_path' => $nonGitWorkspace,
        'repository_url' => $remote,
        'remote_name' => 'origin',
        'branch' => 'main',
    ]])[$nonGitKey];
    $result = $manager->reconcileProject($nonGitKey, $nonGitConfig);
    $nonGitQuarantines = glob($workspaceRoot . '/.workspace-repair-' . $nonGitKey . '-*') ?: [];
    $preserved = false;
    foreach ($nonGitQuarantines as $quarantine) {
        $preserved = $preserved || is_file($quarantine . '/operator-data.txt');
    }
    $check('7: non-Git directory is still repaired via quarantine + clone', $result === 'repaired' && is_dir($nonGitWorkspace . '/.git'));
    $check('7: non-Git directory data is preserved in quarantine', $preserved);

    $mirror = __DIR__ . '/../../deployment-local-agent/agent/lib/SourceWorkspaceManager.php';
    $check('shipped Local Agent manager mirrors source implementation', hash_file('sha256', __DIR__ . '/../lib/SourceWorkspaceManager.php') === hash_file('sha256', $mirror));
} finally {
    $previousGlobalConfig === false ? putenv('GIT_CONFIG_GLOBAL') : putenv('GIT_CONFIG_GLOBAL=' . $previousGlobalConfig);
    $removeTree($root);
}

if ($failures !== []) {
    fwrite(STDERR, "Source workspace branch preservation failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Source workspace branch preservation: {$passed} passed, 0 failed.\n";
