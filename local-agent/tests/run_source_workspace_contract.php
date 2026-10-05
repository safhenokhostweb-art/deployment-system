<?php

declare(strict_types=1);

require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/RetryPolicy.php';
require __DIR__ . '/../lib/GitTransportFailureClassifier.php';
require __DIR__ . '/../lib/SecretRedactor.php';
require __DIR__ . '/../lib/RetryableGitFetch.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/ScopedSshConfig.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';

use LocalAgent\BuildWorkspace;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\WorkspaceSourcePreparer;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/source-workspace-contract-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$previousGlobalConfig = getenv('GIT_CONFIG_GLOBAL');
putenv('GIT_CONFIG_GLOBAL=' . $root . '/isolated-global.gitconfig');
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

    $check('primary build never fetches or mutates the persistent workspace remote ref', $run(['git', 'rev-parse', 'refs/remotes/origin/main'], $source) === $commitA);
    $check('safe fetch does not move the persistent source HEAD', $git->lastCommitHash($source) === $commitA);
    $check('requested commit need not be present in the persistent workspace', $git->run(['git', 'cat-file', '-e', $commitB . '^{commit}'], $source, 30)['exitCode'] !== 0);
    $check('exact requested commit is checked out only in the isolated build workspace', $git->lastCommitHash($workspace->projectDir()) === $commitB);
    $check('isolated build contains exact requested commit content', trim((string) file_get_contents($workspace->projectDir() . '/tracked.txt')) === 'commit-b');
    $check('commit timestamp is captured for deterministic artifact metadata', $prepared['commitTimestamp'] > 0);
    $check('tracked source inventory is captured from the immutable checkout', in_array('tracked.txt', $prepared['sourcePaths'] ?? [], true) && !in_array('.git/HEAD', $prepared['sourcePaths'] ?? [], true));
    $workspace->cleanup();

    // Dirty state fails without touching the remote-tracking ref. Nothing is
    // fetched, reset, or discarded in the persistent workspace.
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
    $check('dirty workspace is not fetched or modified', $run(['git', 'rev-parse', 'refs/remotes/origin/main'], $source) === $commitA && is_file($source . '/operator-work.txt'));
    @unlink($source . '/operator-work.txt');
    $dirtyWorkspace->cleanup();

    $wrongRepository = BuildWorkspace::create($root . '/builds', 'saas-platform', 'wrong-repository');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($wrongRepository, $source, $root . '/another.git', 'origin', 'main', $commitC),
        'repository_identity_mismatch',
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

    // A validated GitHub mapping uses the Agent's canonical authenticated SSH
    // endpoint. The isolated test config rewrites only that endpoint to the
    // local bare fixture, proving transport choice without network access.
    $transportSource = $root . '/transport-source';
    $run(['git', 'clone', '--branch', 'main', $remote, $transportSource], $root);
    $run(['git', 'reset', '--hard', $commitA], $transportSource);
    file_put_contents($transportSource . '/.git/info/exclude', "persistent-only.txt\n", FILE_APPEND);
    file_put_contents($transportSource . '/persistent-only.txt', 'must never enter artifact source');
    $httpsRepository = 'https://github.com/example/primary.git';
    $canonicalSsh = 'ssh://git@ssh.github.com:443/example/primary.git';
    $run(['git', 'remote', 'set-url', 'origin', $httpsRepository], $transportSource);
    $run(['git', 'config', '--global', 'url.' . $remote . '.insteadOf', $canonicalSsh], $root);

    $httpsWorkspace = BuildWorkspace::create($root . '/builds', 'primary', 'https-to-ssh');
    $preparer->prepareRemoteCommit($httpsWorkspace, $transportSource, $httpsRepository, 'origin', 'main', $commitC, null, [], [], [], 'customer-site');
    $check('validated primary HTTPS mapping materializes through canonical SSH', $run(['git', 'config', '--get', 'remote.origin.url'], $httpsWorkspace->projectDir()) === $canonicalSsh);
    $check('stale persistent HEAD does not control exact isolated checkout', $git->lastCommitHash($transportSource) === $commitA && $git->lastCommitHash($httpsWorkspace->projectDir()) === $commitC);
    $check('persistent workspace files never become Artifact source', !is_file($httpsWorkspace->projectDir() . '/persistent-only.txt'));
    $check('primary materialization does not mutate stored HTTPS remote', $run(['git', 'config', '--get', 'remote.origin.url'], $transportSource) === $httpsRepository);
    $httpsWorkspace->cleanup();

    $run(['git', 'remote', 'set-url', 'origin', $canonicalSsh], $transportSource);
    $sshWorkspace = BuildWorkspace::create($root . '/builds', 'primary', 'ssh-valid');
    $preparer->prepareRemoteCommit($sshWorkspace, $transportSource, $httpsRepository, 'origin', 'main', $commitC, null, [], [], [], 'customer-site');
    $check('correct SSH workspace remote remains valid', $git->lastCommitHash($sshWorkspace->projectDir()) === $commitC);
    $sshWorkspace->cleanup();

    $run(['git', 'remote', 'set-url', 'origin', 'https://github.com/example/different.git'], $transportSource);
    $mismatchWorkspace = BuildWorkspace::create($root . '/builds', 'primary', 'identity-mismatch');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($mismatchWorkspace, $transportSource, $httpsRepository, 'origin', 'main', $commitC, null, [], [], [], 'customer-site'),
        'repository_identity_mismatch',
    );
    $mismatchWorkspace->cleanup();

    $credentialUrl = 'https://agent-user:super-secret-token@github.com/example/primary.git';
    $run(['git', 'remote', 'set-url', 'origin', $credentialUrl], $transportSource);
    $run(['git', 'config', '--global', '--unset-all', 'url.' . $remote . '.insteadOf'], $root);
    $run(['git', 'config', '--global', 'url.' . $root . '/missing.git.insteadOf', $canonicalSsh], $root);
    $diagnosticWorkspace = BuildWorkspace::create($root . '/builds', 'primary', 'safe-diagnostic');
    try {
        $preparer->prepareRemoteCommit($diagnosticWorkspace, $transportSource, $credentialUrl, 'origin', 'main', $commitC, null, [], [], [], 'customer-site');
        $check('primary fetch diagnostic is produced', false);
    } catch (LocalBuildFailedException $e) {
        $message = $e->getMessage();
        $check('primary fetch diagnostic is useful and secret-safe',
            str_contains($message, 'mapping=customer-site')
            && str_contains($message, 'branch=main')
            && str_contains($message, 'repository_identity=github.com/example/primary')
            && str_contains($message, 'transport=ssh')
            && str_contains($message, 'exit_code=')
            && str_contains($message, 'timed_out=false')
            && str_contains($message, 'stderr_category=repository_not_found')
            && !str_contains($message, 'super-secret-token')
            && !str_contains($message, $credentialUrl));
    }
    $diagnosticWorkspace->cleanup();

    $forbidden = BuildWorkspace::create($root . '/builds', 'saas-platform', 'forbidden-source');
    try {
        $preparer->prepareRemoteCommit($forbidden, '/var/www/saas-platform', $remote, 'origin', 'main', $commitC);
        $check('production path remains forbidden as an Artifact source', false);
    } catch (LocalBuildFailedException $e) {
        $check('production path remains forbidden as an Artifact source', $e->failureCode === 'production_source_forbidden');
    } finally {
        $forbidden->cleanup();
    }

    // ============================================================
    // Regression (production incident): a Composer "path" repository must
    // be validated and resolved against the FULLY PREPARED exact-commit
    // build source (the isolated checkout in the BuildWorkspace), never
    // against an older, stale persistent source workspace HEAD — even
    // when that persistent workspace is clean and correctly remoted/
    // branched. Covers scenarios A-F from the audit.
    // ============================================================
    $pkgSeed = $root . '/pkg-seed';
    $pkgRemote = $root . '/pkg-remote.git';
    $pkgSource = $root . '/pkg-authoritative-source';
    mkdir($pkgSeed, 0700, true);
    $run(['git', 'init', '--initial-branch=main'], $pkgSeed);
    $run(['git', 'config', 'user.email', 'agent@example.test'], $pkgSeed);
    $run(['git', 'config', 'user.name', 'Deployment Agent Test'], $pkgSeed);
    file_put_contents($pkgSeed . '/composer.json', json_encode(['name' => 'fixture/app'], JSON_PRETTY_PRINT));
    $run(['git', 'add', '-A'], $pkgSeed);
    $run(['git', 'commit', '-m', 'old commit, no path repository yet'], $pkgSeed);
    $pkgCommitOld = $git->lastCommitHash($pkgSeed);

    $run(['git', 'clone', '--bare', $pkgSeed, $pkgRemote], $root);
    $run(['git', 'clone', '--branch', 'main', $pkgRemote, $pkgSource], $root);
    $run(['git', 'remote', 'add', 'publish', $pkgRemote], $pkgSeed);

    // Advance the remote past the point the persistent source was cloned
    // at: the new commit introduces a Composer "path" repository nested
    // INSIDE the same repository tree (a monorepo package layout) — never
    // a sibling directory outside it. The persistent workspace above is
    // never touched again, so it structurally cannot have this directory.
    mkdir($pkgSeed . '/packages/theme-runtime/src', 0700, true);
    file_put_contents($pkgSeed . '/packages/theme-runtime/composer.json', json_encode(['name' => 'fixture/theme-runtime'], JSON_PRETTY_PRINT));
    file_put_contents($pkgSeed . '/packages/theme-runtime/src/Widget.php', "<?php\nclass Widget {}\n");
    file_put_contents($pkgSeed . '/composer.json', json_encode([
        'name' => 'fixture/app',
        'repositories' => [['type' => 'path', 'url' => 'packages/theme-runtime']],
        'require' => ['fixture/theme-runtime' => '*'],
        'minimum-stability' => 'dev',
    ], JSON_PRETTY_PRINT));
    $run(['git', 'add', '-A'], $pkgSeed);
    $run(['git', 'commit', '-m', 'introduce theme-runtime path repository'], $pkgSeed);
    $pkgCommitNew = $git->lastCommitHash($pkgSeed);
    $run(['git', 'push', 'publish', 'main'], $pkgSeed);

    $check('regression fixture: persistent workspace is stale and predates the path repository', $git->lastCommitHash($pkgSource) === $pkgCommitOld && !is_dir($pkgSource . '/packages/theme-runtime'));

    // A: persistent workspace is old and lacks the path package; the
    // target commit introduces it. Build must succeed from the exact
    // target checkout.
    $pkgWorkspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'stale-path-repo');
    $pkgThrew = null;
    try {
        $preparer->prepareRemoteCommit($pkgWorkspace, $pkgSource, $pkgRemote, 'origin', 'main', $pkgCommitNew);
    } catch (\Throwable $e) {
        $pkgThrew = $e;
    }
    $check('A: build succeeds from the exact target commit even though the persistent workspace never advanced', $pkgThrew === null);
    if ($pkgThrew !== null) {
        fwrite(STDERR, 'Unexpected failure in scenario A: ' . $pkgThrew->getMessage() . "\n");
    }
    $check('A: the path repository is materialized in the isolated build workspace from the exact target commit', is_file($pkgWorkspace->projectDir() . '/packages/theme-runtime/src/Widget.php'));
    $check('A: the isolated workspace content matches the exact target commit', str_contains((string) file_get_contents($pkgWorkspace->projectDir() . '/packages/theme-runtime/src/Widget.php'), 'class Widget'));

    // E: the persistent source workspace is never required to track remote
    // HEAD — it is completely untouched by a successful build.
    $check('E: persistent workspace HEAD is unchanged after a successful build', $git->lastCommitHash($pkgSource) === $pkgCommitOld);
    $check('E: persistent workspace working tree still lacks the package the build did not need from it', !is_dir($pkgSource . '/packages/theme-runtime'));
    $check('E: persistent workspace remains clean (no operator git pull/reset/checkout ever required)', $git->changedFiles($pkgSource) === []);

    $pkgWorkspace->cleanup();

    // B: the TARGET commit itself genuinely lacks the declared path
    // repository (not tracked in-repo, no external sibling either) — must
    // fail closed, never silently skipped.
    file_put_contents($pkgSeed . '/composer.json', json_encode([
        'name' => 'fixture/app',
        'repositories' => [['type' => 'path', 'url' => 'packages/does-not-exist']],
        'require' => ['fixture/missing' => '*'],
        'minimum-stability' => 'dev',
    ], JSON_PRETTY_PRINT));
    $run(['git', 'add', '-A'], $pkgSeed);
    $run(['git', 'commit', '-m', 'declare a path repository that genuinely does not exist'], $pkgSeed);
    $pkgCommitMissing = $git->lastCommitHash($pkgSeed);
    $run(['git', 'push', 'publish', 'main'], $pkgSeed);

    $missingWorkspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'missing-path-repo');
    $expectPrepareFailure(
        fn () => $preparer->prepareRemoteCommit($missingWorkspace, $pkgSource, $pkgRemote, 'origin', 'main', $pkgCommitMissing),
        "declares a path repository ('packages/does-not-exist') that does not exist",
    );
    $missingWorkspace->cleanup();

    // C: the requested target SHA cannot be resolved at all (never pushed,
    // does not exist anywhere) — must fail closed before any Composer/
    // build step ever runs.
    $bogusCommit = str_repeat('a', 40);
    $bogusWorkspace = BuildWorkspace::create($root . '/builds', 'saas-platform', 'bogus-sha');
    $bogusThrew = null;
    try {
        $preparer->prepareRemoteCommit($bogusWorkspace, $pkgSource, $pkgRemote, 'origin', 'main', $bogusCommit);
    } catch (\Throwable $e) {
        $bogusThrew = $e;
    }
    $check('C: an unresolvable target SHA fails closed before any build step', $bogusThrew !== null && str_contains($bogusThrew->getMessage(), 'validating the requested commit'));
    $check('C: the isolated workspace for an unresolvable commit is never populated with project files', !is_file($bogusWorkspace->projectDir() . '/composer.json'));
    $bogusWorkspace->cleanup();

    // D: the exact-commit checkout resolving to the WRONG SHA must fail
    // closed. Exercised directly against WorkspaceSourcePreparer's own
    // post-checkout self-verification (real repo, real checkout, a
    // deliberately wrong expected SHA) since a `git checkout --force`
    // landing on the wrong commit is not reproducible through real git
    // behavior.
    $verifyMethod = new ReflectionMethod(WorkspaceSourcePreparer::class, 'assertCheckedOutAtExactCommit');
    $verifyMethod->setAccessible(true);
    $check('D: checkout-verification passes when the checked-out HEAD matches the expected commit', (function () use ($verifyMethod, $preparer, $pkgSource, $pkgCommitOld) {
        try {
            $verifyMethod->invoke($preparer, $pkgSource, $pkgCommitOld);

            return true;
        } catch (\Throwable) {
            return false;
        }
    })());
    $check('D: checkout-verification fails closed when the checked-out HEAD does not match the expected commit', (function () use ($verifyMethod, $preparer, $pkgSource) {
        try {
            $verifyMethod->invoke($preparer, $pkgSource, str_repeat('b', 40));

            return false;
        } catch (LocalBuildFailedException $e) {
            return $e->failureCode === 'local_workspace_prepare_failed' && str_contains($e->getMessage(), 'checkout mismatch');
        }
    })());

    // F: no commit/push is ever issued by the Local Agent build pipeline.
    $sourceCode = (string) file_get_contents(__DIR__ . '/../lib/WorkspaceSourcePreparer.php');
    $check('F: WorkspaceSourcePreparer never issues a git commit', preg_match("/'git',\s*'commit'/", $sourceCode) === 0);
    $check('F: WorkspaceSourcePreparer never issues a git push', preg_match("/'git',\s*'push'/", $sourceCode) === 0);

    $mirror = __DIR__ . '/../../deployment-local-agent/agent/lib/WorkspaceSourcePreparer.php';
    $check('shipped WorkspaceSourcePreparer mirrors source implementation', hash_file('sha256', __DIR__ . '/../lib/WorkspaceSourcePreparer.php') === hash_file('sha256', $mirror));
    $mirrorScoped = __DIR__ . '/../../deployment-local-agent/agent/lib/ScopedSshConfig.php';
    $check('shipped ScopedSshConfig mirrors source implementation', hash_file('sha256', __DIR__ . '/../lib/ScopedSshConfig.php') === hash_file('sha256', $mirrorScoped));
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
    $previousGlobalConfig === false ? putenv('GIT_CONFIG_GLOBAL') : putenv('GIT_CONFIG_GLOBAL=' . $previousGlobalConfig);
}

if ($failures !== []) {
    fwrite(STDERR, "Source workspace contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Source workspace contract: {$passed} passed, 0 failed.\n";
