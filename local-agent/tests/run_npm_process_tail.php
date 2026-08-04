<?php

declare(strict_types=1);

/**
 * Real bug audited and fixed: `npm ci`/`npm run build` can finish ALL of
 * their user-visible work (dependency install, postinstall scripts) and
 * still be a genuinely live OS process for a long tail afterward — an
 * instrumented, real `esbuild` install (real registry fetch,
 * `proc_open()`/`proc_get_status()` polled every 100-200ms, a live
 * `Win32_Process` tree snapshot mid-run) proved this is real Node.js/npm
 * process-lifecycle behavior (registry-client keep-alive sockets +
 * npm's own background update-notifier check both delay the event loop
 * from emptying), NOT a proc_open/proc_get_status bug — LocalBuildRunner
 * was killing an npm that had already succeeded once its (fixed, 600s)
 * timeout elapsed.
 *
 * This suite proves the fix: `--no-audit`/`--no-fund`/`--no-update-notifier`
 * are genuinely present on every npm invocation; a completed-but-lingering
 * npm gets a bounded grace period instead of an immediate kill; a
 * GENUINELY hung/failed npm is still killed exactly as before (the grace
 * mechanism never masks a real problem); and the three required log
 * events are actually emitted. Same philosophy as the rest of this test
 * suite: no mocking, real child processes (fake npm/composer scripts are
 * REAL PHP processes actually spawned via proc_open, not mocked
 * function calls), real assertions.
 *
 * Usage: php tests/run_npm_process_tail.php
 */

require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/LocalBuildRunner.php';

use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;

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

function makeWorkspace(string $label = 'npm-tail-test'): string
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

function readLogFile(string $logDir): string
{
    $content = '';
    foreach (glob("{$logDir}/*.log") ?: [] as $file) {
        $content .= (string) file_get_contents($file);
    }

    return $content;
}

// ============================================================
// 1. GitManager::run()'s grace-period mechanism, directly — the
//    low-level primitive LocalBuildRunner's fix is built on.
// ============================================================
$git = new GitManager();

// 1a. Completion indicator present, process exits naturally within grace
//     -> succeeds normally (timedOut=false), no force-kill.
$logsA = [];
$resultA = $git->run(
    [PHP_BINARY, '-r', 'echo "DONE\n"; sleep(2);'],
    sys_get_temp_dir(),
    1,
    null,
    10,
    static fn (string $out): bool => str_contains($out, 'DONE'),
    function (string $msg) use (&$logsA): void { $logsA[] = $msg; },
);
check('GitManager: a lingering-but-complete process exits naturally within grace (timedOut=false)', $resultA['timedOut'] === false, $failures, $passCount);
check('GitManager: exit code 0 is preserved through the grace window', $resultA['exitCode'] === 0, $failures, $passCount);
check('GitManager: logs "entering...graceful wait" when grace is entered', (bool) array_filter($logsA, static fn ($m) => str_contains($m, 'graceful wait')), $failures, $passCount);
check('GitManager: logs the natural-exit-during-grace event', (bool) array_filter($logsA, static fn ($m) => str_contains($m, 'exited naturally during the graceful wait')), $failures, $passCount);

// 1b. Completion indicator present, but the process outlives even the
//     grace period -> eventually force-killed (timedOut=true), bounded.
$logsB = [];
$startB = microtime(true);
$resultB = $git->run(
    [PHP_BINARY, '-r', 'echo "DONE\n"; sleep(20);'],
    sys_get_temp_dir(),
    1,
    null,
    2,
    static fn (string $out): bool => str_contains($out, 'DONE'),
    function (string $msg) use (&$logsB): void { $logsB[] = $msg; },
);
$elapsedB = microtime(true) - $startB;
check('GitManager: a lingering process that outlives the grace period is force-killed (timedOut=true)', $resultB['timedOut'] === true, $failures, $passCount);
check('GitManager: exit code 124 (timeout signature) after the grace period elapses', $resultB['exitCode'] === 124, $failures, $passCount);
check('GitManager: the grace period genuinely bounds the wait (finished well under the 20s sleep, not waiting it out)', $elapsedB < 8.0, $failures, $passCount);
check('GitManager: logs the specific force-kill-after-grace reason', (bool) array_filter($logsB, static fn ($m) => str_contains($m, 'grace period elapsed without the process exiting')), $failures, $passCount);

// 1c. NO completion indicator (predicate returns false) -> immediate
//     kill at the original timeout, exactly as before — the grace
//     mechanism must never mask a genuine hang.
$logsC = [];
$startC = microtime(true);
$resultC = $git->run(
    [PHP_BINARY, '-r', 'sleep(20);'],
    sys_get_temp_dir(),
    1,
    null,
    10,
    static fn (string $out): bool => str_contains($out, 'DONE'),
    function (string $msg) use (&$logsC): void { $logsC[] = $msg; },
);
$elapsedC = microtime(true) - $startC;
check('GitManager: a genuinely hung process (no completion indicator) is still killed at the original timeout', $resultC['timedOut'] === true, $failures, $passCount);
check('GitManager: no grace period was granted (killed promptly, not after the full 10s grace budget)', $elapsedC < 5.0, $failures, $passCount);
check('GitManager: no "entering graceful wait" was logged for a genuine hang', array_filter($logsC, static fn ($m) => str_contains($m, 'graceful wait')) === [], $failures, $passCount);
check('GitManager: logs the "no confirmed completion" reason instead', (bool) array_filter($logsC, static fn ($m) => str_contains($m, 'no confirmed completion')), $failures, $passCount);

// 1d. Backward compatibility: no grace params passed at all (the exact
//     shape every pre-existing call site — git, Composer — uses) behaves
//     byte-for-byte as before this fix.
$startD = microtime(true);
$resultD = $git->run([PHP_BINARY, '-r', 'echo "DONE\n"; sleep(5);'], sys_get_temp_dir(), 1);
$elapsedD = microtime(true) - $startD;
check('GitManager: omitting the new params entirely preserves the original immediate-kill behavior', $resultD['timedOut'] === true && $resultD['exitCode'] === 124, $failures, $passCount);
check('GitManager: ...and does so promptly, not waiting any grace budget', $elapsedD < 5.0, $failures, $passCount);

// 1e. A throwing $isLikelyComplete predicate must not crash the loop —
//     falls back to the safe, original immediate-kill behavior.
$resultE = $git->run(
    [PHP_BINARY, '-r', 'echo "DONE\n"; sleep(5);'],
    sys_get_temp_dir(),
    1,
    null,
    10,
    static function (string $out): bool { throw new \RuntimeException('predicate exploded'); },
);
check('GitManager: a throwing completion predicate is swallowed, falling back to immediate kill', $resultE['timedOut'] === true, $failures, $passCount);

// ============================================================
// 2. LocalBuildRunner: the npm flags are genuinely present on both
//    `npm ci` and `npm run build` invocations.
// ============================================================
function writeArgvCapturingNpmScript(string $path): void
{
    file_put_contents($path, <<<'PHP'
        <?php
        $args = array_slice($argv, 1);
        $n = count(glob(getcwd() . '/npm-invocation-*.json')) + 1;
        file_put_contents(getcwd() . '/npm-invocation-' . $n . '.json', json_encode($args));
        $isBuild = ($args[0] ?? null) === 'run' && ($args[1] ?? null) === 'build';
        if ($isBuild) {
            @mkdir(getcwd() . '/public/build', 0777, true);
            file_put_contents(getcwd() . '/public/build/manifest.json', '{}');
        }
        fwrite(STDOUT, "added 1 package in 1s\n");
        exit(0);
        PHP);
}

$workspace2 = makeWorkspace();
mkdir("{$workspace2}/repo", 0777, true);
$repo2 = "{$workspace2}/repo";
file_put_contents("{$repo2}/package.json", json_encode(['name' => 'flagcheck', 'private' => true, 'scripts' => ['build' => 'noop']]));
$fakeNpm2 = "{$workspace2}/fake-npm.php";
writeArgvCapturingNpmScript($fakeNpm2);

$runner2 = new LocalBuildRunner(new GitManager(), npmCommand: [PHP_BINARY, $fakeNpm2]);
$result2 = $runner2->run($repo2);

check('LocalBuildRunner: npm ci and npm run build both ran via the fake script', $result2['ranNpmInstall'] === true && $result2['ranNpmBuild'] === true, $failures, $passCount);

$invocation1 = json_decode((string) @file_get_contents("{$repo2}/npm-invocation-1.json"), true);
$invocation2 = json_decode((string) @file_get_contents("{$repo2}/npm-invocation-2.json"), true);
check('LocalBuildRunner: npm ci received --no-audit --no-fund --no-update-notifier', is_array($invocation1) && array_intersect(['--no-audit', '--no-fund', '--no-update-notifier'], $invocation1) === ['--no-audit', '--no-fund', '--no-update-notifier'], $failures, $passCount);
check('LocalBuildRunner: npm run build ALSO received --no-audit --no-fund --no-update-notifier', is_array($invocation2) && array_intersect(['--no-audit', '--no-fund', '--no-update-notifier'], $invocation2) === ['--no-audit', '--no-fund', '--no-update-notifier'], $failures, $passCount);
rrmdir($workspace2);

// ============================================================
// 3. LocalBuildRunner::npmLikelySucceeded() — the completion-detection
//    heuristic — against REAL npm output patterns captured during this
//    fix's own audit (an actual `npm ci`/`esbuild install`), not
//    invented strings.
// ============================================================
$npmLikelySucceeded = new ReflectionMethod(LocalBuildRunner::class, 'npmLikelySucceeded');
$npmLikelySucceeded->setAccessible(true);

$realCiSuccessOutputs = [
    "\nadded 2 packages in 22s\n",
    "up to date in 13s\n",
    "\n> pkg@1.0.0 postinstall\n> node postinstall.js\n\npostinstall finished at 1785835310498\n\nup to date, audited 1 package in 5s\n\nfound 0 vulnerabilities\n",
];
foreach ($realCiSuccessOutputs as $i => $output) {
    check("npmLikelySucceeded: recognizes real npm ci completion output #{$i}", $npmLikelySucceeded->invoke(null, $output, true) === true, $failures, $passCount);
}

check('npmLikelySucceeded: empty output is never treated as complete (install)', $npmLikelySucceeded->invoke(null, '', true) === false, $failures, $passCount);
check('npmLikelySucceeded: empty output is never treated as complete (run build)', $npmLikelySucceeded->invoke(null, '', false) === false, $failures, $passCount);
check('npmLikelySucceeded: a real npm error always disqualifies, even alongside success-shaped text', $npmLikelySucceeded->invoke(null, "added 2 packages in 1s\nnpm error code E404\n", true) === false, $failures, $passCount);
check('npmLikelySucceeded: npm ERR! (legacy casing) also disqualifies', $npmLikelySucceeded->invoke(null, "npm ERR! network timeout\n", true) === false, $failures, $passCount);
check('npmLikelySucceeded: run-build accepts any non-empty, error-free output as weaker-but-real evidence', $npmLikelySucceeded->invoke(null, "vite v5.0.0 building for production...\n✓ built in 820ms\n", false) === true, $failures, $passCount);
check('npmLikelySucceeded: install-mode output with no recognizable summary line is NOT treated as complete', $npmLikelySucceeded->invoke(null, "some unrelated chatter\n", true) === false, $failures, $passCount);

// ============================================================
// 4. THE actual scenario, end to end through LocalBuildRunner: a fake
//    npm that prints a real completion line, then keeps the process
//    alive for a tail — proving the fix works at the point it matters
//    (a genuine LocalBuildRunner::run() call), not just at the
//    GitManager primitive level.
// ============================================================
function writeTailingNpmScript(string $path, int $sleepAfterCompletion, bool $emitCompletionLine): void
{
    $emit = $emitCompletionLine ? 'fwrite(STDOUT, "added 1 package in 1s\n");' : '';
    file_put_contents($path, <<<PHP
        <?php
        {$emit}
        fflush(STDOUT);
        sleep({$sleepAfterCompletion});
        exit(0);
        PHP);
}

// 4a. Short tail (3s) that fits inside the configured grace period (10s)
//     — LocalBuildRunner::run() must succeed (no exception), not treat
//     this as a failed/timed-out install.
$workspace4a = makeWorkspace();
mkdir("{$workspace4a}/repo", 0777, true);
$repo4a = "{$workspace4a}/repo";
file_put_contents("{$repo4a}/package.json", json_encode(['name' => 'shorttail', 'private' => true]));
$fakeNpm4a = "{$workspace4a}/fake-npm.php";
writeTailingNpmScript($fakeNpm4a, sleepAfterCompletion: 3, emitCompletionLine: true);

$logDir4a = makeWorkspace('npm-tail-log');
$logger4a = new Logger($logDir4a);
$runner4a = new LocalBuildRunner(new GitManager(), npmCommand: [PHP_BINARY, $fakeNpm4a], npmInstallTimeoutSeconds: 1, logger: $logger4a, npmGracePeriodSeconds: 10);

$threw4a = null;
try {
    $result4a = $runner4a->run($repo4a);
} catch (\Throwable $e) {
    $threw4a = $e;
}
check('THE FIX: npm that finishes its real work but lingers past the timeout no longer fails the build', $threw4a === null, $failures, $passCount);
check('THE FIX: ranNpmInstall is true — the install is correctly recognized as successful', $threw4a === null && ($result4a['ranNpmInstall'] ?? false) === true, $failures, $passCount);

$logContent4a = readLogFile($logDir4a);
check('THE FIX: log shows the process was still alive after npm work completed', str_contains($logContent4a, 'graceful wait'), $failures, $passCount);
check('THE FIX: log shows the natural exit during the graceful wait (no force-kill needed)', str_contains($logContent4a, 'exited naturally during the graceful wait'), $failures, $passCount);
rrmdir($workspace4a);
rrmdir($logDir4a);

// 4b. Long tail (20s) that outlives even the grace period (2s) — must
//     eventually still fail (bounded — proves the grace period doesn't
//     become an unbounded wait), reported as local_npm_install_failed.
$workspace4b = makeWorkspace();
mkdir("{$workspace4b}/repo", 0777, true);
$repo4b = "{$workspace4b}/repo";
file_put_contents("{$repo4b}/package.json", json_encode(['name' => 'longtail', 'private' => true]));
$fakeNpm4b = "{$workspace4b}/fake-npm.php";
writeTailingNpmScript($fakeNpm4b, sleepAfterCompletion: 20, emitCompletionLine: true);

$logDir4b = makeWorkspace('npm-tail-log');
$logger4b = new Logger($logDir4b);
$runner4b = new LocalBuildRunner(new GitManager(), npmCommand: [PHP_BINARY, $fakeNpm4b], npmInstallTimeoutSeconds: 1, logger: $logger4b, npmGracePeriodSeconds: 2);

$startedAt4b = microtime(true);
$caught4b = null;
try {
    $runner4b->run($repo4b);
} catch (LocalBuildFailedException $e) {
    $caught4b = $e;
}
$elapsed4b = microtime(true) - $startedAt4b;
check('a build whose process NEVER exits (even after grace) is still eventually reported as failed', $caught4b !== null && $caught4b->failureCode === 'local_npm_install_failed', $failures, $passCount);
check('...and the grace period genuinely bounds this (well under the 20s sleep, not waiting it out)', $elapsed4b < 10.0, $failures, $passCount);

$logContent4b = readLogFile($logDir4b);
check('THE FIX: log shows the force-kill reason when even the grace period elapses', str_contains($logContent4b, 'grace period elapsed without the process exiting'), $failures, $passCount);
rrmdir($workspace4b);
rrmdir($logDir4b);

// 4c. A GENUINELY hung npm (no completion output at all) must still be
//     killed at the original, short timeout — proving the fix never
//     masks a real hang/failure behind a generous grace period.
$workspace4c = makeWorkspace();
mkdir("{$workspace4c}/repo", 0777, true);
$repo4c = "{$workspace4c}/repo";
file_put_contents("{$repo4c}/package.json", json_encode(['name' => 'genuinehang', 'private' => true]));
$fakeNpm4c = "{$workspace4c}/fake-npm.php";
writeTailingNpmScript($fakeNpm4c, sleepAfterCompletion: 20, emitCompletionLine: false);

$logDir4c = makeWorkspace('npm-tail-log');
$logger4c = new Logger($logDir4c);
// A generous grace period (10s) that must NEVER be granted here, since
// there is no completion indicator to justify it.
$runner4c = new LocalBuildRunner(new GitManager(), npmCommand: [PHP_BINARY, $fakeNpm4c], npmInstallTimeoutSeconds: 1, logger: $logger4c, npmGracePeriodSeconds: 10);

$startedAt4c = microtime(true);
$caught4c = null;
try {
    $runner4c->run($repo4c);
} catch (LocalBuildFailedException $e) {
    $caught4c = $e;
}
$elapsed4c = microtime(true) - $startedAt4c;
check('a genuinely hung npm (no output at all) is still reported as failed', $caught4c !== null && $caught4c->failureCode === 'local_npm_install_failed', $failures, $passCount);
check('...and killed promptly at the original timeout, WITHOUT consuming the 10s grace budget', $elapsed4c < 6.0, $failures, $passCount);

$logContent4c = readLogFile($logDir4c);
check('no graceful-wait log line for a genuine hang — the grace mechanism was correctly never engaged', !str_contains($logContent4c, 'graceful wait'), $failures, $passCount);
rrmdir($workspace4c);
rrmdir($logDir4c);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
