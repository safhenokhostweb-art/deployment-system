<?php

declare(strict_types=1);

/**
 * Phase 1 (Workspace Inventory): proves GitStatusPorcelainV2Parser and
 * WorkspaceInventoryScanner report actual Git facts — never AI/human/IDE
 * ownership — against real git repositories (same no-mocking convention
 * as the rest of this test suite; see run_git_parser_hardening.php and
 * tests/run.php).
 *
 * GitManager is `final`, so it cannot be subclassed to spy on which
 * commands it runs. The "never invokes a mutating Git operation" proof
 * (CRITICAL TEST, below) is therefore outcome-based instead: it snapshots
 * HEAD, the current branch, git's own status output, and a content hash
 * of every working-tree file before and after a scan, and asserts all
 * four are byte-identical — a stronger guarantee than inspecting argv
 * lists would be, since it can't be fooled by a code path that "forgot"
 * to record a command.
 *
 * Usage: php tests/run_workspace_inventory.php
 */

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/GitStatusPorcelainV2Parser.php';
require __DIR__ . '/../lib/WorkspaceInventoryScanner.php';

use LocalAgent\GitManager;
use LocalAgent\GitStatusPorcelainV2Parser;
use LocalAgent\WorkspaceInventoryScanner;

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
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir)) {
        @unlink($dir) || @rmdir($dir);

        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_link($path)) {
            @unlink($path) || @rmdir($path);
        } elseif (is_dir($path)) {
            rrmdir($path);
        } else {
            @chmod($path, 0666);
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function newGit(): GitManager
{
    return new GitManager(timeouts: ['status' => 30], jobHeartbeatIntervalSeconds: 10);
}

function runGit(GitManager $git, array $argv, string $cwd): array
{
    return $git->run(array_merge(['git'], $argv), $cwd, 30);
}

function initRepo(GitManager $git, string $repo): void
{
    mkdir($repo, 0777, true);
    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Workspace Inventory Test'], $repo);
}

function commitAll(GitManager $git, string $repo, string $message = 'commit'): void
{
    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', $message], $repo);
}

/** Recursively hashes every working-tree file's relative path + content, skipping .git — proves nothing was added/removed/modified. */
function treeContentHash(string $dir): string
{
    $lines = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($dir) + 1));
        if ($relative === '.git' || str_starts_with($relative, '.git/')) {
            continue;
        }
        if ($item->isDir()) {
            continue;
        }
        $lines[] = $relative . '|' . sha1_file((string) $item->getPathname());
    }
    sort($lines);

    return hash('sha256', implode("\n", $lines));
}

function findEntry(array $inventory, string $path): ?array
{
    foreach ($inventory['files'] as $file) {
        if ($file['path'] === $path) {
            return $file;
        }
    }

    return null;
}

$git = newGit();
$scanner = new WorkspaceInventoryScanner($git);

// ---------------------------------------------------------------------------
// Clean repository
// ---------------------------------------------------------------------------
$wsClean = makeTempDir('wi-clean');
try {
    $repo = "{$wsClean}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $inv = $scanner->scan($repo);
    check('clean: is_clean is true', $inv['is_clean'] === true, $failures, $passCount);
    check('clean: total_changed_count is 0', $inv['total_changed_count'] === 0, $failures, $passCount);
    check('clean: files[] is empty', $inv['files'] === [], $failures, $passCount);
    check('clean: scan_error is null', $inv['scan_error'] === null, $failures, $passCount);
    check('clean: is_truncated is false', $inv['is_truncated'] === false, $failures, $passCount);
    check('clean: schema_version is 1', $inv['schema_version'] === 1, $failures, $passCount);
    check('clean: branch is main', $inv['branch'] === 'main', $failures, $passCount);
    check('clean: head_sha looks like a real sha', preg_match('/^[0-9a-f]{40}$/', (string) $inv['head_sha']) === 1, $failures, $passCount);
    check('clean: every returned file has ownership=unknown (vacuously true here)', $inv['files'] === [], $failures, $passCount);
} finally {
    rrmdir($wsClean);
}

// ---------------------------------------------------------------------------
// Modified tracked file / staged file / staged+unstaged same file / added / deleted
// ---------------------------------------------------------------------------
$wsMixed = makeTempDir('wi-mixed');
try {
    $repo = "{$wsMixed}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/modified.txt", "one\n");
    file_put_contents("{$repo}/deleted.txt", "gone\n");
    file_put_contents("{$repo}/mixed.txt", "v1\n");
    commitAll($git, $repo);

    // Plain unstaged modification.
    file_put_contents("{$repo}/modified.txt", "two\n");

    // Deleted tracked file (unstaged).
    unlink("{$repo}/deleted.txt");

    // Staged-then-further-modified: staged "v2", then unstaged edit to "v3".
    file_put_contents("{$repo}/mixed.txt", "v2\n");
    runGit($git, ['add', 'mixed.txt'], $repo);
    file_put_contents("{$repo}/mixed.txt", "v3\n");

    // A brand new staged (added) file.
    file_put_contents("{$repo}/new-staged.txt", "new\n");
    runGit($git, ['add', 'new-staged.txt'], $repo);

    $inv = $scanner->scan($repo);

    $modified = findEntry($inv, 'modified.txt');
    check('modified: entry found', $modified !== null, $failures, $passCount);
    check('modified: status is modified', $modified !== null && $modified['status'] === 'modified', $failures, $passCount);
    check('modified: not staged', $modified !== null && $modified['staged'] === false, $failures, $passCount);
    check('modified: tracked', $modified !== null && $modified['tracked'] === true, $failures, $passCount);
    check('modified: ownership is unknown', $modified !== null && $modified['ownership'] === 'unknown', $failures, $passCount);

    $deleted = findEntry($inv, 'deleted.txt');
    check('deleted: entry found', $deleted !== null, $failures, $passCount);
    check('deleted: status is deleted', $deleted !== null && $deleted['status'] === 'deleted', $failures, $passCount);
    check('deleted: deleted_count is 1', $inv['deleted_count'] === 1, $failures, $passCount);

    $mixed = findEntry($inv, 'mixed.txt');
    check('staged+unstaged same file: entry found', $mixed !== null, $failures, $passCount);
    check('staged+unstaged same file: staged is true', $mixed !== null && $mixed['staged'] === true, $failures, $passCount);
    check('staged+unstaged same file: index_status is M', $mixed !== null && $mixed['index_status'] === 'M', $failures, $passCount);
    check('staged+unstaged same file: worktree_status is M', $mixed !== null && $mixed['worktree_status'] === 'M', $failures, $passCount);
    check('staged+unstaged same file: reported as ONE entry, not two', count(array_filter($inv['files'], fn ($f) => $f['path'] === 'mixed.txt')) === 1, $failures, $passCount);

    $added = findEntry($inv, 'new-staged.txt');
    check('added: entry found', $added !== null, $failures, $passCount);
    check('added: status is added', $added !== null && $added['status'] === 'added', $failures, $passCount);
    check('added: staged is true', $added !== null && $added['staged'] === true, $failures, $passCount);

    check('mixed: staged_count counts mixed.txt and new-staged.txt', $inv['staged_count'] === 2, $failures, $passCount);
    check('mixed: tracked_modified_count counts all 4 tracked entries', $inv['tracked_modified_count'] === 4, $failures, $passCount);
    check('mixed: total_changed_count is 4', $inv['total_changed_count'] === 4, $failures, $passCount);
    check('mixed: is_clean is false', $inv['is_clean'] === false, $failures, $passCount);
} finally {
    rrmdir($wsMixed);
}

// ---------------------------------------------------------------------------
// Untracked
// ---------------------------------------------------------------------------
$wsUntracked = makeTempDir('wi-untracked');
try {
    $repo = "{$wsUntracked}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);
    file_put_contents("{$repo}/scratch.txt", "new\n");

    $inv = $scanner->scan($repo);
    $entry = findEntry($inv, 'scratch.txt');
    check('untracked: entry found', $entry !== null, $failures, $passCount);
    check('untracked: status is untracked', $entry !== null && $entry['status'] === 'untracked', $failures, $passCount);
    check('untracked: tracked is false', $entry !== null && $entry['tracked'] === false, $failures, $passCount);
    check('untracked: staged is false', $entry !== null && $entry['staged'] === false, $failures, $passCount);
    check('untracked: untracked_count is 1', $inv['untracked_count'] === 1, $failures, $passCount);
    check('untracked: tracked_modified_count is 0', $inv['tracked_modified_count'] === 0, $failures, $passCount);
} finally {
    rrmdir($wsUntracked);
}

// ---------------------------------------------------------------------------
// Renamed
// ---------------------------------------------------------------------------
$wsRename = makeTempDir('wi-rename');
try {
    $repo = "{$wsRename}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/old-name.txt", str_repeat("line\n", 20));
    commitAll($git, $repo);
    rename("{$repo}/old-name.txt", "{$repo}/new-name.txt");
    runGit($git, ['add', '-A'], $repo);

    $inv = $scanner->scan($repo);
    $entry = findEntry($inv, 'new-name.txt');
    check('renamed: entry found under its new path', $entry !== null, $failures, $passCount);
    check('renamed: status is renamed', $entry !== null && $entry['status'] === 'renamed', $failures, $passCount);
    check('renamed: old_path is the original name', $entry !== null && $entry['old_path'] === 'old-name.txt', $failures, $passCount);
    check('renamed: renamed_count is 1', $inv['renamed_count'] === 1, $failures, $passCount);
    check('renamed: total_changed_count is 1 (one entry, not two)', $inv['total_changed_count'] === 1, $failures, $passCount);
} finally {
    rrmdir($wsRename);
}

// ---------------------------------------------------------------------------
// Merge conflict
// ---------------------------------------------------------------------------
$wsConflict = makeTempDir('wi-conflict');
try {
    $repo = "{$wsConflict}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/file.txt", "base\n");
    commitAll($git, $repo);
    runGit($git, ['checkout', '-q', '-b', 'feature'], $repo);
    file_put_contents("{$repo}/file.txt", "base\nfeature\n");
    commitAll($git, $repo, 'feature change');
    runGit($git, ['checkout', '-q', 'main'], $repo);
    file_put_contents("{$repo}/file.txt", "base\nmain\n");
    commitAll($git, $repo, 'main change');
    runGit($git, ['merge', '--no-edit', 'feature'], $repo); // expected to conflict

    $inv = $scanner->scan($repo);
    $entry = findEntry($inv, 'file.txt');
    check('conflict: entry found', $entry !== null, $failures, $passCount);
    check('conflict: status is conflicted', $entry !== null && $entry['status'] === 'conflicted', $failures, $passCount);
    check('conflict: conflicted_count is 1', $inv['conflicted_count'] === 1, $failures, $passCount);
    check('conflict: is_clean is false', $inv['is_clean'] === false, $failures, $passCount);
} finally {
    rrmdir($wsConflict);
}

// ---------------------------------------------------------------------------
// Detached HEAD
// ---------------------------------------------------------------------------
$wsDetached = makeTempDir('wi-detached');
try {
    $repo = "{$wsDetached}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);
    $sha = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);
    runGit($git, ['checkout', '-q', '--detach', $sha], $repo);

    $inv = $scanner->scan($repo);
    check('detached HEAD: branch is null', $inv['branch'] === null, $failures, $passCount);
    check('detached HEAD: head_sha still resolves', $inv['head_sha'] === $sha, $failures, $passCount);
    check('detached HEAD: comparison_basis is unavailable', $inv['comparison_basis'] === 'unavailable', $failures, $passCount);
    check('detached HEAD: scan does not throw/error', $inv['scan_error'] === null, $failures, $passCount);
} finally {
    rrmdir($wsDetached);
}

// ---------------------------------------------------------------------------
// No upstream / ahead / behind / diverged — via a local bare "origin"
// ---------------------------------------------------------------------------
$wsTracking = makeTempDir('wi-tracking');
try {
    $bare = "{$wsTracking}/origin.git";
    mkdir($bare, 0777, true);
    runGit($git, ['init', '--quiet', '--bare', '-b', 'main'], $bare);

    $seed = "{$wsTracking}/seed";
    initRepo($git, $seed);
    file_put_contents("{$seed}/base.txt", "base\n");
    commitAll($git, $seed, 'commit A');
    runGit($git, ['remote', 'add', 'origin', $bare], $seed);
    runGit($git, ['push', '-q', 'origin', 'main'], $seed);
    $shaA = trim(runGit($git, ['rev-parse', 'HEAD'], $seed)['output']);
    file_put_contents("{$seed}/base.txt", "base\nmore\n");
    commitAll($git, $seed, 'commit B');
    runGit($git, ['push', '-q', 'origin', 'main'], $seed);
    $shaB = trim(runGit($git, ['rev-parse', 'HEAD'], $seed)['output']);

    // No upstream at all.
    $wsNoUpstream = makeTempDir('wi-no-upstream');
    try {
        $repo = "{$wsNoUpstream}/repo";
        initRepo($git, $repo);
        file_put_contents("{$repo}/base.txt", "base\n");
        commitAll($git, $repo);
        $inv = $scanner->scan($repo);
        check('no upstream: comparison_basis is unavailable', $inv['comparison_basis'] === 'unavailable', $failures, $passCount);
        check('no upstream: ahead_count is null (never 0)', $inv['ahead_count'] === null, $failures, $passCount);
        check('no upstream: behind_count is null (never 0)', $inv['behind_count'] === null, $failures, $passCount);
        check('no upstream: upstream_ref is null', $inv['upstream_ref'] === null, $failures, $passCount);
    } finally {
        rrmdir($wsNoUpstream);
    }

    // Ahead: clone at B, add one unpushed local commit.
    $cloneAhead = "{$wsTracking}/clone-ahead";
    runGit($git, ['clone', '-q', $bare, $cloneAhead], $wsTracking);
    file_put_contents("{$cloneAhead}/base.txt", "base\nmore\nlocal-only\n");
    commitAll($git, $cloneAhead, 'local-only commit');
    $invAhead = $scanner->scan($cloneAhead);
    check('ahead: comparison_basis is upstream_tracking', $invAhead['comparison_basis'] === 'upstream_tracking', $failures, $passCount);
    check('ahead: ahead_count is 1', $invAhead['ahead_count'] === 1, $failures, $passCount);
    check('ahead: behind_count is 0', $invAhead['behind_count'] === 0, $failures, $passCount);
    check('ahead: upstream_sha resolves to a real sha', preg_match('/^[0-9a-f]{40}$/', (string) $invAhead['upstream_sha']) === 1, $failures, $passCount);

    // Behind: clone at B, then reset the LOCAL branch back to A — the
    // cached origin/main tracking ref (fetched at clone time) still
    // points at B, so this is "behind" without ever calling `git fetch`.
    $cloneBehind = "{$wsTracking}/clone-behind";
    runGit($git, ['clone', '-q', $bare, $cloneBehind], $wsTracking);
    runGit($git, ['reset', '-q', '--hard', $shaA], $cloneBehind);
    $invBehind = $scanner->scan($cloneBehind);
    check('behind: comparison_basis is upstream_tracking', $invBehind['comparison_basis'] === 'upstream_tracking', $failures, $passCount);
    check('behind: ahead_count is 0', $invBehind['ahead_count'] === 0, $failures, $passCount);
    check('behind: behind_count is 1', $invBehind['behind_count'] === 1, $failures, $passCount);

    // Diverged: same starting point as "behind", then add a new local commit on top of A.
    $cloneDiverged = "{$wsTracking}/clone-diverged";
    runGit($git, ['clone', '-q', $bare, $cloneDiverged], $wsTracking);
    runGit($git, ['reset', '-q', '--hard', $shaA], $cloneDiverged);
    file_put_contents("{$cloneDiverged}/base.txt", "base\ndiverged\n");
    commitAll($git, $cloneDiverged, 'diverging commit');
    $invDiverged = $scanner->scan($cloneDiverged);
    check('diverged: comparison_basis is upstream_tracking', $invDiverged['comparison_basis'] === 'upstream_tracking', $failures, $passCount);
    check('diverged: ahead_count is 1', $invDiverged['ahead_count'] === 1, $failures, $passCount);
    check('diverged: behind_count is 1', $invDiverged['behind_count'] === 1, $failures, $passCount);
} finally {
    rrmdir($wsTracking);
}

// ---------------------------------------------------------------------------
// Unicode filename / filename with spaces
// ---------------------------------------------------------------------------
$wsUnicode = makeTempDir('wi-unicode');
try {
    $repo = "{$wsUnicode}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $unicodeName = 'café-résumé-日本語.txt';
    file_put_contents("{$repo}/{$unicodeName}", "unicode\n");
    $spacedName = 'file with spaces and (parens).txt';
    file_put_contents("{$repo}/{$spacedName}", "spacey\n");

    $inv = $scanner->scan($repo);
    check('unicode filename: reported with the exact byte-for-byte name', findEntry($inv, $unicodeName) !== null, $failures, $passCount);
    check('spaced filename: reported with the exact byte-for-byte name, unquoted', findEntry($inv, $spacedName) !== null, $failures, $passCount);
} finally {
    rrmdir($wsUnicode);
}

// ---------------------------------------------------------------------------
// Ignored files not reported
// ---------------------------------------------------------------------------
$wsIgnored = makeTempDir('wi-ignored');
try {
    $repo = "{$wsIgnored}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/.gitignore", "ignored.log\n");
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);
    file_put_contents("{$repo}/ignored.log", "should never be reported\n");
    file_put_contents("{$repo}/visible.txt", "should be reported\n");

    $inv = $scanner->scan($repo);
    check('ignored: an ignored file is never in files[]', findEntry($inv, 'ignored.log') === null, $failures, $passCount);
    check('ignored: a non-ignored untracked file is still reported', findEntry($inv, 'visible.txt') !== null, $failures, $passCount);
    check('ignored: total_changed_count only counts the non-ignored file', $inv['total_changed_count'] === 1, $failures, $passCount);
} finally {
    rrmdir($wsIgnored);
}

// ---------------------------------------------------------------------------
// Nested repository not recursively scanned
// ---------------------------------------------------------------------------
$wsNested = makeTempDir('wi-nested');
try {
    $repo = "{$wsNested}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $nested = "{$repo}/vendor-like-nested-repo";
    initRepo($git, $nested);
    file_put_contents("{$nested}/inner.txt", "inner\n");
    commitAll($git, $nested, 'inner commit');

    $inv = $scanner->scan($repo);
    $innerFileLeaked = findEntry($inv, 'vendor-like-nested-repo/inner.txt');
    check('nested repo: the nested repo\'s own tracked file is NOT individually listed', $innerFileLeaked === null, $failures, $passCount);
    $nestedAsOpaqueEntry = array_filter($inv['files'], fn ($f) => str_starts_with($f['path'], 'vendor-like-nested-repo'));
    check('nested repo: reported as at most one opaque entry for the whole nested repo', count($nestedAsOpaqueEntry) <= 1, $failures, $passCount);
} finally {
    rrmdir($wsNested);
}

// ---------------------------------------------------------------------------
// Mapped repository subdirectory rejected / wrong Git top-level rejected
// ---------------------------------------------------------------------------
$wsSubdir = makeTempDir('wi-subdir');
try {
    $repo = "{$wsSubdir}/repo";
    initRepo($git, $repo);
    mkdir("{$repo}/app/nested", 0777, true);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $threw = false;
    try {
        $scanner->scan("{$repo}/app");
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'top-level');
    }
    check('mapped repository subdirectory rejected (one level deep)', $threw, $failures, $passCount);

    $threw = false;
    try {
        $scanner->scan("{$repo}/app/nested");
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'top-level');
    }
    check('wrong Git top-level rejected (deeper subdirectory)', $threw, $failures, $passCount);

    $notARepo = "{$wsSubdir}/not-a-repo";
    mkdir($notARepo, 0777, true);
    $threw = false;
    try {
        $scanner->scan($notARepo);
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'Git working tree');
    }
    check('a directory with no Git repository at all is rejected', $threw, $failures, $passCount);
} finally {
    rrmdir($wsSubdir);
}

// ---------------------------------------------------------------------------
// Root-safety guard clauses that don't need a real filesystem fixture
// ---------------------------------------------------------------------------
$threw = false;
try {
    $scanner->scan('relative/path');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('a relative path is rejected', $threw, $failures, $passCount);

$threw = false;
try {
    $scanner->scan(PHP_OS_FAMILY === 'Windows' ? 'C:\\' : '/');
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'filesystem root');
}
check('the filesystem root is rejected', $threw, $failures, $passCount);

$threw = false;
try {
    $scanner->scan('/var/www/some-app');
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'production-like');
}
check('a production-like path is rejected', $threw, $failures, $passCount);

$threw = false;
try {
    $scanner->scan(sys_get_temp_dir() . '/wi-does-not-exist-' . bin2hex(random_bytes(6)));
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'does not exist');
}
check('a nonexistent path is rejected', $threw, $failures, $passCount);

// ---------------------------------------------------------------------------
// Symlink/reparse-point root safety, where the platform permits creating one
// ---------------------------------------------------------------------------
$wsSymlink = makeTempDir('wi-symlink');
try {
    $repo = "{$wsSymlink}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);

    $linkPath = "{$wsSymlink}/repo-link";
    $linked = false;
    if (PHP_OS_FAMILY === 'Windows') {
        // Junctions don't require elevated privileges, unlike Windows
        // symlinks — attempt one; skip gracefully if this environment
        // still refuses it.
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open(['cmd', '/c', 'mklink', '/J', $linkPath, $repo], $descriptorSpec, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $linked = $exitCode === 0 && is_dir($linkPath);
        }
    } else {
        $linked = @symlink($repo, $linkPath) && is_dir($linkPath);
    }

    if ($linked) {
        $threw = false;
        try {
            $scanner->scan($linkPath);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'symlink/junction');
        }
        check('a symlink/junction workspace root is rejected', $threw, $failures, $passCount);
    } else {
        check('symlink/junction root test skipped (this environment could not create one)', true, $failures, $passCount);
    }
} finally {
    rrmdir($wsSymlink);
}

// ---------------------------------------------------------------------------
// Truncation and long-path handling — exercised directly against
// buildInventory() via Reflection with synthetic entries, since creating
// 500+ real files (or a real >1000-character path, which risks Windows'
// own MAX_PATH limit) would make this fixture slow and platform-fragile
// without testing anything buildInventory() doesn't already fully own.
// ---------------------------------------------------------------------------
function invokeBuildInventory(WorkspaceInventoryScanner $scanner, array $entries, string $canonicalRoot): array
{
    $parsed = [
        'branch' => ['oid' => str_repeat('a', 40), 'head' => 'main', 'detached' => false, 'upstream' => null, 'ahead' => null, 'behind' => null],
        'entries' => $entries,
    ];
    $method = new ReflectionMethod(WorkspaceInventoryScanner::class, 'buildInventory');
    $method->setAccessible(true);

    return $method->invoke($scanner, $parsed, $canonicalRoot);
}

function syntheticEntry(string $path): array
{
    return ['path' => $path, 'old_path' => null, 'status' => 'untracked', 'index_status' => null, 'worktree_status' => null, 'staged' => false, 'tracked' => false];
}

$wsReflect = makeTempDir('wi-reflect');
try {
    // Truncation: 520 entries against the default 500 return limit.
    $manyEntries = [];
    for ($n = 0; $n < 520; $n++) {
        $manyEntries[] = syntheticEntry(sprintf('file%04d.txt', $n));
    }
    $invTruncated = invokeBuildInventory($scanner, $manyEntries, $wsReflect);
    check('truncation: total_changed_count remains exact (520)', $invTruncated['total_changed_count'] === 520, $failures, $passCount);
    check('truncation: files[] is capped at the default 500', count($invTruncated['files']) === 500, $failures, $passCount);
    check('truncation: is_truncated is true', $invTruncated['is_truncated'] === true, $failures, $passCount);
    check('truncation: omitted_file_count is 20', $invTruncated['omitted_file_count'] === 20, $failures, $passCount);
    check('truncation: untracked_count remains exact (520)', $invTruncated['untracked_count'] === 520, $failures, $passCount);

    // Long path handling: one entry exceeds the 1000-character path limit.
    $longPath = str_repeat('x', 1500) . '.txt';
    $pathEntries = [syntheticEntry('short.txt'), syntheticEntry($longPath)];
    $invLongPath = invokeBuildInventory($scanner, $pathEntries, $wsReflect);
    check('long path: total_changed_count still counts both (2)', $invLongPath['total_changed_count'] === 2, $failures, $passCount);
    check('long path: the overlong path is excluded from files[]', findEntry($invLongPath, $longPath) === null, $failures, $passCount);
    check('long path: the short path is still returned', findEntry($invLongPath, 'short.txt') !== null, $failures, $passCount);
    check('long path: omitted_file_count is 1', $invLongPath['omitted_file_count'] === 1, $failures, $passCount);
    check('long path: is_truncated is true', $invLongPath['is_truncated'] === true, $failures, $passCount);

    // Hard maximum: a caller-requested return limit above 2000 is clamped.
    $scannerHuge = new WorkspaceInventoryScanner($git, 999999);
    $maxProperty = new ReflectionProperty(WorkspaceInventoryScanner::class, 'maxReturnedFiles');
    $maxProperty->setAccessible(true);
    check('hard maximum: a requested limit above 2000 is clamped to 2000', $maxProperty->getValue($scannerHuge) === 2000, $failures, $passCount);
} finally {
    rrmdir($wsReflect);
}

// ---------------------------------------------------------------------------
// Deterministic snapshot hash / changes when Git state changes
// ---------------------------------------------------------------------------
$wsHash = makeTempDir('wi-hash');
try {
    $repo = "{$wsHash}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/base.txt", "base\n");
    commitAll($git, $repo);
    file_put_contents("{$repo}/scratch.txt", "one\n");

    $invA = $scanner->scan($repo);
    $invB = $scanner->scan($repo); // unchanged state, scanned again
    check('snapshot_hash: deterministic across repeated scans of the same state', $invA['snapshot_hash'] === $invB['snapshot_hash'], $failures, $passCount);
    check('snapshot_hash: looks like a sha256 hex digest', preg_match('/^[0-9a-f]{64}$/', $invA['snapshot_hash']) === 1, $failures, $passCount);

    // Editing an untracked file's content doesn't change git's own status
    // entries at all (git never diffs untracked content) — adding a
    // second untracked file does change the entry set, which is the
    // actual "relevant workspace state" the hash contract cares about.
    file_put_contents("{$repo}/scratch2.txt", "two\n");
    $invC = $scanner->scan($repo);
    check('snapshot_hash: changes when the working tree changes', $invC['snapshot_hash'] !== $invA['snapshot_hash'], $failures, $passCount);
} finally {
    rrmdir($wsHash);
}

// ---------------------------------------------------------------------------
// CRITICAL TEST: workspace inventory scanning invokes NO mutating Git
// operation. See this file's own header for why this is outcome-based.
// ---------------------------------------------------------------------------
$wsMutation = makeTempDir('wi-mutation');
try {
    $repo = "{$wsMutation}/repo";
    initRepo($git, $repo);
    file_put_contents("{$repo}/tracked.txt", "base\n");
    commitAll($git, $repo);
    file_put_contents("{$repo}/tracked.txt", "modified\n");
    file_put_contents("{$repo}/untracked.txt", "new\n");
    runGit($git, ['add', 'untracked.txt'], $repo); // one staged file too

    $headBefore = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);
    $branchBefore = trim(runGit($git, ['rev-parse', '--abbrev-ref', 'HEAD'], $repo)['output']);
    $statusBefore = runGit($git, ['status', '--porcelain=v2', '-z', '--branch', '--ahead-behind', '--untracked-files=all'], $repo)['output'];
    $treeHashBefore = treeContentHash($repo);

    $inv = $scanner->scan($repo);
    check('mutation guard: scan actually observed the dirty state (sanity check)', $inv['total_changed_count'] === 2, $failures, $passCount);

    $headAfter = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);
    $branchAfter = trim(runGit($git, ['rev-parse', '--abbrev-ref', 'HEAD'], $repo)['output']);
    $statusAfter = runGit($git, ['status', '--porcelain=v2', '-z', '--branch', '--ahead-behind', '--untracked-files=all'], $repo)['output'];
    $treeHashAfter = treeContentHash($repo);

    check('mutation guard: HEAD is unchanged after scanning', $headBefore === $headAfter, $failures, $passCount);
    check('mutation guard: current branch is unchanged after scanning', $branchBefore === $branchAfter, $failures, $passCount);
    check('mutation guard: git\'s own status output is byte-identical before/after scanning (nothing staged/unstaged/reset)', $statusBefore === $statusAfter, $failures, $passCount);
    check('mutation guard: the working tree\'s file set and content are byte-identical before/after scanning', $treeHashBefore === $treeHashAfter, $failures, $passCount);
    check('mutation guard: no .git/MERGE_HEAD, ORIG_HEAD, or lock file was left behind', !is_file("{$repo}/.git/MERGE_HEAD") && !is_file("{$repo}/.git/index.lock"), $failures, $passCount);
} finally {
    rrmdir($wsMutation);
}

// ---------------------------------------------------------------------------
// GitStatusPorcelainV2Parser — direct unit coverage independent of the scanner
// ---------------------------------------------------------------------------
$rawOrdinary = "1 M. N... 100644 100644 100644 0000000000000000000000000000000000000000 0000000000000000000000000000000000000000 modified.txt\0";
$parsedOrdinary = GitStatusPorcelainV2Parser::parse($rawOrdinary);
check('parser: ordinary record parsed', count($parsedOrdinary['entries']) === 1, $failures, $passCount);
check('parser: ordinary record path', $parsedOrdinary['entries'][0]['path'] === 'modified.txt', $failures, $passCount);
check('parser: ordinary record status', $parsedOrdinary['entries'][0]['status'] === 'modified', $failures, $passCount);
check('parser: ordinary record staged is true (index side is M)', $parsedOrdinary['entries'][0]['staged'] === true, $failures, $passCount);

$rawRename = "2 R100 N... 100644 100644 100644 0000000000000000000000000000000000000000 0000000000000000000000000000000000000000 R100 new name.txt\0old name.txt\0";
$parsedRename = GitStatusPorcelainV2Parser::parse($rawRename);
check('parser: rename record path (with a space) parsed correctly', $parsedRename['entries'][0]['path'] === 'new name.txt', $failures, $passCount);
check('parser: rename record old_path parsed correctly', $parsedRename['entries'][0]['old_path'] === 'old name.txt', $failures, $passCount);
check('parser: rename record status is renamed', $parsedRename['entries'][0]['status'] === 'renamed', $failures, $passCount);

$rawUntracked = "? some dir/new file.txt\0";
$parsedUntracked = GitStatusPorcelainV2Parser::parse($rawUntracked);
check('parser: untracked record with a space in a subdirectory', $parsedUntracked['entries'][0]['path'] === 'some dir/new file.txt', $failures, $passCount);
check('parser: untracked record has no XY code', $parsedUntracked['entries'][0]['index_status'] === null, $failures, $passCount);

$rawUnmergedBothAdded = "u AA N... 100644 100644 100644 100644 0000000000000000000000000000000000000000 0000000000000000000000000000000000000000 0000000000000000000000000000000000000000 conflict.txt\0";
$parsedUnmerged = GitStatusPorcelainV2Parser::parse($rawUnmergedBothAdded);
check('parser: "AA" unmerged (both added) is still labeled conflicted, not added', $parsedUnmerged['entries'][0]['status'] === 'conflicted', $failures, $passCount);

$rawEmpty = '';
$parsedEmpty = GitStatusPorcelainV2Parser::parse($rawEmpty);
check('parser: empty output parses to no entries, no error', $parsedEmpty['entries'] === [], $failures, $passCount);

$rawDetached = "# branch.oid " . str_repeat('a', 40) . "\0# branch.head (detached)\0";
$parsedDetached = GitStatusPorcelainV2Parser::parse($rawDetached);
check('parser: detached HEAD header sets detached=true and head=null', $parsedDetached['branch']['detached'] === true && $parsedDetached['branch']['head'] === null, $failures, $passCount);

$rawUnborn = "# branch.oid (initial)\0# branch.head main\0";
$parsedUnborn = GitStatusPorcelainV2Parser::parse($rawUnborn);
check('parser: unborn branch "(initial)" oid parses to a null head_sha, not the literal string', $parsedUnborn['branch']['oid'] === null, $failures, $passCount);

$rawAheadBehind = "# branch.ab +3 -5\0";
$parsedAb = GitStatusPorcelainV2Parser::parse($rawAheadBehind);
check('parser: branch.ab parses ahead/behind as integers', $parsedAb['branch']['ahead'] === 3 && $parsedAb['branch']['behind'] === 5, $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
