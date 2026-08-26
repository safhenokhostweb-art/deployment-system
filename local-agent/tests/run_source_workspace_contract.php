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
$preparer = new WorkspaceSourcePreparer($git);
$failures = [];
$passed = 0;

$run = static function (array $command, string $cwd) use ($git): string {
    $result = $git->run($command, $cwd, 60);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . $result['output']);
    }

    return trim($result['output']);
};
$check = static function (string $label, bool $ok) use (&$failures, &$passed): void {
    $ok ? $passed++ : $failures[] = $label;
};
$expectPrepareFailure = static function (callable $operation, string $messageFragment) use ($check): void {
    try {
        $operation();
        $check($messageFragment, false);
    } catch (LocalBuildFailedException $e) {
        $check($messageFragment, $e->failureCode === 'local_workspace_prepare_failed' && str_contains($e->getMessage(), $messageFragment));
    }
};

try {
    $seed = $root . '/seed';
    $remote = $root . '/remote.git';
    $source = $root . '/authoritative-source';
    mkdir($seed, 0700, true);
    $run(['git', 'init', '--initial-branch=main'], $seed);
    $run(['git', 'config', 'user.email', 'agent@example.test'], $seed);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $seed);
    file_put_contents($seed . '/tracked.txt', "commit-a\n");
    $run(['git', 'add', 'tracked.txt'], $seed);
    $run(['git', 'commit', '-m', 'commit a'], $seed);
    $commitA = $git->lastCommitHash($seed);

    $run(['git', 'clone', '--bare', $seed, $remote], $root);
    $run(['git', 'clone', '--branch', 'main', $remote, $source], $root);
    $run(['git', 'remote', 'add', 'publish', $remote], $seed);

    // Advance the remote after the persistent source was cloned. Its HEAD
    // and origin/main are now both clean but stale at commit A.
    file_put_contents($seed . '/tracked.txt', "commit-b\n");
    $run(['git', 'add', 'tracked.txt'], $seed);
    $run(['git', 'commit', '-m', 'commit b'], $seed);
    $commitB = $git->lastCommitHash($seed);
    $run(['git', 'push', 'publish', 'main'], $seed);

    $check('stale fixture starts at the old clean HEAD', $git->lastCommitHash($source) === $commitA && $git->changedFiles($source) === []);
    $check('stale fixture starts with an old remote-tracking ref', $run(['git', 'rev-parse', 'refs/remotes/origin/main'], $source) === $commitA);

    $workspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'stale-refresh');
    $prepared = $preparer->prepareRemoteCommit($workspace, $source, $remote, 'origin', 'main', $commitB);

    $check('clean stale workspace fetches the configured remote branch before build', $run(['git', 'rev-parse', 'refs/remotes/origin/main'], $source) === $commitB);
    $check('safe fetch does not move the persistent source HEAD', $git->lastCommitHash($source) === $commitA);
    $check('requested commit becomes reachable from the refreshed remote branch', $git->run(['git', 'merge-base', '--is-ancestor', $commitB, 'refs/remotes/origin/main'], $source, 30)['exitCode'] === 0);
    $check('exact requested commit is checked out only in the isolated build workspace', $git->lastCommitHash($workspace->projectDir()) === $commitB);
    $check('isolated build contains exact requested commit content', trim((string) file_get_contents($workspace->projectDir() . '/tracked.txt')) === 'commit-b');
    $check('commit timestamp is captured for deterministic artifact metadata', $prepared['commitTimestamp'] > 0);
    $workspace->cleanup();

    // Dirty state must fail before fetch, so even the remote-tracking ref is
    // left unchanged. Nothing is reset or discarded.
    file_put_contents($seed . '/tracked.txt', "commit-c\n");
    $run(['git', 'add', 'tracked.txt'], $seed);
    $run(['git', 'commit', '-m', 'commit c'], $seed);
    $commitC = $git->lastCommitHash($seed);
    $run(['git', 'push', 'publish', 'main'], $seed);
    file_put_contents($source . '/operator-work.txt', 'must survive');
    $dirtyWorkspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'dirty');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($dirtyWorkspace, $source, $remote, 'origin', 'main', $commitC),
        'Source workspace is dirty',
    );
    $check('dirty workspace is not fetched or modified', $run(['git', 'rev-parse', 'refs/remotes/origin/main'], $source) === $commitB && is_file($source . '/operator-work.txt'));
    @unlink($source . '/operator-work.txt');
    $dirtyWorkspace->cleanup();

    $wrongRepository = BuildWorkspace::create($root . '/builds', 'saas-platform', 'wrong-repository');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($wrongRepository, $source, $root . '/another.git', 'origin', 'main', $commitC),
        'Source workspace repository mismatch',
    );
    $wrongRepository->cleanup();

    $wrongBranch = BuildWorkspace::create($root . '/builds', 'saas-platform', 'wrong-branch');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($wrongBranch, $source, $remote, 'origin', 'release', $commitC),
        'Source workspace branch mismatch',
    );
    $wrongBranch->cleanup();

    // A clean local-only commit is divergence, not staleness. Fetching the
    // remote ref is safe, but the persistent branch is never reset.
    $run(['git', 'config', 'user.email', 'agent@example.test'], $source);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $source);
    file_put_contents($source . '/local-only.txt', "preserve\n");
    $run(['git', 'add', 'local-only.txt'], $source);
    $run(['git', 'commit', '-m', 'local only'], $source);
    $divergedHead = $git->lastCommitHash($source);
    $divergedWorkspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'diverged');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($divergedWorkspace, $source, $remote, 'origin', 'main', $commitC),
        'Source workspace branch has diverged',
    );
    $check('diverged persistent branch is never reset', $git->lastCommitHash($source) === $divergedHead && is_file($source . '/local-only.txt'));
    $divergedWorkspace->cleanup();

    $forbidden = BuildWorkspace::create($root . '/builds', 'saas-platform', 'forbidden-source');
    try {
        $preparer->prepareRemoteCommit($forbidden, '/var/www/saas-platform', $remote, 'origin', 'main', $commitC);
        $check('production path remains forbidden as an Artifact source', false);
    } catch (LocalBuildFailedException $e) {
        $check('production path remains forbidden as an Artifact source', $e->failureCode === 'production_source_forbidden');
    } finally {
        $forbidden->cleanup();
    }

    $mirror = __DIR__ . '/../../deployment-local-agent/agent/lib/WorkspaceSourcePreparer.php';
    $check('shipped WorkspaceSourcePreparer mirrors source implementation', hash_file('sha256', __DIR__ . '/../lib/WorkspaceSourcePreparer.php') === hash_file('sha256', $mirror));
} finally {
    if (is_dir($root)) {
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
