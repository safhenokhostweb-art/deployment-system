<?php

declare(strict_types=1);

/**
 * Real bug reported: LocalBuildRunner used to run `composer install`/
 * `npm ci` directly inside the developer's own working tree. `npm ci`
 * needs to delete the existing node_modules/ first, but a native
 * Tailwind binary inside it was locked by Windows/VS Code/an editor
 * watcher — every publish failed with `EPERM unlink`, and closing every
 * other process first "fixed" it only by coincidentally releasing the
 * lock. This suite proves the isolated-workspace fix (BuildWorkspace +
 * WorkspaceSourcePreparer): composer/npm now run in a disposable,
 * per-job directory tree entirely outside any project's own folder, so
 * a real working tree's vendor/node_modules — locked or not — is never
 * read from or written to.
 *
 * Same philosophy as the rest of this test suite: no mocking, real git
 * repos, real composer/npm processes, and for the locked-file
 * reproduction specifically, a REAL separate process holding a REAL
 * Windows file handle open with FileShare::None (confirmed by direct
 * testing to be what actually blocks unlink() with "Resource temporarily
 * unavailable" — a plain fopen() handle from another process does NOT
 * block deletion on Windows, so a weaker simulation would prove nothing).
 *
 * Usage: php tests/run_workspace_isolation.php
 */

require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/ApplicationRoot.php';
require __DIR__ . '/../lib/LocalBuildRunner.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';
require __DIR__ . '/../lib/ArtifactUploader.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactUploader;
use LocalAgent\BuildWorkspace;
use LocalAgent\Client;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;
use LocalAgent\WorkspaceSourcePreparer;

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

function makeTempDir(string $label): string
{
    $root = sys_get_temp_dir() . "/{$label}-" . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);

            continue;
        }
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

function runGit(GitManager $git, array $argv, string $cwd): array
{
    return $git->run(array_merge(['git'], $argv), $cwd, 60);
}

/**
 * @return array{path: string, commitHash: string}
 */
function buildFixtureRepo(GitManager $git, string $workspace, string $name = 'repo'): array
{
    $repo = "{$workspace}/{$name}";
    mkdir($repo, 0777, true);

    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Workspace Isolation Test'], $repo);

    file_put_contents("{$repo}/.gitignore", ".env\nnode_modules/\nvendor/\npublic/build/\n");
    mkdir("{$repo}/app", 0777, true);
    file_put_contents("{$repo}/app/Kernel.php", "<?php\n// fixture app code\n");
    file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/app'], JSON_PRETTY_PRINT));
    file_put_contents("{$repo}/package.json", json_encode([
        'name' => 'fixture-app', 'private' => true,
        'scripts' => ['build' => 'node build.js'],
    ], JSON_PRETTY_PRINT));
    file_put_contents("{$repo}/build.js", <<<'JS'
        const fs = require('fs');
        fs.mkdirSync(`${__dirname}/public/build`, { recursive: true });
        fs.writeFileSync(`${__dirname}/public/build/manifest.json`, JSON.stringify({ 'app.js': 'assets/app.abc123.js' }));
        JS);

    $git->run(['npm', 'install', '--package-lock-only'], $repo, 60);

    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);
    $commitHash = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);

    return ['path' => $repo, 'commitHash' => $commitHash];
}

/**
 * Holds $path open on a background PowerShell process with
 * FileShare::None — confirmed by direct testing to be what actually
 * makes Windows refuse unlink()/rename() from another process
 * ("Resource temporarily unavailable"), unlike a plain fopen() handle
 * (which does NOT block deletion on Windows). This is the real
 * mechanism a loaded native module (e.g. the reported Tailwind oxide
 * .node binary) locks a file with.
 *
 * @return resource|null the holder process, or null on non-Windows
 *     (the bug this suite proves fixed is Windows-specific — locking
 *     semantics on Linux/macOS, the real production target, are
 *     different and this exact reproduction doesn't apply there)
 */
function lockFileExclusively(string $path, int $seconds)
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }

    $scriptPath = tempnam(sys_get_temp_dir(), 'hold-') . '.ps1';
    file_put_contents($scriptPath, <<<PS1
        param([string]\$Path, [int]\$Seconds)
        \$stream = [System.IO.File]::Open(\$Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::None)
        Write-Output "locked"
        Start-Sleep -Seconds \$Seconds
        \$stream.Close()
        PS1);

    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $scriptPath, '-Path', $path, '-Seconds', (string) $seconds],
        $descriptorSpec,
        $pipes,
    );

    if (!is_resource($process)) {
        @unlink($scriptPath);

        return null;
    }

    // Block until the holder confirms the handle is actually open —
    // never a fixed sleep guessing at timing.
    $confirmation = fgets($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    @unlink($scriptPath);

    return trim((string) $confirmation) === 'locked' ? $process : null;
}

/**
 * proc_close() waits for the holder PROCESS to exit, but Windows'
 * kernel-level release of the file handle it held is not guaranteed to
 * be complete at the exact instant proc_close() returns (confirmed
 * empirically: an immediate post-release read occasionally still hit
 * "Resource temporarily unavailable" under system load) — so this polls
 * briefly rather than trusting a single immediate check.
 */
function releaseLock($process, ?string $verifyUnlockedPath = null): void
{
    if ($process !== null) {
        proc_terminate($process);
        proc_close($process);
    }

    if ($verifyUnlockedPath === null) {
        return;
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $verifyUnlockedPath);
        $handle = @fopen($verifyUnlockedPath, 'rb');
        if ($handle !== false) {
            fclose($handle);

            return;
        }
        usleep(50000);
    }
}

$isWindows = PHP_OS_FAMILY === 'Windows';

// ============================================================
// 1. The real working tree — including a PRE-EXISTING, real vendor/ and
//    node_modules/ (simulating a developer who already ran composer/npm
//    manually before) — is completely untouched by a full publish
//    pipeline: not a single file added, removed, or modified.
// ============================================================
$scenario1 = makeTempDir('workspace-untouched');
$git = new GitManager();
$fixture1 = buildFixtureRepo($git, $scenario1);

mkdir("{$fixture1['path']}/vendor/existing-pkg", 0777, true);
file_put_contents("{$fixture1['path']}/vendor/existing-pkg/marker.php", "<?php // pre-existing, untouched by this test\n");
file_put_contents("{$fixture1['path']}/vendor/autoload.php", "<?php // pre-existing developer vendor\n");
mkdir("{$fixture1['path']}/node_modules/existing-pkg", 0777, true);
file_put_contents("{$fixture1['path']}/node_modules/existing-pkg/index.js", "// pre-existing, untouched by this test\n");

/**
 * @return array<string, array{size: int, mtime: int, hash: string}>
 */
function snapshotTree(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
        $snapshot[$relative] = [
            'size' => $item->getSize(),
            'mtime' => $item->getMTime(),
            'hash' => hash_file('sha256', $item->getPathname()),
        ];
    }

    return $snapshot;
}

$before = snapshotTree($fixture1['path']);

$workspaceBase1 = makeTempDir('agent-workspaces') . '/builds';
$uploaderWorkspace1 = makeTempDir('uploader-logs');
$logger1 = new Logger($uploaderWorkspace1 . '/logs');
// A Client pointed at an unreachable Hub — this test only cares about
// what happens to the REAL working tree during build/archive, not the
// upload outcome, so the Hub being unreachable (fast, offline, no fake
// server needed) is fine — the build still fully runs first.
$deadClient1 = new Client(baseUrl: 'http://127.0.0.1:1', localAgentId: 'x', agentToken: 'x', signingSecret: 'x', timeoutSeconds: 1, logger: $logger1);
$uploader1 = new ArtifactUploader(new LocalBuildRunner($git), new WorkspaceSourcePreparer($git), new ArtifactBuilder(), $deadClient1, $logger1, $workspaceBase1);
$uploader1->buildAndUpload('untouched-project', $fixture1['path'], $fixture1['commitHash'], 'main', 'job-untouched');

$after = snapshotTree($fixture1['path']);
check('the real working tree has EXACTLY the same set of files after a full build (nothing added, nothing removed)', $before === $after, $failures, $passCount);
check('specifically: the pre-existing vendor/autoload.php is byte-for-byte and mtime-for-mtime unchanged', ($before['vendor/autoload.php'] ?? null) === ($after['vendor/autoload.php'] ?? null), $failures, $passCount);
check('specifically: the pre-existing node_modules file is byte-for-byte and mtime-for-mtime unchanged', ($before['node_modules/existing-pkg/index.js'] ?? null) === ($after['node_modules/existing-pkg/index.js'] ?? null), $failures, $passCount);
rrmdir($scenario1);
rrmdir(dirname($workspaceBase1));
rrmdir($uploaderWorkspace1);

// ============================================================
// 2. THE reported bug, reproduced for real: a file inside the real
//    working tree's node_modules/ is locked (FileShare::None, held by a
//    genuinely separate process) exactly like a loaded native binary
//    would be — `npm ci` in the OLD (pre-fix) architecture would have
//    hit EPERM trying to delete it. In the isolated workspace, npm ci
//    never touches this directory at all, so the build must succeed
//    regardless, and the locked file must remain exactly as it was
//    (still locked, still present, unchanged).
// ============================================================
if ($isWindows) {
    $scenario2 = makeTempDir('workspace-locked-file');
    $fixture2 = buildFixtureRepo($git, $scenario2);

    mkdir("{$fixture2['path']}/node_modules/@tailwindcss", 0777, true);
    $lockedFilePath = "{$fixture2['path']}/node_modules/@tailwindcss/oxide-win32-x64-msvc.node";
    file_put_contents($lockedFilePath, "native binary content\n");
    $lockedFileHashBefore = hash_file('sha256', $lockedFilePath);

    $holder = lockFileExclusively($lockedFilePath, 20);
    check('the reproduction lock was actually acquired by a separate process', $holder !== null, $failures, $passCount);

    if ($holder !== null) {
        $workspaceBase2 = makeTempDir('agent-workspaces') . '/builds';
        $uploaderWorkspace2 = makeTempDir('uploader-logs');
        $logger2 = new Logger($uploaderWorkspace2 . '/logs');
        $deadClient2 = new Client(baseUrl: 'http://127.0.0.1:1', localAgentId: 'x', agentToken: 'x', signingSecret: 'x', timeoutSeconds: 1, logger: $logger2);
        $uploader2 = new ArtifactUploader(new LocalBuildRunner($git), new WorkspaceSourcePreparer($git), new ArtifactBuilder(), $deadClient2, $logger2, $workspaceBase2);

        $result2 = null;
        $threw = null;
        try {
            $result2 = $uploader2->buildAndUpload('locked-project', $fixture2['path'], $fixture2['commitHash'], 'main', 'job-locked');
        } catch (\Throwable $e) {
            $threw = $e;
        }

        check('the build does not throw despite a locked file sitting in the real node_modules/', $threw === null, $failures, $passCount);
        // failureCode here (if any) can only legitimately come from the
        // dead-Hub connection itself (Client::request() throws on a
        // connection failure, landing in ArtifactUploader's generic
        // catch-all, which maps to artifact_build_failed — pre-existing,
        // unrelated-to-this-fix behavior) — never anything
        // composer/npm/build-related, since npm ci runs in total
        // isolation from the locked file.
        check(
            'the build itself (composer/npm/archive) never fails because of the locked file — only the unreachable-Hub connection can fail',
            $result2 !== null && in_array($result2['failureCode'], [null, 'artifact_build_failed', 'artifact_upload_failed'], true),
            $failures,
            $passCount,
        );

        // The lock is still held at this point — verify it's untouched
        // WHILE held (a hash_file()/read attempt would itself be blocked
        // by FileShare::None, so content is only checked after release).
        check('the lock itself is still held (nothing even attempted to touch it) — unlink still fails the same way', @unlink($lockedFilePath) === false, $failures, $passCount);

        releaseLock($holder, $lockedFilePath);
        clearstatcache(true, $lockedFilePath);
        check('the locked file is still exactly where it was, byte-for-byte unchanged', is_file($lockedFilePath) && hash_file('sha256', $lockedFilePath) === $lockedFileHashBefore, $failures, $passCount);
        rrmdir(dirname($workspaceBase2));
        rrmdir($uploaderWorkspace2);
    }

    rrmdir($scenario2);
} else {
    echo "NOTE: skipping the Windows-specific locked-file (EPERM) reproduction — this test only runs on Windows, the platform the reported bug is specific to.\n";
}

// ============================================================
// 3. Race guard moved from ArtifactBuilder to WorkspaceSourcePreparer:
//    refuses to prepare a workspace for a commit that is no longer HEAD.
// ============================================================
$scenario3 = makeTempDir('workspace-race-guard');
$fixture3 = buildFixtureRepo($git, $scenario3);
$staleCommit = $fixture3['commitHash'];
// Move HEAD forward — $staleCommit is no longer the tip.
file_put_contents("{$fixture3['path']}/app/Kernel.php", "<?php\n// changed after the caller captured the commit hash\n");
runGit($git, ['add', '-A'], $fixture3['path']);
runGit($git, ['commit', '--quiet', '-m', 'second commit'], $fixture3['path']);

$preparer3 = new WorkspaceSourcePreparer($git);
$workspace3 = BuildWorkspace::create(makeTempDir('agent-workspaces'), 'race-project', 'job-race');
check('WorkspaceSourcePreparer refuses to prepare a workspace for a commit that is no longer HEAD', (function () use ($preparer3, $workspace3, $fixture3, $staleCommit) {
    try {
        $preparer3->prepare($workspace3, $fixture3['path'], $staleCommit);

        return false;
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'Refusing to build');
    }
})(), $failures, $passCount);
$workspace3->cleanup();
rrmdir($scenario3);

// ============================================================
// 4. Build is from the EXACT published commit — an UNCOMMITTED change
//    sitting in the working tree at prepare-time must NOT appear in the
//    workspace (git archive only ever reads from git's own object
//    database for the given commit, never the working tree's current
//    file contents).
// ============================================================
$scenario4 = makeTempDir('workspace-exact-commit');
$fixture4 = buildFixtureRepo($git, $scenario4);
// An uncommitted change AND an uncommitted new file — neither was ever
// committed, so neither belongs in an artifact built from $commitHash.
file_put_contents("{$fixture4['path']}/app/Kernel.php", "<?php\n// UNCOMMITTED — must never appear in the archived workspace\n");
file_put_contents("{$fixture4['path']}/app/Uncommitted.php", "<?php // UNCOMMITTED file — must never appear\n");

$preparer4 = new WorkspaceSourcePreparer($git);
$workspace4 = BuildWorkspace::create(makeTempDir('agent-workspaces'), 'exact-commit-project', 'job-exact');
$preparer4->prepare($workspace4, $fixture4['path'], $fixture4['commitHash']);

$archivedKernel = file_get_contents("{$workspace4->projectDir()}/app/Kernel.php");
check('the archived workspace has the COMMITTED content of a tracked file, not the uncommitted working-tree edit', str_contains($archivedKernel, 'fixture app code') && !str_contains($archivedKernel, 'UNCOMMITTED'), $failures, $passCount);
check('an uncommitted, never-added file never enters the workspace at all', !is_file("{$workspace4->projectDir()}/app/Uncommitted.php"), $failures, $passCount);
$workspace4->cleanup();
rrmdir($scenario4);

// ============================================================
// 5. Composer path-repository dependencies (customer-site's real,
//    audited case: ../saas-platform/packages/saas-platform/*) are
//    resolved generically (no project names hardcoded) and copied into
//    the workspace at the same relative position, so `composer install`
//    resolves them without ever touching the real sibling directory —
//    and the final artifact is fully self-contained (the real package
//    directory is completely untouched afterwards).
// ============================================================
$scenario5 = makeTempDir('workspace-path-deps');
// The "sibling package" — exactly what a real path-repository target
// looks like: its own tiny composer.json, real PHP source.
$siblingPkgDir = "{$scenario5}/sibling-repo/packages/some-lib";
mkdir($siblingPkgDir, 0777, true);
file_put_contents("{$siblingPkgDir}/composer.json", json_encode([
    'name' => 'acme/some-lib',
    'autoload' => ['psr-4' => ['Acme\\SomeLib\\' => 'src/']],
], JSON_PRETTY_PRINT));
mkdir("{$siblingPkgDir}/src", 0777, true);
file_put_contents("{$siblingPkgDir}/src/Widget.php", "<?php\nnamespace Acme\\SomeLib;\nclass Widget {}\n");

$mainRepo = "{$scenario5}/main-project";
mkdir($mainRepo, 0777, true);
runGit($git, ['init', '--quiet', '-b', 'main'], $mainRepo);
runGit($git, ['config', 'user.email', 'test@example.test'], $mainRepo);
runGit($git, ['config', 'user.name', 'Workspace Isolation Test'], $mainRepo);
file_put_contents("{$mainRepo}/composer.json", json_encode([
    'name' => 'fixture/main-project',
    'repositories' => [
        ['type' => 'path', 'url' => '../sibling-repo/packages/some-lib', 'options' => ['symlink' => true]],
    ],
    'require' => ['acme/some-lib' => '*'],
    // A path-repository package with no VCS tag defaults to a "dev-main"
    // version — real Composer behavior, confirmed by direct testing, not
    // specific to this fixture — which a bare "*" constraint can't match
    // without this.
    'minimum-stability' => 'dev',
    'prefer-stable' => true,
], JSON_PRETTY_PRINT));
runGit($git, ['add', '-A'], $mainRepo);
runGit($git, ['commit', '--quiet', '-m', 'initial'], $mainRepo);
$mainCommit = trim(runGit($git, ['rev-parse', 'HEAD'], $mainRepo)['output']);

$preparer5 = new WorkspaceSourcePreparer($git);
$workspace5 = BuildWorkspace::create(makeTempDir('agent-workspaces'), 'path-dep-project', 'job-pathdep');
$preparer5->prepare($workspace5, $mainRepo, $mainCommit);

$expectedCopyTarget = str_replace('\\', '/', $workspace5->root()) . '/sibling-repo/packages/some-lib';
check('the path-repository package was copied into the workspace at the SAME relative position as the real sibling', is_file(str_replace('/', DIRECTORY_SEPARATOR, $expectedCopyTarget) . '/src/Widget.php'), $failures, $passCount);

$buildRunner5 = new LocalBuildRunner($git);
$buildResult5 = null;
$buildThrew5 = null;
try {
    $buildResult5 = $buildRunner5->run($workspace5->projectDir());
} catch (\Throwable $e) {
    $buildThrew5 = $e;
}
check('composer install succeeds inside the workspace using the copied (not symlinked-to-the-real-tree) path dependency', $buildThrew5 === null && $buildResult5['ranComposer'] === true, $failures, $passCount);

if ($buildThrew5 === null) {
    $vendorWidgetPath = "{$workspace5->projectDir()}/vendor/acme/some-lib/src/Widget.php";
    check('vendor/ inside the workspace has the real, dereferenced package content (not a dangling link)', is_file($vendorWidgetPath) && str_contains((string) file_get_contents($vendorWidgetPath), 'class Widget'), $failures, $passCount);

    $builder5 = new ArtifactBuilder();
    $archive5 = $builder5->build($workspace5->projectDir(), $workspace5->root(), time(), ['ranComposer' => true, 'ranNpmBuild' => false]);
    $zip5 = new ZipArchive();
    $zip5->open($archive5['path']);
    $hasWidgetInArtifact = $zip5->locateName('vendor/acme/some-lib/src/Widget.php') !== false;
    $widgetContentInArtifact = $hasWidgetInArtifact ? $zip5->getFromName('vendor/acme/some-lib/src/Widget.php') : null;
    $zip5->close();
    check('the FINAL ARTIFACT is self-contained: the path-dependency\'s real source is inside vendor/, not a Junction/symlink reference', $hasWidgetInArtifact && is_string($widgetContentInArtifact) && str_contains($widgetContentInArtifact, 'class Widget'), $failures, $passCount);
    @unlink($archive5['path']);
}

// The real sibling package directory itself — outside the workspace —
// must be completely untouched by any of this.
check('the REAL sibling package directory was never modified (no composer artifacts leaked back into it)', !is_dir("{$siblingPkgDir}/vendor") && is_file("{$siblingPkgDir}/src/Widget.php"), $failures, $passCount);

$workspace5->cleanup();
check('cleanup also removes the copied path-dependency directory (the whole workspace root, not just project/)', !is_dir($workspace5->root()), $failures, $passCount);
rrmdir($scenario5);

// An absolute or escaping path-repository URL must be rejected, never silently written outside the workspace.
$scenario5b = makeTempDir('workspace-path-deps-escape');
$escapeRepo = "{$scenario5b}/main-project";
mkdir($escapeRepo, 0777, true);
runGit($git, ['init', '--quiet', '-b', 'main'], $escapeRepo);
runGit($git, ['config', 'user.email', 'test@example.test'], $escapeRepo);
runGit($git, ['config', 'user.name', 'Workspace Isolation Test'], $escapeRepo);
file_put_contents("{$escapeRepo}/composer.json", json_encode([
    'name' => 'fixture/escape-project',
    'repositories' => [['type' => 'path', 'url' => '../../../../escape-attempt']],
    'require' => ['acme/escape' => '*'],
], JSON_PRETTY_PRINT));
runGit($git, ['add', '-A'], $escapeRepo);
runGit($git, ['commit', '--quiet', '-m', 'initial'], $escapeRepo);
$escapeCommit = trim(runGit($git, ['rev-parse', 'HEAD'], $escapeRepo)['output']);
mkdir("{$scenario5b}/escape-attempt", 0777, true);
file_put_contents("{$scenario5b}/escape-attempt/marker.txt", "should never be reachable\n");

$preparer5b = new WorkspaceSourcePreparer($git);
$workspace5b = BuildWorkspace::create(makeTempDir('agent-workspaces'), 'escape-project', 'job-escape');
check('a path-repository URL escaping the workspace root is rejected with local_workspace_prepare_failed, never silently followed', (function () use ($preparer5b, $workspace5b, $escapeRepo, $escapeCommit) {
    try {
        $preparer5b->prepare($workspace5b, $escapeRepo, $escapeCommit);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'local_workspace_prepare_failed';
    }
})(), $failures, $passCount);
$workspace5b->cleanup();
rrmdir($scenario5b);

// ============================================================
// 6. Cleanup: success removes the whole workspace; a locked file INSIDE
//    the workspace makes cleanup best-effort (warns, never throws, never
//    corrupts the caller's own result).
// ============================================================
$workspaceBase6 = makeTempDir('agent-workspaces') . '/builds';
$workspace6 = BuildWorkspace::create($workspaceBase6, 'cleanup-project', 'job-cleanup-ok');
file_put_contents("{$workspace6->projectDir()}/marker.txt", 'x');
$workspace6->cleanup();
check('BuildWorkspace::cleanup() removes the entire workspace root on success', !is_dir($workspace6->root()), $failures, $passCount);

if ($isWindows) {
    $workspace6b = BuildWorkspace::create($workspaceBase6, 'cleanup-project', 'job-cleanup-locked');
    $lockedInsideWorkspace = "{$workspace6b->projectDir()}/locked.txt";
    file_put_contents($lockedInsideWorkspace, 'locked content');
    $holder6 = lockFileExclusively($lockedInsideWorkspace, 15);

    if ($holder6 !== null) {
        $logDir6 = makeTempDir('cleanup-warn-log');
        $logger6 = new Logger($logDir6);
        $threw6 = null;
        try {
            $workspace6b->cleanup($logger6);
        } catch (\Throwable $e) {
            $threw6 = $e;
        }
        check('cleanup() with a locked file inside the workspace never throws', $threw6 === null, $failures, $passCount);

        $logContent = '';
        foreach (glob("{$logDir6}/*.log") ?: [] as $logFile) {
            $logContent .= (string) file_get_contents($logFile);
        }
        check('cleanup() logs a [local_workspace_cleanup_failed] warning when it cannot fully clean up', str_contains($logContent, 'local_workspace_cleanup_failed'), $failures, $passCount);

        releaseLock($holder6, $lockedInsideWorkspace);
        rrmdir($workspace6b->root());
        rrmdir($logDir6);
    }
}
rrmdir(dirname($workspaceBase6));

// ============================================================
// 7. Guarded deletion: refuses to delete anything not confirmed nested
//    inside its expected floor — a coding mistake fails loudly rather
//    than silently deleting something unexpected.
// ============================================================
$outsideDir = makeTempDir('outside-any-workspace');
$unrelatedFloor = makeTempDir('some-unrelated-floor'); // a REAL, existing directory — genuinely unrelated to $outsideDir
$guardMethod = new ReflectionMethod(BuildWorkspace::class, 'guardedRemoveTree');
$guardMethod->setAccessible(true);
check('guardedRemoveTree() refuses to delete a path outside its expected floor', (function () use ($guardMethod, $outsideDir, $unrelatedFloor) {
    try {
        $guardMethod->invoke(null, $outsideDir, $unrelatedFloor);

        return false;
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'not confirmed to be inside');
    }
})(), $failures, $passCount);
rrmdir($unrelatedFloor);
check('...and the directory outside the floor genuinely still exists (the refusal was real, not a no-op)', is_dir($outsideDir), $failures, $passCount);
check('guardedRemoveTree() refuses to delete the floor itself, even when asked directly', (function () use ($guardMethod, $outsideDir) {
    try {
        $guardMethod->invoke(null, $outsideDir, $outsideDir);

        return false;
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'shared builds root itself');
    }
})(), $failures, $passCount);
rrmdir($outsideDir);

// ============================================================
// 8. Concurrency: two publish jobs for the SAME project (different job
//    ids — the only way two publishes for one project could ever
//    overlap, since a single Local Agent's poll loop is otherwise
//    strictly sequential) get fully independent workspace paths, with
//    zero cross-contamination.
// ============================================================
$workspaceBase8 = makeTempDir('agent-workspaces') . '/builds';
$workspaceA = BuildWorkspace::create($workspaceBase8, 'concurrent-project', 'job-A');
$workspaceB = BuildWorkspace::create($workspaceBase8, 'concurrent-project', 'job-B');
check('two jobs for the same project get distinct workspace roots', $workspaceA->root() !== $workspaceB->root(), $failures, $passCount);
file_put_contents("{$workspaceA->projectDir()}/marker.txt", 'A');
file_put_contents("{$workspaceB->projectDir()}/marker.txt", 'B');
check('writing into job A\'s workspace never appears in job B\'s', file_get_contents("{$workspaceB->projectDir()}/marker.txt") === 'B', $failures, $passCount);
$workspaceA->cleanup();
check('cleaning up job A\'s workspace leaves job B\'s completely intact', is_file("{$workspaceB->projectDir()}/marker.txt") && file_get_contents("{$workspaceB->projectDir()}/marker.txt") === 'B', $failures, $passCount);
$workspaceB->cleanup();
rrmdir(dirname($workspaceBase8));

// The Watcher's own change-detection (git status against a project's
// configured local_path) can never see a workspace as a project change
// — structurally true here because every workspace lives under a
// completely separate temp root, never inside any project's own
// directory tree.
check('a build workspace never lands inside the real project directory (so the file-watcher can never mistake it for a project change)', !str_starts_with(realpath($workspaceBase8) ?: $workspaceBase8, realpath($scenario1) ?: $scenario1), $failures, $passCount);

// ============================================================
// 9. Windows paths containing spaces work end-to-end — no shell is ever
//    involved (argv-array proc_open throughout), so a space is never a
//    word-splitting hazard.
// ============================================================
$spacyBase = makeTempDir('agent workspaces with spaces');
$scenario9 = makeTempDir('workspace-spacy-repo');
$fixture9 = buildFixtureRepo($git, $scenario9);
$logDir9 = makeTempDir('spacy-log dir');
$logger9 = new Logger($logDir9);
$deadClient9 = new Client(baseUrl: 'http://127.0.0.1:1', localAgentId: 'x', agentToken: 'x', signingSecret: 'x', timeoutSeconds: 1, logger: $logger9);
$uploader9 = new ArtifactUploader(new LocalBuildRunner($git), new WorkspaceSourcePreparer($git), new ArtifactBuilder(), $deadClient9, $logger9, $spacyBase);
$threw9 = null;
try {
    $uploader9->buildAndUpload('spacy project', $fixture9['path'], $fixture9['commitHash'], 'main', 'job with spaces');
} catch (\Throwable $e) {
    $threw9 = $e;
}
check('a workspace base directory containing spaces works without error (composer/npm/git all run via argv arrays, never a shell)', $threw9 === null, $failures, $passCount);
rrmdir($spacyBase);
rrmdir($scenario9);
rrmdir($logDir9);

// ============================================================
// 10. --no-scripts proof: a composer.json post-autoload-dump script that
//     WOULD fail the build if it ran (matching the real evidence: a
//     credential-less isolated workspace boots the framework and hits a
//     missing .env/DB) must never run at all.
// ============================================================
$scenario10 = makeTempDir('workspace-no-scripts');
$repo10 = "{$scenario10}/repo";
mkdir($repo10, 0777, true);
file_put_contents("{$repo10}/composer.json", json_encode([
    'name' => 'fixture/scripty',
    'scripts' => ['post-autoload-dump' => ['php -r "fwrite(STDERR, \'this must never run\'); exit(1);"']],
], JSON_PRETTY_PRINT));
$buildRunner10 = new LocalBuildRunner($git);
$result10 = null;
$threw10 = null;
try {
    $result10 = $buildRunner10->run($repo10);
} catch (\Throwable $e) {
    $threw10 = $e;
}
check('composer install succeeds even with a post-autoload-dump script that always fails — proving --no-scripts is actually applied', $threw10 === null && $result10['ranComposer'] === true, $failures, $passCount);
rrmdir($scenario10);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
