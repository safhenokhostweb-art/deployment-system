<?php

declare(strict_types=1);

/**
 * Real bug reported after v1.4.1 shipped (the PATH fix): once Composer
 * itself could be launched reliably, deployment still failed because the
 * configured PHP binary was missing a required extension
 * (phpoffice/phpspreadsheet requires ext-gd, not enabled in this
 * machine's php.ini) — reported only as a generic, undifferentiated
 * local_composer_failed after Composer had already downloaded every
 * package.
 *
 * This suite proves LocalBuildRunner's new pre-flight check
 * (assertPlatformRequirementsSatisfied(), run via Composer's own
 * `check-platform-reqs`) catches this BEFORE `composer install` starts,
 * with its own `missing_php_extension` failure code, the exact missing
 * extension name, and the loaded php.ini path/`php --ini`/`php -m`
 * output attached for diagnosis — and, just as importantly, never
 * blocks a project whose requirements ARE satisfied.
 *
 * Same philosophy as the rest of this test suite: no mocking, real
 * composer.lock fixtures, real child processes.
 *
 * Usage: php tests/run_platform_requirements.php
 */

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/LocalBuildRunner.php';

use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\LocalBuildRunner;

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

function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/local-agent-platformreq-test-' . bin2hex(random_bytes(6));
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

/**
 * A composer.lock declaring a single locked package that itself requires
 * the given extension — exactly how a real transitive dependency (e.g.
 * phpoffice/phpspreadsheet requiring ext-gd) shows up in a real project's
 * lock file, without needing network access to a real package at all.
 */
function writeFixtureWithLockedExtensionRequirement(string $repo, string $packageName, string $extension): void
{
    mkdir($repo, 0777, true);
    file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/app', 'require' => [$packageName => '1.0.0']]));
    file_put_contents("{$repo}/composer.lock", json_encode([
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => md5($packageName . $extension),
        'packages' => [[
            'name' => $packageName,
            'version' => '1.0.0',
            'type' => 'library',
            'require' => [$extension => '*', 'php' => '>=7.4'],
        ]],
        'packages-dev' => [],
        'aliases' => [],
        'minimum-stability' => 'stable',
        'stability-flags' => [],
        'prefer-stable' => false,
        'prefer-lowest' => false,
        'platform' => [],
        'platform-dev' => [],
        'plugin-api-version' => '2.6.0',
    ]));
}

// ============================================================
// 1. A locked package requiring a genuinely nonexistent extension must
//    fail fast with missing_php_extension, BEFORE composer install ever
//    runs (proven by vendor/ never being created — no network activity,
//    no package download attempted at all).
// ============================================================
$workspace1 = makeWorkspace();
$repo1 = $workspace1 . '/repo';
writeFixtureWithLockedExtensionRequirement($repo1, 'fake/pkg-needing-gd', 'ext-does-not-exist-xyz-4471');

$git1 = new GitManager();
$runner1 = new LocalBuildRunner($git1);

$caught1 = null;
try {
    $runner1->run($repo1);
} catch (LocalBuildFailedException $e) {
    $caught1 = $e;
}

check('a missing locked-package extension requirement throws (not silently succeeds)', $caught1 !== null, $failures, $passCount);
if ($caught1 !== null) {
    check('failureCode is the dedicated missing_php_extension, not the generic local_composer_failed', $caught1->failureCode === 'missing_php_extension', $failures, $passCount);
    check('the message names the exact missing extension', str_contains($caught1->getMessage(), 'ext-does-not-exist-xyz-4471'), $failures, $passCount);
    check('the message names the package that required it', str_contains($caught1->getMessage(), 'fake/pkg-needing-gd'), $failures, $passCount);
    check('the message tells the operator not to bypass with --ignore-platform-req', str_contains($caught1->getMessage(), '--ignore-platform-req'), $failures, $passCount);
    check('the message includes the loaded php.ini path', str_contains($caught1->getMessage(), 'Loaded php.ini:') && str_contains($caught1->getMessage(), 'php.ini'), $failures, $passCount);
    check('the message includes the raw "php --ini" output', str_contains($caught1->getMessage(), '--- php --ini ---') && str_contains($caught1->getMessage(), 'Loaded Configuration File'), $failures, $passCount);
    check('the message includes the raw "php -m" output', str_contains($caught1->getMessage(), '--- php -m (loaded modules) ---') && str_contains($caught1->getMessage(), '[PHP Modules]'), $failures, $passCount);
}
check('composer install never ran — no vendor/ directory was created', !is_dir("{$repo1}/vendor"), $failures, $passCount);
rrmdir($workspace1);

// ============================================================
// 2. A locked package requiring an extension that IS actually loaded
//    must pass the pre-check and proceed to a REAL composer install.
//    ext-json is a Composer/PHP-core requirement and is always present.
// ============================================================
$workspace2 = makeWorkspace();
$repo2 = $workspace2 . '/repo';
writeFixtureWithLockedExtensionRequirement($repo2, 'fake/pkg-needing-json', 'ext-json');
// The locked package itself isn't real/installable, so a real `composer
// install` would still fail trying to download it — that's a SEPARATE,
// expected failure (local_composer_failed via a real network/resolution
// error), not the thing under test here. What matters is that it is NOT
// misreported as missing_php_extension — the platform check must let a
// satisfied requirement straight through.
$git2 = new GitManager();
$runner2 = new LocalBuildRunner($git2, composerTimeoutSeconds: 30);
$caught2 = null;
try {
    $runner2->run($repo2);
} catch (LocalBuildFailedException $e) {
    $caught2 = $e;
}
check('a satisfied extension requirement (ext-json) never triggers missing_php_extension', $caught2 === null || $caught2->failureCode !== 'missing_php_extension', $failures, $passCount);
rrmdir($workspace2);

// ============================================================
// 3. A project with no composer.lock and no ext-* requirements at all
//    (the common case) is completely unaffected — real install proceeds
//    and succeeds, exactly as before this change.
// ============================================================
$workspace3 = makeWorkspace();
$repo3 = $workspace3 . '/repo';
mkdir($repo3, 0777, true);
file_put_contents("{$repo3}/composer.json", json_encode(['name' => 'fixture/plain-app']));
$git3 = new GitManager();
$runner3 = new LocalBuildRunner($git3);
$result3 = $runner3->run($repo3);
check('a plain project with no ext-* requirements still installs normally (unaffected by the new check)', $result3['ranComposer'] === true && is_file("{$repo3}/vendor/autoload.php"), $failures, $passCount);
rrmdir($workspace3);

// ============================================================
// 4. Private helper behavior via Reflection — pure logic, no process.
// ============================================================
$extractJsonArray = new \ReflectionMethod(LocalBuildRunner::class, 'extractJsonArray');
$extractJsonArray->setAccessible(true);
$extractLoadedIniPath = new \ReflectionMethod(LocalBuildRunner::class, 'extractLoadedIniPath');
$extractLoadedIniPath->setAccessible(true);
$resolvedDiagnosticPhpBinary = new \ReflectionMethod(LocalBuildRunner::class, 'resolvedDiagnosticPhpBinary');
$resolvedDiagnosticPhpBinary->setAccessible(true);

check(
    'extractJsonArray() parses the JSON array even with trailing stderr-style text appended after it (as GitManager::run() combines stdout+stderr)',
    $extractJsonArray->invoke(null, "[{\"name\":\"ext-gd\",\"status\":\"missing\"}]\nSome unrelated warning line\n") === [['name' => 'ext-gd', 'status' => 'missing']],
    $failures,
    $passCount,
);
check('extractJsonArray() returns null for output containing no JSON array at all', $extractJsonArray->invoke(null, 'Failed to start process for: composer check-platform-reqs') === null, $failures, $passCount);

check(
    'extractLoadedIniPath() finds the real path from a genuine "php --ini" block',
    str_contains((string) $extractLoadedIniPath->invoke(null, "Configuration File (php.ini) Path: \nLoaded Configuration File:         C:\\laragon\\bin\\php\\php.ini\nScan for additional .ini files in: (none)"), 'C:\\laragon\\bin\\php\\php.ini'),
    $failures,
    $passCount,
);
check(
    'extractLoadedIniPath() reports "(none ...)" clearly when no php.ini is loaded at all',
    str_contains((string) $extractLoadedIniPath->invoke(null, "Configuration File (php.ini) Path: \nLoaded Configuration File:         (none)\n"), '(none'),
    $failures,
    $passCount,
);

$gitR = new GitManager();
check(
    'resolvedDiagnosticPhpBinary(): an explicit php_binary is always preferred',
    $resolvedDiagnosticPhpBinary->invoke(new LocalBuildRunner($gitR, ['composer'], ['npm'], 'C:\\explicit\\php.exe')) === 'C:\\explicit\\php.exe',
    $failures,
    $passCount,
);
check(
    'resolvedDiagnosticPhpBinary(): falls back to the composer_command\'s own interpreter when it looks like a php executable',
    $resolvedDiagnosticPhpBinary->invoke(new LocalBuildRunner($gitR, ['C:\\laragon\\bin\\php\\php.exe', 'C:\\laragon\\bin\\composer\\composer.phar'], ['npm'])) === 'C:\\laragon\\bin\\php\\php.exe',
    $failures,
    $passCount,
);
check(
    'resolvedDiagnosticPhpBinary(): falls back to bare "php" when composer_command is just [\'composer\'] with nothing else to go on',
    $resolvedDiagnosticPhpBinary->invoke(new LocalBuildRunner($gitR, ['composer'], ['npm'])) === 'php',
    $failures,
    $passCount,
);

// ============================================================
// 5. A composer_command that can't even start a process (the scenario
//    already covered for the real install itself in
//    run_command_resolution.php) must NOT be misreported as
//    missing_php_extension — the pre-check is best-effort and defers to
//    the real install's own (correct) local_composer_failed in that case.
// ============================================================
$workspace5 = makeWorkspace();
$repo5 = $workspace5 . '/repo';
writeFixtureWithLockedExtensionRequirement($repo5, 'fake/pkg-needing-json', 'ext-json');
$git5 = new GitManager();
$runner5 = new LocalBuildRunner($git5, [$workspace5 . '/this-composer-does-not-exist.exe']);
$caught5 = null;
try {
    $runner5->run($repo5);
} catch (LocalBuildFailedException $e) {
    $caught5 = $e;
}
check('an unusable composer_command falls through to the real install\'s own local_composer_failed, never a false missing_php_extension', $caught5 !== null && $caught5->failureCode === 'local_composer_failed', $failures, $passCount);
rrmdir($workspace5);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
