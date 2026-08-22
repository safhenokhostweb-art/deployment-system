<?php

declare(strict_types=1);

require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';

use LocalAgent\BuildWorkspace;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\WorkspaceSourcePreparer;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/source-workspace-contract-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$git = new GitManager();
$failures = [];
$passed = 0;

$run = static function (array $command, string $cwd) use ($git): void {
    $result = $git->run($command, $cwd, 60);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . $result['output']);
    }
};
$check = static function (string $label, bool $ok) use (&$failures, &$passed): void {
    $ok ? $passed++ : $failures[] = $label;
};

try {
    mkdir($root . '/source');
    $source = $root . '/source';
    $run(['git', 'init'], $source);
    $run(['git', 'config', 'user.email', 'agent@example.test'], $source);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $source);
    file_put_contents($source . '/tracked.txt', "from-github\n");
    $run(['git', 'add', 'tracked.txt'], $source);
    $run(['git', 'commit', '-m', 'fixture'], $source);
    $branch = $git->currentBranch($source);
    $sha = $git->lastCommitHash($source);
    $bundle = $root . '/remote.bundle';
    $run(['git', 'bundle', 'create', $bundle, $branch], $source);
    $run(['git', 'remote', 'add', 'origin', $bundle], $source);
    $run(['git', 'fetch', 'origin', $branch], $source);

    // This local-only file must never leak into an exact-commit artifact checkout.
    file_put_contents($source . '/production-only-leak.txt', 'must not ship');

    $workspace = BuildWorkspace::create($root . '/builds', 'customer-site', 'exact-sha');
    $prepared = (new WorkspaceSourcePreparer($git))->prepareRemoteCommit($workspace, $source, 'origin', $branch, $sha);

    $check('exact requested SHA is checked out inside the isolated build workspace', $git->lastCommitHash($workspace->projectDir()) === $sha);
    $check('tracked commit content exists in the workspace checkout', trim((string) file_get_contents($workspace->projectDir() . '/tracked.txt')) === 'from-github');
    $check('uncommitted/source-only content cannot enter the artifact workspace', !file_exists($workspace->projectDir() . '/production-only-leak.txt'));
    $check('commit timestamp is captured for deterministic artifact metadata', $prepared['commitTimestamp'] > 0);
    $workspace->cleanup();

    $forbidden = BuildWorkspace::create($root . '/builds', 'customer-site', 'forbidden-source');
    try {
        (new WorkspaceSourcePreparer($git))->prepareRemoteCommit($forbidden, '/var/www/customer-site', 'origin', $branch, $sha);
        $check('production path cannot prepare an artifact', false);
    } catch (LocalBuildFailedException $e) {
        $check('production path cannot prepare an artifact', $e->failureCode === 'production_source_forbidden');
    } finally {
        $forbidden->cleanup();
    }
} finally {
    if (is_dir($root)) {
        // Test-only temp tree, resolved and constrained to the system temp root.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Source workspace contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Source workspace contract: {$passed} passed, 0 failed.\n";
