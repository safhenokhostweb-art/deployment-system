<?php

declare(strict_types=1);

/**
 * Dependency-free integration test for ArtifactManager (the customer-site
 * direct-GitHub-dependency removal work) — same philosophy as
 * tests/run.php/run_recipe.php: no mocking framework, real fixtures, real
 * assertions. Runs a real fake-Hub `php -S` process serving real zip
 * bytes over real HTTP, and exercises ArtifactManager's actual
 * filesystem operations (extract, preserve-copy, atomic rename-swap,
 * backup retention) against real temp directories.
 *
 * Usage: php tests/run_artifact.php
 */

require __DIR__ . '/../lib/Signer.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/ArtifactManager.php';

use DeploymentAgent\ArtifactManager;
use DeploymentAgent\Client;
use DeploymentAgent\Logger;

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
    $root = sys_get_temp_dir() . '/artifact-agent-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir)) {
        unlink($dir);

        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);

            continue;
        }
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

/** Builds a real zip fixture with a couple of "app" files, returns its path + checksum. */
function buildFixtureZip(string $dir, string $name, string $marker): array
{
    $zipPath = "{$dir}/{$name}.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('index.php', "<?php\n// {$marker}\n");
    $zip->addFromString('app/Kernel.php', "<?php\n// fixture kernel\n");
    // vendor/ and public/build/ are already baked into a real artifact by
    // the Local Agent (see local-agent's ArtifactBuilder) — every entry
    // here is tagged with $marker so a test can prove which release's
    // build output actually ended up active, never a stale one preserved
    // from whatever the previous release happened to have.
    $zip->addFromString('vendor/autoload.php', "<?php\n// vendor from {$marker}\n");
    $zip->addFromString('public/build/manifest.json', json_encode(['from' => $marker]));
    $zip->close();

    return ['path' => $zipPath, 'checksum' => hash_file('sha256', $zipPath), 'size' => filesize($zipPath)];
}

/**
 * @return array{process: resource, port: int, manifestPath: string}
 */
function startFakeHub(): array
{
    $port = 22000 + random_int(0, 9000);
    $manifestPath = tempnam(sys_get_temp_dir(), 'artifact-manifest-');
    file_put_contents($manifestPath, json_encode([]));
    $router = __DIR__ . '/fixtures/fake_hub_artifact_download_server.php';

    // Root-cause fix (the exact class of bug already documented in
    // Executor.php/GitManager.php elsewhere in this codebase): `php -S`
    // logs a line to its own stdout/stderr per request; piped and never
    // drained, the OS pipe buffer can fill and block the server's next
    // write, silently freezing it. A file redirect can never block.
    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-server-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], ['ARTIFACT_MANIFEST_PATH' => $manifestPath]);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'manifestPath' => $manifestPath, 'serverLogPath' => $serverLogPath];
        }
        usleep(100000);
    }

    throw new \RuntimeException('Fake Hub server did not start listening in time.');
}

function stopFakeHub(array $hub): void
{
    proc_terminate($hub['process']);
    proc_close($hub['process']);
    @unlink($hub['manifestPath']);
    @unlink($hub['serverLogPath']);
}

function registerManifestEntry(string $manifestPath, string $publicId, string $file, int $status = 200): void
{
    $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
    $manifest[$publicId] = ['file' => $file, 'status' => $status];
    file_put_contents($manifestPath, json_encode($manifest));
}

$workspace = makeWorkspace();
$hub = startFakeHub();
$logger = new Logger($workspace . '/logs');
$client = new Client(
    baseUrl: "http://127.0.0.1:{$hub['port']}",
    serverId: 'test-server',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 10,
    logger: $logger,
);
$manager = new ArtifactManager($client);

// ============================================================
// First-ever deploy: no previous working_directory at all.
// ============================================================
$fixtureA = buildFixtureZip($workspace, 'release-a', 'release A');
registerManifestEntry($hub['manifestPath'], 'artifact-a', $fixtureA['path']);
$workingDirectory = $workspace . '/site';

$resultA = $manager->deploy(
    ['public_id' => 'artifact-a', 'checksum_sha256' => $fixtureA['checksum'], 'size_bytes' => $fixtureA['size']],
    $workingDirectory,
    [],
    3,
);

check('first deploy succeeds', $resultA['status'] === 'succeeded', $failures, $passCount);
check('first deploy extracts index.php into working_directory', is_file("{$workingDirectory}/index.php"), $failures, $passCount);
check('first deploy extracts app/Kernel.php into working_directory', is_file("{$workingDirectory}/app/Kernel.php"), $failures, $passCount);

// Recipe source acquisition: the exact same verified artifact is extracted
// into a brand-new immutable release directory. No activation occurs here;
// ReleaseManager owns current/previous and rollback after recipe steps pass.
$immutableRelease = $workspace . '/recipe/releases/deploy-from-artifact';
$releaseResult = $manager->extractToRelease(
    ['public_id' => 'artifact-a', 'checksum_sha256' => $fixtureA['checksum'], 'size_bytes' => $fixtureA['size']],
    $immutableRelease,
);
check('recipe artifact extraction succeeds', $releaseResult['status'] === 'succeeded', $failures, $passCount);
check('recipe artifact extraction materializes the exact artifact content in its immutable release directory', str_contains((string) @file_get_contents("{$immutableRelease}/index.php"), 'release A'), $failures, $passCount);
$duplicateReleaseResult = $manager->extractToRelease(
    ['public_id' => 'artifact-a', 'checksum_sha256' => $fixtureA['checksum'], 'size_bytes' => $fixtureA['size']],
    $immutableRelease,
);
check('recipe artifact extraction never overwrites an existing immutable release directory', $duplicateReleaseResult['status'] === 'failed', $failures, $passCount);
$incompleteRelease = $workspace . '/recipe/releases/incomplete-artifact';
$incompleteResult = $manager->extractToRelease(
    ['public_id' => 'artifact-a', 'checksum_sha256' => $fixtureA['checksum'], 'size_bytes' => $fixtureA['size']],
    $incompleteRelease,
    null,
    ['missing/prebuilt/output.js'],
);
check('complete-artifact required paths are checked before an immutable release directory is created', $incompleteResult['failureCode'] === 'artifact_incomplete' && !is_dir($incompleteRelease), $failures, $passCount);

// ============================================================
// Preserve paths: seed .env + storage before a second deploy, confirm
// they survive into the new release, and the previous release was
// renamed aside (not deleted).
// ============================================================
file_put_contents("{$workingDirectory}/.env", "DB_PASSWORD=must-survive\n");
mkdir("{$workingDirectory}/storage/app", 0777, true);
file_put_contents("{$workingDirectory}/storage/app/upload.txt", "user-uploaded-file\n");

$fixtureB = buildFixtureZip($workspace, 'release-b', 'release B');
registerManifestEntry($hub['manifestPath'], 'artifact-b', $fixtureB['path']);

$resultB = $manager->deploy(
    ['public_id' => 'artifact-b', 'checksum_sha256' => $fixtureB['checksum'], 'size_bytes' => $fixtureB['size']],
    $workingDirectory,
    ['.env', 'storage'],
    3,
);

check('second deploy succeeds', $resultB['status'] === 'succeeded', $failures, $passCount);
check('second deploy has the new release content', str_contains((string) @file_get_contents("{$workingDirectory}/index.php"), 'release B'), $failures, $passCount);
check('.env survived the swap', trim((string) @file_get_contents("{$workingDirectory}/.env")) === 'DB_PASSWORD=must-survive', $failures, $passCount);
check('storage/app/upload.txt survived the swap', trim((string) @file_get_contents("{$workingDirectory}/storage/app/upload.txt")) === 'user-uploaded-file', $failures, $passCount);

// vendor/ and public/build/ are NOT in the preserve-paths list passed to
// deploy() above (only .env/storage are) — they must come exclusively
// from the new artifact (release B), never carried over from release A.
$vendorContent = (string) @file_get_contents("{$workingDirectory}/vendor/autoload.php");
$manifestContent = (string) @file_get_contents("{$workingDirectory}/public/build/manifest.json");
check('vendor/ comes from the NEW artifact, not preserved from the previous release', str_contains($vendorContent, 'release B') && !str_contains($vendorContent, 'release A'), $failures, $passCount);
check('public/build/ comes from the NEW artifact, not preserved from the previous release', str_contains($manifestContent, 'release B') && !str_contains($manifestContent, 'release A'), $failures, $passCount);

$backups = glob("{$workspace}/site.backup-*");
check('the previous release was renamed aside as a backup, not deleted', count($backups) === 1, $failures, $passCount);
if ($backups !== [] && $backups !== false) {
    check('the backup contains the FIRST release\'s content (proving it was the pre-swap version)', str_contains((string) @file_get_contents($backups[0] . '/index.php'), 'release A'), $failures, $passCount);
}

// ============================================================
// Retention: run two more successful deploys (total 4), keep only 2 backups.
// ============================================================
foreach (['c', 'd'] as $letter) {
    $fixture = buildFixtureZip($workspace, "release-{$letter}", "release " . strtoupper($letter));
    registerManifestEntry($hub['manifestPath'], "artifact-{$letter}", $fixture['path']);
    $manager->deploy(['public_id' => "artifact-{$letter}", 'checksum_sha256' => $fixture['checksum'], 'size_bytes' => $fixture['size']], $workingDirectory, ['.env', 'storage'], 2);
    sleep(1); // distinct backup-dir mtimes for deterministic retention ordering
}

$backupsAfterRetention = glob("{$workspace}/site.backup-*");
check('retention keeps only the configured number of backups', count($backupsAfterRetention) === 2, $failures, $passCount);

// ============================================================
// Failure paths
// ============================================================
$freshTarget = $workspace . '/checksum-mismatch-site';
$fixtureMismatch = buildFixtureZip($workspace, 'mismatch', 'mismatch');
registerManifestEntry($hub['manifestPath'], 'artifact-mismatch', $fixtureMismatch['path']);
$mismatchResult = $manager->deploy(
    ['public_id' => 'artifact-mismatch', 'checksum_sha256' => str_repeat('0', 64), 'size_bytes' => $fixtureMismatch['size']],
    $freshTarget,
    [],
    3,
);
check('a checksum mismatch is reported as failed', $mismatchResult['status'] === 'failed', $failures, $passCount);
check('a checksum mismatch reports the correct failure code', $mismatchResult['failureCode'] === 'artifact_checksum_mismatch', $failures, $passCount);
check('a checksum mismatch never touches the target directory', !is_dir($freshTarget), $failures, $passCount);

$recipeMismatchTarget = $workspace . '/recipe/releases/checksum-mismatch';
$recipeMismatchResult = $manager->extractToRelease(
    ['public_id' => 'artifact-mismatch', 'checksum_sha256' => str_repeat('0', 64), 'size_bytes' => $fixtureMismatch['size']],
    $recipeMismatchTarget,
);
check('recipe artifact checksum mismatch fails before a release directory is created', $recipeMismatchResult['failureCode'] === 'artifact_checksum_mismatch' && !is_dir($recipeMismatchTarget), $failures, $passCount);

$notFoundTarget = $workspace . '/download-failure-site';
$notFoundResult = $manager->deploy(
    ['public_id' => 'artifact-does-not-exist', 'checksum_sha256' => str_repeat('1', 64), 'size_bytes' => 1],
    $notFoundTarget,
    [],
    3,
);
check('a 404 download is reported as failed', $notFoundResult['status'] === 'failed', $failures, $passCount);
check('a 404 download reports the correct failure code', $notFoundResult['failureCode'] === 'artifact_download_failed', $failures, $passCount);
check('a 404 download never touches the target directory', !is_dir($notFoundTarget), $failures, $passCount);

$corruptTarget = $workspace . '/extract-failure-site';
$corruptContent = 'this is definitely not a valid zip file';
$corruptPath = "{$workspace}/corrupt.zip";
file_put_contents($corruptPath, $corruptContent);
registerManifestEntry($hub['manifestPath'], 'artifact-corrupt', $corruptPath);
$corruptResult = $manager->deploy(
    ['public_id' => 'artifact-corrupt', 'checksum_sha256' => hash_file('sha256', $corruptPath), 'size_bytes' => strlen($corruptContent)],
    $corruptTarget,
    [],
    3,
);
check('a corrupt (non-zip) artifact is reported as failed', $corruptResult['status'] === 'failed', $failures, $passCount);
check('a corrupt artifact reports the correct failure code', $corruptResult['failureCode'] === 'artifact_extract_failed', $failures, $passCount);
check('a corrupt artifact never touches the target directory', !is_dir($corruptTarget), $failures, $passCount);

$recipeCorruptTarget = $workspace . '/recipe/releases/corrupt';
$recipeCorruptResult = $manager->extractToRelease(
    ['public_id' => 'artifact-corrupt', 'checksum_sha256' => hash_file('sha256', $corruptPath), 'size_bytes' => strlen($corruptContent)],
    $recipeCorruptTarget,
);
check('recipe corrupt artifact fails before activation and cleans its release directory', $recipeCorruptResult['failureCode'] === 'artifact_extract_failed' && !is_dir($recipeCorruptTarget), $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
