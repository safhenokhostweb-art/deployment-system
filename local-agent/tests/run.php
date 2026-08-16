<?php

declare(strict_types=1);

/**
 * Phase 5.2: dependency-free test harness for the Local Agent's own
 * lib/* code — same convention as the production Server Agent's
 * agent/tests/run.php (no Composer/PHPUnit in this directory; its whole
 * identity is "zero dependencies"). Builds real temp-directory git
 * fixtures and asserts on IgnoreRules/SafetyChecker output directly.
 *
 * Usage: php tests/run.php
 */

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/IgnoreRules.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/LogTail.php';
require __DIR__ . '/../lib/ShutdownSignal.php';
require __DIR__ . '/../lib/InvalidProjectCacheException.php';
require __DIR__ . '/../lib/ProjectConfigCache.php';
require __DIR__ . '/../lib/ProjectConfigResolver.php';
require __DIR__ . '/../lib/ProjectConfigSyncScheduler.php';
require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/JobMaxRuntimeExceededException.php';
require __DIR__ . '/../lib/WatchdogCooldown.php';
require __DIR__ . '/../lib/WatchdogState.php';
require __DIR__ . '/../lib/TaskListParser.php';

use LocalAgent\GitCommandException;
use LocalAgent\GitManager;
use LocalAgent\IgnoreRules;
use LocalAgent\InvalidProjectCacheException;
use LocalAgent\Logger;
use LocalAgent\LogTail;
use LocalAgent\ProjectConfigCache;
use LocalAgent\ProjectConfigResolver;
use LocalAgent\ProjectConfigSyncScheduler;
use LocalAgent\SafetyChecker;
use LocalAgent\ShutdownSignal;
use LocalAgent\TaskListParser;
use LocalAgent\WatchdogCooldown;
use LocalAgent\WatchdogState;

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

function makeRepo(): string
{
    $root = sys_get_temp_dir() . '/local-agent-test-' . bin2hex(random_bytes(6));
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
        } else {
            // git marks its object files read-only on Windows; chmod
            // before unlink so cleanup doesn't fail here.
            @chmod($path, 0666);
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function runGit(array $args, string $cwd): void
{
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($args, $descriptorSpec, $pipes, $cwd, null);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

// --- IgnoreRules::filter() against the spec's own 5-pattern example ---

$patterns = ['node_modules/', 'vendor/', 'storage/logs/', '.env', '*.log'];

$files = [
    'app/Http/Controllers/FooController.php',
    'node_modules/some-package/index.js',
    'vendor/autoload.php',
    'storage/logs/laravel.log',
    '.env',
    'debug.log',
    'resources/views/foo.blade.php',
];

$filtered = IgnoreRules::filter($files, $patterns);

check('keeps ordinary tracked files', in_array('app/Http/Controllers/FooController.php', $filtered, true), $failures, $passCount);
check('keeps another ordinary tracked file', in_array('resources/views/foo.blade.php', $filtered, true), $failures, $passCount);
check('filters out a node_modules/ directory match', !in_array('node_modules/some-package/index.js', $filtered, true), $failures, $passCount);
check('filters out a vendor/ directory match', !in_array('vendor/autoload.php', $filtered, true), $failures, $passCount);
check('filters out a nested storage/logs/ directory match', !in_array('storage/logs/laravel.log', $filtered, true), $failures, $passCount);
check('filters out an exact .env match', !in_array('.env', $filtered, true), $failures, $passCount);
check('filters out a *.log suffix glob match', !in_array('debug.log', $filtered, true), $failures, $passCount);
check(
    'filter() with no user patterns leaves ordinary files unchanged',
    IgnoreRules::filter(['app/Foo.php', 'resources/views/foo.blade.php'], []) === ['app/Foo.php', 'resources/views/foo.blade.php'],
    $failures,
    $passCount,
);
check(
    '.local-agent-ignore itself is always filtered out, even with no user patterns',
    !in_array('.local-agent-ignore', IgnoreRules::filter(['app/Foo.php', '.local-agent-ignore'], []), true),
    $failures,
    $passCount,
);

// --- Phase 5.4: MANDATORY_PATTERNS — sensitive files are filtered
// unconditionally, even with an empty (or unrelated) user pattern list.
// This is the hard "never commit .env/credentials/tokens" guarantee,
// not an opt-in one. ---

$sensitiveFiles = ['app/Foo.php', '.env', '.env.local', '.env.production', 'secrets.pem', 'id.key', 'credentials.json', 'archive.token'];
$sensitiveFiltered = IgnoreRules::filter($sensitiveFiles, []);

check('mandatory: keeps an ordinary tracked file', in_array('app/Foo.php', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters .env with no user patterns configured', !in_array('.env', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters .env.local via the .env.* prefix glob', !in_array('.env.local', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters .env.production via the .env.* prefix glob', !in_array('.env.production', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters *.pem private keys', !in_array('secrets.pem', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters *.key private keys', !in_array('id.key', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters credentials.json', !in_array('credentials.json', $sensitiveFiltered, true), $failures, $passCount);
check('mandatory: filters *.token files', !in_array('archive.token', $sensitiveFiltered, true), $failures, $passCount);
check(
    'mandatory: a renamed file ending up as .env is still filtered',
    !in_array('config/local.env -> .env', IgnoreRules::filter(['config/local.env -> .env'], []), true),
    $failures,
    $passCount,
);
check(
    'a rename is matched by its new (resulting) path',
    IgnoreRules::isIgnored('old/README.md -> storage/logs/moved.txt', $patterns),
    $failures,
    $passCount,
);

// --- IgnoreRules::loadPatterns() reads a real .local-agent-ignore file ---

$repo = makeRepo();
file_put_contents($repo . '/.local-agent-ignore', "# comment line, ignored\n\nnode_modules/\n*.log\n");
$loaded = IgnoreRules::loadPatterns($repo);
check('loadPatterns() skips blank lines and comments', $loaded === ['node_modules/', '*.log'], $failures, $passCount);
check('loadPatterns() on a repo with no ignore file returns an empty array', IgnoreRules::loadPatterns($repo . '-missing') === [], $failures, $passCount);
rrmdir($repo);

// --- SafetyChecker: branch mismatch, merge in progress, conflict markers ---

$git = new GitManager();
$safety = new SafetyChecker();

// Branch mismatch
$repo = makeRepo();
runGit(['git', 'init', '-q', '-b', 'main'], $repo);
runGit(['git', 'config', 'user.email', 'test@example.test'], $repo);
runGit(['git', 'config', 'user.name', 'Test'], $repo);
file_put_contents($repo . '/file.txt', 'hello');
runGit(['git', 'add', '.'], $repo);
runGit(['git', 'commit', '-q', '-m', 'initial'], $repo);

$threw = false;
try {
    $safety->assertSafeToPublish($git, $repo, 'production');
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'main') && str_contains($e->getMessage(), 'production');
}
check('assertSafeToPublish() throws on a branch mismatch', $threw, $failures, $passCount);

$threw = false;
try {
    $safety->assertSafeToPublish($git, $repo, 'main');
} catch (\RuntimeException $e) {
    $threw = true;
}
check('assertSafeToPublish() passes silently on a clean repo on the expected branch', !$threw, $failures, $passCount);

$reviewedHead = $git->lastCommitHash($repo);
$verifiedHead = $safety->assertSafeToPublishVerifiedHead($git, $repo, 'main', $reviewedHead);
check('no-source-change publish accepts the exact reviewed clean HEAD', $verifiedHead === $reviewedHead, $failures, $passCount);

file_put_contents($repo . '/uncommitted.txt', 'dirty');
$threw = false;
try {
    $safety->assertSafeToPublishVerifiedHead($git, $repo, 'main', $reviewedHead);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'no longer clean');
}
check('no-source-change publish rejects a working tree that became dirty after review', $threw, $failures, $passCount);
unlink($repo . '/uncommitted.txt');

file_put_contents($repo . '/file.txt', 'changed');
runGit(['git', 'add', '.'], $repo);
runGit(['git', 'commit', '-q', '-m', 'advance head'], $repo);
$threw = false;
try {
    $safety->assertSafeToPublishVerifiedHead($git, $repo, 'main', $reviewedHead);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'no longer matches');
}
check('no-source-change publish rejects a HEAD that changed after review', $threw, $failures, $passCount);

// Merge in progress (simulated via a fake MERGE_HEAD)
file_put_contents($repo . '/.git/MERGE_HEAD', str_repeat('a', 40) . "\n");
$threw = false;
try {
    $safety->assertSafeToPublish($git, $repo, 'main');
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'merge');
}
check('assertSafeToPublish() throws when a merge is in progress', $threw, $failures, $passCount);
unlink($repo . '/.git/MERGE_HEAD');

rrmdir($repo);

// Unresolved conflict markers (simulated via a real diverging merge)
$repo = makeRepo();
runGit(['git', 'init', '-q', '-b', 'main'], $repo);
runGit(['git', 'config', 'user.email', 'test@example.test'], $repo);
runGit(['git', 'config', 'user.name', 'Test'], $repo);
file_put_contents($repo . '/file.txt', "line one\n");
runGit(['git', 'add', '.'], $repo);
runGit(['git', 'commit', '-q', '-m', 'base'], $repo);
runGit(['git', 'checkout', '-q', '-b', 'feature'], $repo);
file_put_contents($repo . '/file.txt', "line one\nfeature change\n");
runGit(['git', 'add', '.'], $repo);
runGit(['git', 'commit', '-q', '-m', 'feature change'], $repo);
runGit(['git', 'checkout', '-q', 'main'], $repo);
file_put_contents($repo . '/file.txt', "line one\nmain change\n");
runGit(['git', 'add', '.'], $repo);
runGit(['git', 'commit', '-q', '-m', 'main change'], $repo);
runGit(['git', 'merge', '--no-edit', 'feature'], $repo); // expected to conflict

// A real conflicted merge also leaves MERGE_HEAD in place, which
// assertSafeToPublish() checks first (see the dedicated test above) —
// removing it here isolates the second guard clause, the conflict-marker
// scan itself, exactly as it would still find them mid-rebase or after
// an incomplete `merge --abort`.
unlink($repo . '/.git/MERGE_HEAD');

$threw = false;
try {
    $safety->assertSafeToPublish($git, $repo, 'main');
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'file.txt');
}
check('assertSafeToPublish() throws on an unresolved merge conflict', $threw, $failures, $passCount);

rrmdir($repo);

// --- Phase 5.5: the raw/allowed/blocked split agent.php's status_check
// branch computes (array_diff($rawFiles, $allowedFiles)) — the Hub-facing
// "Blocked Files" list depends on this being a clean partition. ---

$mixedRawFiles = ['app/Foo.php', '.env', 'resources/views/foo.blade.php', 'credentials.json'];
$mixedAllowed = IgnoreRules::filter($mixedRawFiles, []);
$mixedBlocked = array_values(array_diff($mixedRawFiles, $mixedAllowed));

check('split: allowed list keeps ordinary files', $mixedAllowed === ['app/Foo.php', 'resources/views/foo.blade.php'], $failures, $passCount);
check('split: blocked list contains exactly the sensitive files', $mixedBlocked === ['.env', 'credentials.json'], $failures, $passCount);
check('split: allowed + blocked accounts for every raw file exactly once', count($mixedAllowed) + count($mixedBlocked) === count($mixedRawFiles), $failures, $passCount);

// --- LogTail::tail() against a real Logger-written fixture log file ---

$logDir = makeRepo(); // just needs to be an empty temp dir, not a git repo
check('tail() on an empty log dir returns the placeholder', str_contains(LogTail::tail($logDir, 200), 'no log file yet'), $failures, $passCount);

$logger = new Logger($logDir);
for ($i = 1; $i <= 5; $i++) {
    $logger->info("line {$i}");
}

$full = LogTail::tail($logDir, 200);
check('tail() returns every line when maxLines exceeds the file length', count(explode("\n", $full)) === 5, $failures, $passCount);
check('tail() includes the first line when nothing is truncated', str_contains($full, 'line 1'), $failures, $passCount);
check('tail() includes the last line when nothing is truncated', str_contains($full, 'line 5'), $failures, $passCount);

$lastTwo = LogTail::tail($logDir, 2);
check('tail() with a smaller maxLines drops the earlier lines', !str_contains($lastTwo, 'line 3'), $failures, $passCount);
check('tail() with a smaller maxLines keeps the most recent line', str_contains($lastTwo, 'line 5'), $failures, $passCount);

rrmdir($logDir);

// --- ShutdownSignal::handle() — the pure logic behind the OS ctrl-handler
// callback (the actual OS registration can't be exercised without a real
// signal, but the callback body itself is a plain by-reference flag flip) ---

$logDirForShutdown = makeRepo();
$shutdownLogger = new Logger($logDirForShutdown);
$shuttingDown = false;
ShutdownSignal::handle($shutdownLogger, $shuttingDown, 'event', 0);
check('ShutdownSignal::handle() flips the by-reference flag to true', $shuttingDown === true, $failures, $passCount);
rrmdir($logDirForShutdown);

// --- ProjectConfigCache::fromApiResponse() — validates and reshapes the
// Hub's GET /api/local-agent/projects response into the project_key-keyed
// map the rest of the agent uses (matches
// LocalAgentProjectSyncController's response shape on the Hub side). ---

$validApiResponse = [
    ['project_key' => 'saas-platform', 'local_path' => 'C:\\laragon\\www\\saas-platform', 'remote_name' => 'origin', 'branch' => 'main'],
    ['project_key' => 'customer-site', 'local_path' => 'C:\\laragon\\www\\customer-site', 'remote_name' => 'origin', 'branch' => 'main'],
];
$normalized = ProjectConfigCache::fromApiResponse($validApiResponse);
check('fromApiResponse() keys the map by project_key', array_keys($normalized) === ['saas-platform', 'customer-site'], $failures, $passCount);
check('fromApiResponse() preserves local_path', $normalized['saas-platform']['local_path'] === 'C:\\laragon\\www\\saas-platform', $failures, $passCount);
check('fromApiResponse() preserves branch', $normalized['customer-site']['branch'] === 'main', $failures, $passCount);
check('fromApiResponse() on an empty list is valid (zero assigned projects)', ProjectConfigCache::fromApiResponse([]) === [], $failures, $passCount);

$threw = false;
try {
    ProjectConfigCache::fromApiResponse([['local_path' => 'C:\\x', 'remote_name' => 'origin', 'branch' => 'main']]);
} catch (\InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'project_key');
}
check('fromApiResponse() throws when project_key is missing', $threw, $failures, $passCount);

$threw = false;
try {
    ProjectConfigCache::fromApiResponse([['project_key' => 'saas-platform', 'local_path' => '', 'remote_name' => 'origin', 'branch' => 'main']]);
} catch (\InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'saas-platform');
}
check('fromApiResponse() throws when local_path is empty', $threw, $failures, $passCount);

$threw = false;
try {
    ProjectConfigCache::fromApiResponse([['project_key' => 'saas-platform', 'local_path' => 'C:\\x', 'remote_name' => 'origin']]);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('fromApiResponse() throws when branch is missing', $threw, $failures, $passCount);

// --- ProjectConfigCache::save()/load() — round trip, atomic replacement,
// and the "never contains secrets" guarantee (Phase 5 constraint). ---

$cacheDir = makeRepo(); // just needs to be an empty temp dir
$cachePath = $cacheDir . '/projects.json';
$cache = new ProjectConfigCache($cachePath);

check('load() on a cache that has never been written returns null (not an error)', $cache->load() === null, $failures, $passCount);

$projectsV1 = ['saas-platform' => ['local_path' => 'C:\\laragon\\www\\saas-platform', 'remote_name' => 'origin', 'branch' => 'main']];
$cache->save($projectsV1);
check('save() creates the cache file', is_file($cachePath), $failures, $passCount);
check('load() round-trips exactly what was saved', $cache->load() === $projectsV1, $failures, $passCount);

$rawSaved = file_get_contents($cachePath);
check('cache file never contains an agent_token key', !str_contains($rawSaved, 'agent_token'), $failures, $passCount);
check('cache file never contains a signing_secret key', !str_contains($rawSaved, 'signing_secret'), $failures, $passCount);
check('cache file never contains the word CHANGE_ME (a placeholder secret value)', !str_contains($rawSaved, 'CHANGE_ME'), $failures, $passCount);

// Atomic replacement: a second save() fully replaces the first, and never
// leaves a stray .tmp-* file behind in the cache directory (rename()
// either fully lands or the temp file is cleaned up on failure).
$projectsV2 = ['customer-site' => ['local_path' => 'C:\\laragon\\www\\customer-site', 'remote_name' => 'origin', 'branch' => 'develop']];
$cache->save($projectsV2);
check('save() atomically replaces the previous cache content', $cache->load() === $projectsV2, $failures, $passCount);

$leftoverTmpFiles = array_values(array_filter(scandir($cacheDir), fn ($entry) => str_contains($entry, '.tmp-')));
check('no leftover .tmp-* files remain in the cache directory after save()', $leftoverTmpFiles === [], $failures, $passCount);

// Corrupt cache: exists but is not valid JSON — must be reported as an
// explicit "invalid cache" condition (an exception), never conflated
// with "no cache file yet" (which load() reports as null).
file_put_contents($cachePath, '{not valid json');
$threw = false;
try {
    $cache->load();
} catch (InvalidProjectCacheException $e) {
    $threw = true;
}
check('load() throws InvalidProjectCacheException on corrupt JSON rather than returning null', $threw, $failures, $passCount);

// Corrupt cache: valid JSON, but missing the required "projects" key.
file_put_contents($cachePath, json_encode(['version' => 1]));
$threw = false;
try {
    $cache->load();
} catch (InvalidProjectCacheException $e) {
    $threw = true;
}
check('load() throws InvalidProjectCacheException when "projects" is missing', $threw, $failures, $passCount);

// Corrupt cache: a project entry with an empty local_path.
file_put_contents($cachePath, json_encode(['version' => 1, 'projects' => ['x' => ['local_path' => '', 'remote_name' => 'origin', 'branch' => 'main']]]));
$threw = false;
try {
    $cache->load();
} catch (InvalidProjectCacheException $e) {
    $threw = true;
}
check('load() throws InvalidProjectCacheException on a structurally invalid cached project', $threw, $failures, $passCount);

rrmdir($cacheDir);

// --- ProjectConfigResolver::resolve() — the Client-independent fallback
// half of sync: priority is (2) last good cache, then (3) legacy
// config.php `projects` array. Exercised without any network dependency,
// matching this test harness's zero-dependency convention. ---

$resolverCacheDir = makeRepo();
$resolverLogDir = makeRepo();
$resolverCache = new ProjectConfigCache($resolverCacheDir . '/projects.json');
$resolverLogger = new Logger($resolverLogDir);
$resolver = new ProjectConfigResolver($resolverCache, $resolverLogger);

$legacy = ['deployment-system' => ['local_path' => 'C:\\laragon\\www\\deployment-system', 'remote_name' => 'origin', 'branch' => 'main']];

// No cache yet, no legacy projects: nothing to fall back to.
check('resolve() returns [] when neither cache nor legacy config exist', $resolver->resolve([]) === [], $failures, $passCount);

// No cache yet, but legacy projects configured: falls back to legacy.
check('resolve() falls back to the legacy config.php projects array when no cache exists', $resolver->resolve($legacy) === $legacy, $failures, $passCount);

// A good cache exists: cache wins over legacy, even when both are present.
$cachedProjects = ['saas-platform' => ['local_path' => 'C:\\laragon\\www\\saas-platform', 'remote_name' => 'origin', 'branch' => 'main']];
$resolverCache->save($cachedProjects);
check('resolve() prefers the cache over the legacy config.php array when both exist', $resolver->resolve($legacy) === $cachedProjects, $failures, $passCount);

// A corrupt cache falls through to legacy, exactly like no cache at all.
file_put_contents($resolverCacheDir . '/projects.json', '{not valid json');
check('resolve() falls back past a corrupt cache to the legacy config.php array', $resolver->resolve($legacy) === $legacy, $failures, $passCount);

rrmdir($resolverCacheDir);
rrmdir($resolverLogDir);

// --- ProjectConfigSyncScheduler::shouldSync() — the TTL/version/reload
// gate that replaced "download and validate the full project list on
// every single poll cycle." Pure logic, no Client/network dependency. ---

$scheduler = new ProjectConfigSyncScheduler(300); // 5-minute TTL

check(
    'shouldSync() is true on the very first cycle (lastSyncedAt is null — startup always syncs)',
    $scheduler->shouldSync(null, null, null, false, 1000) === true,
    $failures,
    $passCount,
);

check(
    'shouldSync() is false when nothing has changed and the TTL has not elapsed',
    $scheduler->shouldSync(1000, 'v1', 'v1', false, 1100) === false, // 100s < 300s TTL
    $failures,
    $passCount,
);

check(
    'shouldSync() is true once the TTL has fully elapsed',
    $scheduler->shouldSync(1000, 'v1', 'v1', false, 1301) === true, // 301s >= 300s TTL
    $failures,
    $passCount,
);

check(
    'shouldSync() is false right at the boundary just under the TTL',
    $scheduler->shouldSync(1000, 'v1', 'v1', false, 1299) === false,
    $failures,
    $passCount,
);

check(
    'shouldSync() is true when the heartbeat reports a config_version different from the last known one, even with TTL untouched',
    $scheduler->shouldSync(1000, 'v1', 'v2', false, 1010) === true,
    $failures,
    $passCount,
);

check(
    'shouldSync() is false when the heartbeat omits config_version (null) — never treated as a change',
    $scheduler->shouldSync(1000, 'v1', null, false, 1010) === false,
    $failures,
    $passCount,
);

check(
    'shouldSync() is false before ever having synchronized successfully (knownConfigVersion null) even if the heartbeat reports one',
    $scheduler->shouldSync(1000, null, 'v2', false, 1010) === false,
    $failures,
    $passCount,
);

check(
    'shouldSync() is true when an explicit reload was requested, overriding everything else',
    $scheduler->shouldSync(1000, 'v1', 'v1', true, 1010) === true,
    $failures,
    $passCount,
);

// --- GitManager::run() timeout, tree-termination, output cap, and env
// hardening (root-cause fix for the Local Agent hang) — exercised against
// a real slow child process (PHP_BINARY itself, sleeping), not mocked. ---

$slowCommand = [PHP_BINARY, '-r', 'sleep(10);'];
$gitForTimeout = new GitManager();
$timeoutStart = microtime(true);
$timeoutResult = $gitForTimeout->run($slowCommand, sys_get_temp_dir(), 1);
$timeoutElapsed = microtime(true) - $timeoutStart;

check('run() reports timedOut=true once a command exceeds its timeout', $timeoutResult['timedOut'] === true, $failures, $passCount);
check('run() reports exit code 124 on timeout', $timeoutResult['exitCode'] === 124, $failures, $passCount);
// Regression guard for the actual root-cause bug found while building
// this fix: the previous pipe-based implementation's timeout-deadline
// check was itself blocked behind a stream_get_contents() call that
// silently never returns early on Windows (stream_set_blocking()
// silently fails there), so a genuinely silent hung command was NEVER
// actually caught by the "existing" timeout — this 10s sleep against a 1s
// timeout took over 11s end to end before this fix (proc_get_status()
// was unreachable in a timely way). A margin well under that, but above
// normal process-teardown jitter, proves the deadline check itself now
// fires close to on time.
check('run() actually terminates close to its configured timeout, never waiting out the full command', $timeoutElapsed < 4.0, $failures, $passCount);
check('run() never crashes/throws on timeout — the caller (agent.php\'s main loop) can simply continue', true, $failures, $passCount);

// onTick: fired periodically WHILE a long command is still running — the
// mechanism that renews a PublishJob's Hub-side lease during a slow git
// command instead of only before/after it.
$tickCount = 0;
$gitForTick = new GitManager(jobHeartbeatIntervalSeconds: 1);
$gitForTick->run([PHP_BINARY, '-r', 'sleep(3);'], sys_get_temp_dir(), 5, function () use (&$tickCount): void {
    $tickCount++;
});
check('run() invokes $onTick at least once during a multi-second command', $tickCount >= 1, $failures, $passCount);

$tickCountWhenFast = 0;
$gitForFastTick = new GitManager(jobHeartbeatIntervalSeconds: 5);
$gitForFastTick->run([PHP_BINARY, '-r', 'echo "fast";'], sys_get_temp_dir(), 5, function () use (&$tickCountWhenFast): void {
    $tickCountWhenFast++;
});
check('run() never invokes $onTick at all for a command that finishes immediately', $tickCountWhenFast === 0, $failures, $passCount);

// Output cap: an unexpectedly huge stdout must never grow $output
// unbounded (memory exhaustion) — capped and marked, not silently
// dropped.
$hugeOutputCommand = [PHP_BINARY, '-r', 'echo str_repeat("x", 5300000);'];
$gitForCap = new GitManager();
$cappedResult = $gitForCap->run($hugeOutputCommand, sys_get_temp_dir(), 15);
check('run() caps runaway output instead of buffering it unbounded', strlen($cappedResult['output']) < 5300000, $failures, $passCount);
check('run() marks output as truncated when the cap is hit', str_contains($cappedResult['output'], 'truncated'), $failures, $passCount);

// GIT_TERMINAL_PROMPT=0 / GCM_INTERACTIVE=never: every command run
// through GitManager must never be able to block on an interactive
// credential/host-key prompt.
$envCommand = [PHP_BINARY, '-r', 'echo getenv("GIT_TERMINAL_PROMPT") . "|" . getenv("GCM_INTERACTIVE");'];
$gitForEnv = new GitManager();
$envResult = $gitForEnv->run($envCommand, sys_get_temp_dir(), 10);
check('run() sets GIT_TERMINAL_PROMPT=0 in the child environment', str_contains($envResult['output'], '0|'), $failures, $passCount);
check('run() sets GCM_INTERACTIVE=never in the child environment', str_contains($envResult['output'], '|never'), $failures, $passCount);

// timeoutFor(): the per-purpose configurable timeout CommitManager/
// PushManager query instead of hardcoding their own values.
$gitWithTimeouts = new GitManager(['add' => 42]);
check('timeoutFor() returns a configured override when present', $gitWithTimeouts->timeoutFor('add') === 42, $failures, $passCount);
check('timeoutFor() falls back to its own default for an unconfigured purpose', $gitWithTimeouts->timeoutFor('push') === 180, $failures, $passCount);
check('timeoutFor() falls back to 30 for a completely unknown purpose', $gitWithTimeouts->timeoutFor('not-a-real-purpose') === 30, $failures, $passCount);

// --- GitCommandException — carries whether the underlying failure was a
// timeout, so agent.php can report failure_code=process_timeout to the
// Hub distinctly from an ordinary git failure. ---

$timeoutException = new GitCommandException('git push timed out', true);
$failureException = new GitCommandException('git push failed', false);
check('GitCommandException exposes timedOut=true when constructed as a timeout', $timeoutException->timedOut === true, $failures, $passCount);
check('GitCommandException exposes timedOut=false when constructed as an ordinary failure', $failureException->timedOut === false, $failures, $passCount);

// --- WatchdogCooldown — restart-loop protection, pure logic. ---

$cooldown = new WatchdogCooldown(300);
check('WatchdogCooldown::isActive() is false when no prior restart is recorded', $cooldown->isActive(null, time()) === false, $failures, $passCount);
check('WatchdogCooldown::isActive() is true immediately after a restart', $cooldown->isActive(time(), time()) === true, $failures, $passCount);
check('WatchdogCooldown::isActive() is false once the cooldown has fully elapsed', $cooldown->isActive(time() - 301, time()) === false, $failures, $passCount);
check('WatchdogCooldown::isActive() is true just under the cooldown boundary', $cooldown->isActive(time() - 299, time()) === true, $failures, $passCount);
check('WatchdogCooldown::remainingSeconds() is 0 when no prior restart is recorded', $cooldown->remainingSeconds(null, time()) === 0, $failures, $passCount);
check('WatchdogCooldown::remainingSeconds() counts down correctly', $cooldown->remainingSeconds(time() - 100, time()) === 200, $failures, $passCount);
check('WatchdogCooldown::remainingSeconds() never goes negative once elapsed', $cooldown->remainingSeconds(time() - 400, time()) === 0, $failures, $passCount);

// --- WatchdogState — atomic persistence of the last restart timestamp
// across separate watchdog invocations (each a fresh PHP process). ---

$watchdogStateDir = makeRepo(); // just needs to be an empty temp dir
$watchdogStatePath = $watchdogStateDir . '/watchdog-state.json';
$watchdogState = new WatchdogState($watchdogStatePath);

check('WatchdogState::lastRestartAt() is null before anything is ever recorded', $watchdogState->lastRestartAt() === null, $failures, $passCount);

$watchdogState->recordRestart(1700000000);
check('WatchdogState::recordRestart() persists a timestamp readable by a fresh instance', (new WatchdogState($watchdogStatePath))->lastRestartAt() === 1700000000, $failures, $passCount);

$watchdogState->recordRestart(1700000500);
check('WatchdogState::recordRestart() overwrites the previous value atomically', $watchdogState->lastRestartAt() === 1700000500, $failures, $passCount);
$leftoverWatchdogTmpFiles = array_values(array_filter(scandir($watchdogStateDir), fn ($entry) => str_contains($entry, '.tmp-')));
check('no leftover .tmp-* files remain after WatchdogState::recordRestart()', $leftoverWatchdogTmpFiles === [], $failures, $passCount);

file_put_contents($watchdogStatePath, '{not valid json');
check('WatchdogState::lastRestartAt() returns null (not a throw) for a corrupt state file', (new WatchdogState($watchdogStatePath))->lastRestartAt() === null, $failures, $passCount);

rrmdir($watchdogStateDir);

// --- TaskListParser — pure parsing of `tasklist /FO CSV /NH` output, no
// OS/process dependency. The watchdog uses this to confirm a PID from
// agent.pid still actually belongs to a php.exe process before ever
// tree-killing it. ---

check(
    'isImageName() matches a real CSV tasklist line for the expected image',
    TaskListParser::isImageName('"php.exe","12345","Console","1","12,345 K"', 'php.exe'),
    $failures,
    $passCount,
);
check(
    'isImageName() is case-insensitive on the image name',
    TaskListParser::isImageName('"PHP.EXE","12345","Console","1","12,345 K"', 'php.exe'),
    $failures,
    $passCount,
);
check(
    'isImageName() is false for a different image name at that PID (a stale/reused PID must never be killed)',
    !TaskListParser::isImageName('"notepad.exe","12345","Console","1","6,000 K"', 'php.exe'),
    $failures,
    $passCount,
);
check(
    'isImageName() is false for tasklist\'s own "no tasks found" message',
    !TaskListParser::isImageName('INFO: No tasks are running which match the specified criteria.', 'php.exe'),
    $failures,
    $passCount,
);
check('isImageName() is false for empty output', !TaskListParser::isImageName('', 'php.exe'), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
