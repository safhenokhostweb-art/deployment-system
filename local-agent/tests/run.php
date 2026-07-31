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

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
