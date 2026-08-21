<?php

declare(strict_types=1);

/**
 * Regression test: GitManager::changedFiles() must parse `git status
 * --porcelain -z` NUL-separated records so paths containing spaces,
 * quotes or special characters (=, parentheses, etc.) are returned
 * byte-for-byte WITHOUT git's C-quoting.
 *
 * Real bug: the old parser used `git status --porcelain` (line-oriented)
 * and `substr($line, 3)`. Git C-quotes any path with special characters
 * in that format ? e.g. a file literally named
 *   service/=  app(AppServices...class);
 * came back as
 *   ?? "service/=  app(...);"
 * and substr(3) kept the literal quote characters, producing a `git add`
 * pathspec that could never match the real file:
 *   fatal: pathspec '"service/=  app(...);"' did not match any files
 *
 * These tests build a REAL git repo with REAL files named exactly like
 * the corruption that caused the production incident, then prove
 * changedFiles() returns the true paths AND that `git add -A -- <each
 * path>` actually stages them.
 *
 * Note on the double-quote fixture: a literal `"` in a filename is not
 * creatable on Windows (reserved character), so that fixture runs only
 * on non-Windows platforms. Spaces / `=` / parentheses are creatable
 * everywhere and are what git C-quotes in practice.
 *
 * Usage: php tests/run_git_parser_hardening.php
 */

require __DIR__ . '/../lib/GitManager.php';

use LocalAgent\GitManager;

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
 * Creates a fresh git repo and a GitManager wired to run in it.
 *
 * @return array{git: GitManager, repo: string}
 */
function buildRepo(string $workspace, string $name = 'repo'): array
{
    $repo = "{$workspace}/{$name}";
    mkdir($repo, 0777, true);

    $git = new GitManager(
        timeouts: ['status' => 30, 'add' => 60, 'commit' => 60],
        jobHeartbeatIntervalSeconds: 10,
    );

    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Git Parser Hardening Test'], $repo);

    return ['git' => $git, 'repo' => $repo];
}

function commitAll(GitManager $git, string $repo): void
{
    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);
}

// ---------------------------------------------------------------------------
// Fixture 1: filename with spaces (creatable everywhere; git C-quotes it)
// ---------------------------------------------------------------------------
$ws1 = makeTempDir('gph-space');
try {
    ['git' => $git1, 'repo' => $repo1] = buildRepo($ws1);
    file_put_contents("{$repo1}/file with spaces.txt", "spacey\n");
    commitAll($git1, $repo1);

    // Modify the tracked file AND add a new untracked file with spaces.
    file_put_contents("{$repo1}/file with spaces.txt", "changed\n");
    file_put_contents("{$repo1}/new file with spaces.txt", "new\n");

    $files = $git1->changedFiles($repo1);
    check('spaces: changedFiles returns exact unquoted paths', in_array('file with spaces.txt', $files, true) && in_array('new file with spaces.txt', $files, true), $failures, $passCount);
    check('spaces: no literal quotes in returned paths', !in_array('"file with spaces.txt"', $files, true) && !in_array('"new file with spaces.txt"', $files, true), $failures, $passCount);

    // Prove the returned paths are usable as git add pathspecs.
    $addArgs = array_merge(['add', '-A', '--'], $files);
    $add = runGit($git1, $addArgs, $repo1);
    check('spaces: git add -A -- <paths> succeeds', $add['exitCode'] === 0, $failures, $passCount);
    $staged = runGit($git1, ['diff', '--cached', '--name-only'], $repo1)['output'];
    check('spaces: both files staged', str_contains($staged, 'file with spaces.txt') && str_contains($staged, 'new file with spaces.txt'), $failures, $passCount);
} finally {
    rrmdir($ws1);
}

// ---------------------------------------------------------------------------
// Fixture 2: filename with = and parentheses (exact production incident shape)
// ---------------------------------------------------------------------------
$ws3 = makeTempDir('gph-eqparen');
try {
    ['git' => $git3, 'repo' => $repo3] = buildRepo($ws3);
    // The production-poisoning name, with a single colon so it is creatable
    // on Windows too (a literal `::` is an NTFS alternate-data-stream
    // separator there). The C-quoting behavior git applies is identical.
    $corrupted = 'service/=  app(AppServicesSecurityAgentCredentialService.class);';
    @mkdir("{$repo3}/service", 0777, true);
    file_put_contents("{$repo3}/{$corrupted}", "<?php\n");
    commitAll($git3, $repo3);
    file_put_contents("{$repo3}/{$corrupted}", "<?php // changed\n");
    file_put_contents("{$repo3}/service/new file with = and (parens).txt", "new\n");

    $files = $git3->changedFiles($repo3);
    check('=+parens: changedFiles returns the exact path, unquoted', in_array($corrupted, $files, true), $failures, $passCount);
    check('=+parens: no C-quote wrapper on returned path', !in_array('"' . $corrupted . '"', $files, true), $failures, $passCount);
    check('=+parens: other special-char path returned unquoted', in_array('service/new file with = and (parens).txt', $files, true), $failures, $passCount);

    // The exact failure that killed production: git add with the parsed paths must succeed.
    $add = runGit($git3, array_merge(['add', '-A', '--'], $files), $repo3);
    check('=+parens: git add -A -- <paths> succeeds (was fatal: pathspec)', $add['exitCode'] === 0, $failures, $passCount);
    if ($add['exitCode'] !== 0) {
        echo "    git add stderr: " . trim($add['output']) . PHP_EOL;
    }
    $staged = runGit($git3, ['diff', '--cached', '--name-only'], $repo3)['output'];
    check('=+parens: path staged successfully', str_contains($staged, $corrupted), $failures, $passCount);
    check('=+parens: other path staged successfully', str_contains($staged, 'service/new file with = and (parens).txt'), $failures, $passCount);
} finally {
    rrmdir($ws3);
}

// ---------------------------------------------------------------------------
// Fixture 3: filename with a literal double quote (non-Windows only)
// ---------------------------------------------------------------------------
if (PHP_OS_FAMILY !== 'Windows') {
    $ws2 = makeTempDir('gph-quote');
    try {
        ['git' => $git2, 'repo' => $repo2] = buildRepo($ws2);
        $quotedName = 'my "quoted" file.txt';
        file_put_contents("{$repo2}/{$quotedName}", "quoted\n");
        commitAll($git2, $repo2);
        file_put_contents("{$repo2}/{$quotedName}", "changed\n");

        $files = $git2->changedFiles($repo2);
        check('quotes: changedFiles returns exact path containing a quote', in_array($quotedName, $files, true), $failures, $passCount);

        $add = runGit($git2, array_merge(['add', '-A', '--'], $files), $repo2);
        check('quotes: git add -A -- <paths> succeeds', $add['exitCode'] === 0, $failures, $passCount);
        $staged = runGit($git2, ['diff', '--cached', '--name-only'], $repo2)['output'];
        check('quotes: quoted file staged', str_contains($staged, $quotedName), $failures, $passCount);
    } finally {
        rrmdir($ws2);
    }
} else {
    check('quotes: skipped on Windows (literal quote is a reserved filename char)', true, $failures, $passCount);
}

// ---------------------------------------------------------------------------
// Fixture 4: rename detection still works ("old -> new" form) under -z
// ---------------------------------------------------------------------------
$ws4 = makeTempDir('gph-rename');
try {
    ['git' => $git4, 'repo' => $repo4] = buildRepo($ws4);
    file_put_contents("{$repo4}/old name.txt", "old\n");
    commitAll($git4, $repo4);
    rename("{$repo4}/old name.txt", "{$repo4}/new name.txt");

    $files = $git4->changedFiles($repo4);
    check('rename: both sides returned', in_array('new name.txt', $files, true) && in_array('old name.txt', $files, true), $failures, $passCount);

    $add = runGit($git4, array_merge(['add', '-A', '--'], $files), $repo4);
    check('rename: git add -A -- <paths> succeeds', $add['exitCode'] === 0, $failures, $passCount);
    $staged = runGit($git4, ['diff', '--cached', '--name-status'], $repo4)['output'];
    check('rename: rename staged', str_contains($staged, 'new name.txt') && str_contains($staged, 'old name.txt'), $failures, $passCount);
} finally {
    rrmdir($ws4);
}

// ---------------------------------------------------------------------------
// Fixture 5: clean tree returns empty (regression guard)
// ---------------------------------------------------------------------------
$ws5 = makeTempDir('gph-clean');
try {
    ['git' => $git5, 'repo' => $repo5] = buildRepo($ws5);
    file_put_contents("{$repo5}/base.txt", "base\n");
    commitAll($git5, $repo5);

    $files = $git5->changedFiles($repo5);
    check('clean tree: changedFiles returns empty array', $files === [], $failures, $passCount);
} finally {
    rrmdir($ws5);
}

echo "Git parser hardening: {$passCount} passed, " . count($failures) . " failed" . PHP_EOL;
if ($failures !== []) {
    foreach ($failures as $f) {
        echo "  FAIL: {$f}" . PHP_EOL;
    }
    exit(1);
}
