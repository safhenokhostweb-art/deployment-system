<?php

declare(strict_types=1);

/**
 * Dependency-free integration test for ArtifactBuilder/ArtifactUploader
 * (the customer-site direct-GitHub-dependency removal work) — same
 * philosophy as tests/run.php: no mocking framework, real fixtures, real
 * assertions. Genuinely runs `git archive` against a real temporary
 * repository and verifies the resulting zip's contents with PHP's own
 * ZipArchive, then exercises the full two-phase upload flow against a
 * real `php -S` fake Hub process.
 *
 * Usage: php tests/run_artifact.php
 */

require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';
require __DIR__ . '/../lib/ArtifactUploader.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactUploader;
use LocalAgent\Client;
use LocalAgent\GitManager;
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

function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/local-agent-artifact-test-' . bin2hex(random_bytes(6));
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
    return $git->run(array_merge(['git'], $argv), $cwd, 30);
}

/**
 * A real git repo with tracked source files, a gitignored .env, and a
 * gitignored node_modules/ directory — proving `git archive` excludes
 * exactly what's untracked, with zero bespoke exclusion logic of this
 * project's own.
 */
function buildFixtureRepo(GitManager $git, string $workspace): array
{
    $repo = $workspace . '/repo';
    mkdir($repo, 0777, true);

    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Artifact Test'], $repo);

    file_put_contents("{$repo}/.gitignore", ".env\nnode_modules/\nstorage/logs/\nvendor/\n");
    mkdir("{$repo}/app", 0777, true);
    file_put_contents("{$repo}/app/Kernel.php", "<?php\n// fixture app code\n");
    file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/app']));

    mkdir("{$repo}/node_modules/some-pkg", 0777, true);
    file_put_contents("{$repo}/node_modules/some-pkg/index.js", "// should never be archived\n");
    mkdir("{$repo}/storage/logs", 0777, true);
    file_put_contents("{$repo}/storage/logs/laravel.log", "should never be archived\n");
    file_put_contents("{$repo}/.env", "DB_PASSWORD=super-secret-should-never-be-archived\n");

    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);
    $commitHash = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);

    return ['path' => $repo, 'commitHash' => $commitHash];
}

/**
 * @return array{process: resource, port: int, storageDir: string}
 */
function startFakeHub(): array
{
    $port = 21000 + random_int(0, 9000);
    $storageDir = sys_get_temp_dir() . '/fake-hub-artifact-storage-' . bin2hex(random_bytes(4));
    mkdir($storageDir, 0777, true);
    $router = __DIR__ . '/fixtures/fake_hub_artifact_server.php';

    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv() ?: [], ['FAKE_HUB_STORAGE_DIR' => $storageDir]);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'storageDir' => $storageDir];
        }
        usleep(100000);
    }

    throw new \RuntimeException('Fake Hub server did not start listening in time.');
}

function stopFakeHub(array $hub): void
{
    proc_terminate($hub['process']);
    proc_close($hub['process']);
    rrmdir($hub['storageDir']);
}

// ============================================================
// ArtifactBuilder: real `git archive`, verified with real ZipArchive
// ============================================================
$workspace = makeWorkspace();
$git = new GitManager();
$fixture = buildFixtureRepo($git, $workspace);

$builder = new ArtifactBuilder($git);
$ticks = 0;
$archive = $builder->build($fixture['path'], $fixture['commitHash'], function () use (&$ticks) { $ticks++; });

check('build() returns a path to a real file', is_file($archive['path']), $failures, $passCount);
check('build() returns a nonzero size matching the actual file', $archive['size'] === filesize($archive['path']) && $archive['size'] > 0, $failures, $passCount);
check('build() returns a checksum matching hash_file()', $archive['checksum'] === hash_file('sha256', $archive['path']), $failures, $passCount);

$zip = new ZipArchive();
$openResult = $zip->open($archive['path']);
check('the built archive is a valid, openable zip', $openResult === true, $failures, $passCount);

if ($openResult === true) {
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    check('archive contains the tracked app file', in_array('app/Kernel.php', $names, true), $failures, $passCount);
    check('archive contains composer.json', in_array('composer.json', $names, true), $failures, $passCount);
    check('archive does NOT contain .env (gitignored, never tracked)', !in_array('.env', $names, true), $failures, $passCount);
    check(
        'archive does NOT contain any node_modules/ entry (gitignored, never tracked)',
        !array_filter($names, static fn ($n) => str_starts_with($n, 'node_modules/')),
        $failures, $passCount,
    );
    check(
        'archive does NOT contain storage/logs/laravel.log (gitignored, never tracked)',
        !in_array('storage/logs/laravel.log', $names, true),
        $failures, $passCount,
    );
    check('archive does NOT contain a .git directory', !array_filter($names, static fn ($n) => str_starts_with($n, '.git/')), $failures, $passCount);
}

@unlink($archive['path']);

check('a bogus commit hash fails cleanly with a RuntimeException', (function () use ($builder, $fixture) {
    try {
        $builder->build($fixture['path'], str_repeat('f', 40));

        return false;
    } catch (\RuntimeException) {
        return true;
    }
})(), $failures, $passCount);

// ============================================================
// ArtifactUploader: full two-phase flow against a real fake Hub process
// ============================================================
$hub = startFakeHub();
$logger = new Logger($workspace . '/logs');
$client = new Client(
    baseUrl: "http://127.0.0.1:{$hub['port']}",
    localAgentId: 'test-local-agent',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 10,
    logger: $logger,
);
$uploader = new ArtifactUploader(new ArtifactBuilder($git), $client, $logger);

$uploadTicks = 0;
$result = $uploader->buildAndUpload('fixture-project', $fixture['path'], $fixture['commitHash'], 'main', function () use (&$uploadTicks) { $uploadTicks++; });

check('buildAndUpload() reports success against a healthy fake Hub', $result['succeeded'] === true, $failures, $passCount);

$metaFiles = glob("{$hub['storageDir']}/*.meta.json");
check('exactly one artifact metadata record was created', count($metaFiles) === 1, $failures, $passCount);

if ($metaFiles !== [] && $metaFiles !== false) {
    $meta = json_decode(file_get_contents($metaFiles[0]), true);
    check('uploaded metadata has the correct project_key', ($meta['project_key'] ?? null) === 'fixture-project', $failures, $passCount);
    check('uploaded metadata has the correct commit_hash', ($meta['commit_hash'] ?? null) === $fixture['commitHash'], $failures, $passCount);

    $artifactId = basename($metaFiles[0], '.meta.json');
    $binPath = "{$hub['storageDir']}/{$artifactId}.bin";
    check('the fake Hub received the artifact content', is_file($binPath), $failures, $passCount);

    if (is_file($binPath)) {
        check('the received bytes checksum matches the metadata-claimed checksum', hash_file('sha256', $binPath) === $meta['checksum_sha256'], $failures, $passCount);
    }
}

// Failure path: the Hub is unreachable entirely — must never throw out
// of buildAndUpload(), must report failure with a useful message.
$deadClient = new Client(
    baseUrl: 'http://127.0.0.1:1', // nothing listens here
    localAgentId: 'test-local-agent',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 2,
    logger: $logger,
);
$deadUploader = new ArtifactUploader(new ArtifactBuilder($git), $deadClient, $logger);
$deadResult = $deadUploader->buildAndUpload('fixture-project', $fixture['path'], $fixture['commitHash'], 'main');

check('buildAndUpload() reports failure (not a thrown exception) when the Hub is unreachable', $deadResult['succeeded'] === false, $failures, $passCount);
check('the failure message is non-empty and useful', $deadResult['message'] !== '', $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
