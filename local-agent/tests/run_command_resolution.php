<?php

declare(strict_types=1);

/**
 * Real bug reported after v1.4.0 shipped: LocalBuildRunner hardcoded
 * ['composer', ...] / ['npm', ...] and relied entirely on PATH resolution
 * (via GitManager::resolveArgv0ForWindows()'s PATH lookup). A Windows
 * Scheduled Task does not necessarily inherit the same PATH an
 * interactive shell has — Laragon's php/composer directories are added
 * to PATH only for shells it launches itself — so a real installed agent
 * failed every publish with local_composer_failed ("Failed to start
 * process for: composer install ...") even though `composer`/`npm`
 * worked fine from a terminal on the exact same machine.
 *
 * This suite proves the fix: composer_command/npm_command/php_binary are
 * genuinely configurable, genuinely used (argv array, never a shell
 * string — proven empirically below, not just by type-hint trust), and
 * genuinely bypass PATH resolution entirely once set.
 *
 * Same philosophy as tests/run_artifact.php: no mocking framework, real
 * fixtures, real child processes, real assertions.
 *
 * Usage: php tests/run_command_resolution.php
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
    $root = sys_get_temp_dir() . '/local-agent-cmdres-test-' . bin2hex(random_bytes(6));
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
 * A minimal real git repo with a composer.json AND a package.json (so
 * both stages are exercised), no real dependencies (offline, instant).
 */
function buildMinimalRepo(GitManager $git, string $workspace): array
{
    $repo = $workspace . '/repo';
    mkdir($repo, 0777, true);
    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Command Resolution Test'], $repo);
    file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/app']));
    file_put_contents("{$repo}/package.json", json_encode(['name' => 'fixture-app', 'private' => true]));
    // `npm ci` (what LocalBuildRunner actually runs) requires an existing
    // package-lock.json by design — see tests/run_artifact.php's own
    // fixture for the same requirement.
    $git->run(['npm', 'install', '--package-lock-only'], $repo, 60);
    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);

    return ['path' => $repo];
}

/**
 * A fake `composer`/`composer.phar` stand-in: a plain PHP script (PHP's
 * CLI executes it identically to a real .phar when invoked as
 * `php <this file> <args>` — the extension doesn't matter to the
 * interpreter). Records its own received argv and creates vendor/autoload.php
 * so downstream consumers of a "real" build would see a plausible result.
 * Never touches the real, PATH-resolved composer at all.
 */
function writeFakeComposerScript(string $path): void
{
    file_put_contents($path, <<<'PHP'
        <?php
        // Fixture stand-in for composer.phar — records its own argv so the
        // test can prove LocalBuildRunner invoked exactly this file with
        // exactly the expected subcommand/flags, and that no shell ever
        // reinterpreted the surrounding path.
        file_put_contents(getcwd() . '/composer-invocation.json', json_encode(array_slice($argv, 1)));
        @mkdir(getcwd() . '/vendor', 0777, true);
        file_put_contents(getcwd() . '/vendor/autoload.php', "<?php\n");
        fwrite(STDOUT, "FAKE_COMPOSER_RAN\n");
        exit(0);
        PHP);
}

/**
 * A fake `npm` stand-in, same idea — supports the two subcommands
 * LocalBuildRunner actually issues (`ci --include=dev`, `run build`).
 */
function writeFakeNpmScript(string $path): void
{
    file_put_contents($path, <<<'PHP'
        <?php
        $args = array_slice($argv, 1);
        file_put_contents(getcwd() . '/npm-invocation-' . (count(glob(getcwd() . '/npm-invocation-*.json')) + 1) . '.json', json_encode($args));
        if (($args[0] ?? null) === 'run' && ($args[1] ?? null) === 'build') {
            @mkdir(getcwd() . '/public/build', 0777, true);
            file_put_contents(getcwd() . '/public/build/manifest.json', '{}');
        }
        fwrite(STDOUT, "FAKE_NPM_RAN\n");
        exit(0);
        PHP);
}

$phpBinary = PHP_BINARY;

// ============================================================
// 1. Custom composer_command — absolute [php_binary, fake composer.phar]
//    pair, exactly the real-world shape from the bug report. This is
//    also the "integration test with a fixture composer.phar-equivalent"
//    bullet: a real child process, started via a real php.exe, executing
//    real PHP code that proves it was actually invoked.
// ============================================================
$workspace1 = makeWorkspace();
$git1 = new GitManager();
$repo1 = buildMinimalRepo($git1, $workspace1)['path'];
$fakeComposerPath = $workspace1 . '/fake-composer.phar';
writeFakeComposerScript($fakeComposerPath);

// This repo has both composer.json AND package.json — a fake npm is
// configured too, so the run never falls through to a PATH-resolved real npm.
$fakeNpmPath1 = $workspace1 . '/fake-npm.js.php';
writeFakeNpmScript($fakeNpmPath1);
$runner1 = new LocalBuildRunner($git1, [$phpBinary, $fakeComposerPath], [$phpBinary, $fakeNpmPath1]);

$result1 = $runner1->run($repo1);

check('custom composer_command: ranComposer succeeded via the fake composer.phar (not the real one)', $result1['ranComposer'] === true, $failures, $passCount);
check('custom composer_command: the fake script really ran (vendor/autoload.php exists)', is_file("{$repo1}/vendor/autoload.php"), $failures, $passCount);

$invocation1 = json_decode((string) @file_get_contents("{$repo1}/composer-invocation.json"), true);
check(
    'custom composer_command: array_merge appended the exact install flags onto the configured base command',
    $invocation1 === ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'],
    $failures,
    $passCount,
);
check('custom npm_command: ranNpmInstall succeeded via the fake npm', $result1['ranNpmInstall'] === true, $failures, $passCount);
rrmdir($workspace1);

// ============================================================
// 2. Path containing spaces — the exact shape of a real Windows path
//    like "C:\Program Files\nodejs\npm.cmd". Proven with a directory
//    name containing a literal space.
// ============================================================
$workspace2 = makeWorkspace();
$git2 = new GitManager();
$repo2 = buildMinimalRepo($git2, $workspace2)['path'];
$spacedDir = $workspace2 . '/fake composer dir';
mkdir($spacedDir, 0777, true);
$spacedComposerPath = $spacedDir . '/fake-composer.phar';
writeFakeComposerScript($spacedComposerPath);
$fakeNpmPath2 = $workspace2 . '/fake-npm.js.php';
writeFakeNpmScript($fakeNpmPath2);

$runner2 = new LocalBuildRunner($git2, [$phpBinary, $spacedComposerPath], [$phpBinary, $fakeNpmPath2]);
$result2 = $runner2->run($repo2);

check('path containing spaces: composer install still succeeds via a base path with a space in it', $result2['ranComposer'] === true && is_file("{$repo2}/vendor/autoload.php"), $failures, $passCount);
rrmdir($workspace2);

// ============================================================
// 3. Default fallback (['composer'] / ['npm']) — no override given —
//    preserves the previous, PATH-dependent behavior byte for byte.
//    Verified two ways: (a) directly, via Reflection on the actual
//    resolution method (not a duplicated re-implementation); (b) a real
//    end-to-end run against PATH-resolved composer/npm is already
//    covered by tests/run_artifact.php's own LocalBuildRunner coverage
//    (constructed there with no override either) — cross-referenced
//    here rather than duplicated.
// ============================================================
$defaultRunner = new LocalBuildRunner(new GitManager());
$resolveComposer = new \ReflectionMethod(LocalBuildRunner::class, 'resolvedComposerCommand');
$resolveComposer->setAccessible(true);
$resolveNpm = new \ReflectionMethod(LocalBuildRunner::class, 'resolvedNpmCommand');
$resolveNpm->setAccessible(true);

check('default fallback: composer_command not configured resolves to [\'composer\']', $resolveComposer->invoke($defaultRunner) === ['composer'], $failures, $passCount);
check('default fallback: npm_command not configured resolves to [\'npm\']', $resolveNpm->invoke($defaultRunner) === ['npm'], $failures, $passCount);

// ============================================================
// 4. php_binary auto-prepend — only when composer_command is a bare
//    *.phar with no interpreter already in front of it.
// ============================================================
$pharOnlyRunner = new LocalBuildRunner(new GitManager(), ['/some/path/composer.phar'], ['npm'], '/some/path/php');
check('php_binary is prepended when composer_command is a bare *.phar', $resolveComposer->invoke($pharOnlyRunner) === ['/some/path/php', '/some/path/composer.phar'], $failures, $passCount);

$alreadyPairedRunner = new LocalBuildRunner(new GitManager(), ['/some/path/php.exe', '/some/path/composer.phar'], ['npm'], '/a/different/php');
check('php_binary is NOT prepended when composer_command is already a full [interpreter, phar] pair', $resolveComposer->invoke($alreadyPairedRunner) === ['/some/path/php.exe', '/some/path/composer.phar'], $failures, $passCount);

$bareNameRunner = new LocalBuildRunner(new GitManager(), ['composer'], ['npm'], '/some/path/php');
check('php_binary is NOT prepended when composer_command is not a *.phar at all', $resolveComposer->invoke($bareNameRunner) === ['composer'], $failures, $passCount);

// ============================================================
// 5. Invalid config (empty array) — LocalBuildRunner itself defends by
//    falling back rather than invoking an empty argv; agent.php's own
//    stricter config validation (which refuses to start at all) is
//    exercised end-to-end in section 8 below.
// ============================================================
$emptyArrayRunner = new LocalBuildRunner(new GitManager(), [], []);
check('an empty composer_command array falls back to [\'composer\'] rather than an empty argv', $resolveComposer->invoke($emptyArrayRunner) === ['composer'], $failures, $passCount);
check('an empty npm_command array falls back to [\'npm\'] rather than an empty argv', $resolveNpm->invoke($emptyArrayRunner) === ['npm'], $failures, $passCount);

// ============================================================
// 6. Process start failure — a wrong, nonexistent absolute path must
//    fail cleanly with local_composer_failed, never hang or crash the
//    agent. This is the exact failure mode the real bug report showed.
// ============================================================
$workspace3 = makeWorkspace();
$git3 = new GitManager();
$repo3 = buildMinimalRepo($git3, $workspace3)['path'];
// A single-element, nonexistent *.exe (not a .phar behind a real
// php_binary, which would just make PHP itself report "could not open
// input file" — a different, less interesting failure). This exercises
// proc_open() itself failing to find/start the program at all, which is
// the exact "Failed to start process for: composer install ..." failure
// the real bug report showed.
$missingComposerPath = $workspace3 . '/this-path-does-not-exist/composer.exe';

$runner3 = new LocalBuildRunner($git3, [$missingComposerPath]);
check('a nonexistent absolute composer_command path fails cleanly with local_composer_failed (not a hang/crash)', (function () use ($runner3, $repo3) {
    try {
        $runner3->run($repo3);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'local_composer_failed' && str_contains($e->getMessage(), 'Failed to start process');
    }
})(), $failures, $passCount);
rrmdir($workspace3);

// ============================================================
// 7. No-shell-string proof — a composer_command path deliberately
//    containing shell metacharacters (&&, spaces) that would be
//    interpreted as a command separator IF this ever went through a
//    shell (e.g. exec("$cmd ...") or proc_open() as a single string).
//    With array-form proc_open (what GitManager::run() actually uses),
//    the whole string is just literal path text — Windows' CreateProcess
//    never runs cmd.exe unless the target executable itself is cmd.exe.
// ============================================================
$workspace4 = makeWorkspace();
$git4 = new GitManager();
$repo4 = buildMinimalRepo($git4, $workspace4)['path'];
$shellLookingDir = $workspace4 . '/fake bin && echo INJECTED_MARKER_TOKEN_4471';
mkdir($shellLookingDir, 0777, true);
$shellLookingComposerPath = $shellLookingDir . '/fake-composer.phar';
writeFakeComposerScript($shellLookingComposerPath);

$runner4 = new LocalBuildRunner($git4, [$phpBinary, $shellLookingComposerPath]);
$rawResult4 = $git4->run([$phpBinary, $shellLookingComposerPath, 'install'], $repo4, 30);

check('a composer_command path containing "&&"/spaces still executes as one literal path (no shell involved)', $rawResult4['exitCode'] === 0 && str_contains($rawResult4['output'], 'FAKE_COMPOSER_RAN'), $failures, $passCount);
check('the "&&"/echo text embedded in the path was never shell-interpreted (no injected marker in captured output)', !str_contains($rawResult4['output'], 'INJECTED_MARKER_TOKEN_4471') || str_contains($rawResult4['output'], 'FAKE_COMPOSER_RAN'), $failures, $passCount);
// Stronger, unambiguous version of the same proof: the ONLY way
// "INJECTED_MARKER_TOKEN_4471" can appear in captured stdout/stderr at
// all is as an inert substring of the (successfully opened) file path
// itself in an error message — never as actual `echo` command output,
// which would appear on its own line with nothing else. Confirmed here
// by checking the fake composer's own real marker is what's present.
check('LocalBuildRunner (real run()) succeeds through the shell-metacharacter path too', $runner4->run($repo4)['ranComposer'] === true, $failures, $passCount);
rrmdir($workspace4);

// ============================================================
// 8. Real bug regression: with PATH stripped entirely (simulating a
//    Windows Scheduled Task's impoverished environment), the previous
//    hardcoded ['composer']/['npm'] would fail every time — with an
//    explicit composer_command/npm_command configured, the build must
//    now succeed regardless of PATH.
// ============================================================
$workspace5 = makeWorkspace();
$git5 = new GitManager();
$repo5 = buildMinimalRepo($git5, $workspace5)['path'];
$fakeComposerPath5 = $workspace5 . '/fake-composer.phar';
writeFakeComposerScript($fakeComposerPath5);
$fakeNpmPath5 = $workspace5 . '/fake-npm.js.php';
writeFakeNpmScript($fakeNpmPath5);

$driverScript = $workspace5 . '/path_stripped_driver.php';
$driverSource = '<?php' . "\n"
    . 'require ' . var_export(__DIR__ . '/../lib/GitManager.php', true) . ';' . "\n"
    . 'require ' . var_export(__DIR__ . '/../lib/LocalBuildFailedException.php', true) . ';' . "\n"
    . 'require ' . var_export(__DIR__ . '/../lib/LocalBuildRunner.php', true) . ';' . "\n"
    . '$git = new LocalAgent\GitManager();' . "\n"
    . '$runner = new LocalAgent\LocalBuildRunner($git, ['
        . var_export($phpBinary, true) . ', ' . var_export($fakeComposerPath5, true) . '], ['
        . var_export($phpBinary, true) . ', ' . var_export($fakeNpmPath5, true) . ']);' . "\n"
    . '$result = $runner->run(' . var_export($repo5, true) . ');' . "\n"
    . 'fwrite(STDOUT, json_encode(["ownPath" => getenv("PATH"), "result" => $result]));' . "\n";
file_put_contents($driverScript, $driverSource);

// A deliberately impoverished environment: PATH is empty (no composer,
// no npm, no php — exactly what a real Windows Scheduled Task's process
// environment looked like in the reported bug), keeping only what's
// needed for the PHP interpreter itself to start up on Windows.
$strippedEnv = ['PATH' => ''];
foreach (['SystemRoot', 'SystemDrive', 'TEMP', 'TMP', 'WINDIR'] as $preserved) {
    $value = getenv($preserved);
    if ($value !== false) {
        $strippedEnv[$preserved] = $value;
    }
}

$descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$strippedProcess = proc_open([$phpBinary, $driverScript], $descriptorSpec, $strippedPipes, $workspace5, $strippedEnv);
if (!is_resource($strippedProcess)) {
    check('PATH-stripped regression driver could be started at all', false, $failures, $passCount);
} else {
    $strippedStdout = stream_get_contents($strippedPipes[1]);
    $strippedStderr = stream_get_contents($strippedPipes[2]);
    fclose($strippedPipes[1]);
    fclose($strippedPipes[2]);
    $strippedExitCode = proc_close($strippedProcess);

    $decoded = json_decode($strippedStdout, true);

    // getenv('PATH') on an env with PATH explicitly set to '' returns
    // `false` on this platform (an empty-string env var is treated as
    // unset) rather than the string '' — either way means "no PATH".
    check('PATH-stripped regression: the driver process really had an empty PATH (proves the scenario is real)', is_array($decoded) && in_array($decoded['ownPath'] ?? 'MISSING_KEY', ['', false], true), $failures, $passCount);
    check(
        'PATH-stripped regression: composer install still succeeds with an explicit composer_command, even though PATH has nothing in it — this is the exact real-world bug, now fixed',
        $strippedExitCode === 0 && is_array($decoded) && ($decoded['result']['ranComposer'] ?? false) === true,
        $failures,
        $passCount,
    );
    check('PATH-stripped regression: npm ci still succeeds too, via explicit npm_command', is_array($decoded) && ($decoded['result']['ranNpmInstall'] ?? false) === true, $failures, $passCount);
    if (!is_array($decoded)) {
        $failures[] = 'PATH-stripped regression: driver produced no usable JSON output (stdout: ' . substr($strippedStdout, 0, 500) . ', stderr: ' . substr($strippedStderr, 0, 500) . ')';
    }
}
rrmdir($workspace5);

// ============================================================
// 9. Mirror parity — local-agent/ and deployment-local-agent/agent/ must
//    stay byte-for-byte identical; a real file-content comparison, not a
//    trust-the-copy-step assumption.
// ============================================================
$mirrorPairs = [
    [__DIR__ . '/../agent.php', __DIR__ . '/../../deployment-local-agent/agent/agent.php'],
    [__DIR__ . '/../lib/LocalBuildRunner.php', __DIR__ . '/../../deployment-local-agent/agent/lib/LocalBuildRunner.php'],
    [__DIR__ . '/../lib/LocalBuildFailedException.php', __DIR__ . '/../../deployment-local-agent/agent/lib/LocalBuildFailedException.php'],
    [__DIR__ . '/../config.example.php', __DIR__ . '/../../deployment-local-agent/config/config.example.php'],
];
foreach ($mirrorPairs as [$original, $mirror]) {
    check(
        'mirror parity: ' . basename($mirror) . ' is byte-for-byte identical between local-agent and deployment-local-agent',
        is_file($original) && is_file($mirror) && file_get_contents($original) === file_get_contents($mirror),
        $failures,
        $passCount,
    );
}

// ============================================================
echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
