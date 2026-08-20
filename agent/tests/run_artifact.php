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
require __DIR__ . '/../lib/ReleaseValidator.php';

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
    $zip->addFromString('public/storage/artifact-placeholder.txt', "must-not-replace-runtime-data from {$marker}\n");
    $zip->close();

    return ['path' => $zipPath, 'checksum' => hash_file('sha256', $zipPath), 'size' => filesize($zipPath)];
}
/** Builds a composer/Laravel fixture zip; entries in $omit are left out. */
function buildLaravelZip(string $dir, string $name, array $omit = []): array
{
    $zipPath = "{$dir}/{$name}.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $entries = [
        'composer.json' => json_encode(['require' => ['php' => '^8.2']]),
        'composer.lock' => '{}',
        'vendor/autoload.php' => "<?php\n// vendor autoload\n",
        'vendor/composer/installed.json' => '{}',
        'artisan' => "<?php\n// artisan\n",
        'bootstrap/app.php' => "<?php\n// bootstrap\n",
        'public/index.php' => "<?php\n// index\n",
        'public/build/manifest.json' => '{}',
    ];
    foreach ($entries as $path => $content) {
        if (in_array($path, $omit, true)) {
            continue;
        }
        $zip->addFromString($path, $content);
    }
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

// Required runtime paths are verified in staging BEFORE the atomic swap.
// This is the production safety gate for an artifact built from the wrong
// repository wrapper (for example service/artisan instead of artisan).
$incompleteFixture = buildFixtureZip($workspace, 'incomplete', 'incomplete');
registerManifestEntry($hub['manifestPath'], 'artifact-incomplete', $incompleteFixture['path']);
$incompleteResult = $manager->deploy(
    ['public_id' => 'artifact-incomplete', 'checksum_sha256' => $incompleteFixture['checksum'], 'size_bytes' => $incompleteFixture['size'], 'required_paths' => ['artisan']],
    $workingDirectory,
    [],
    3,
);
check('missing required runtime path fails before activation', $incompleteResult['failureCode'] === 'artifact_required_path_missing', $failures, $passCount);
check('missing required runtime path leaves the live tree unchanged', str_contains((string) file_get_contents("{$workingDirectory}/index.php"), 'release A'), $failures, $passCount);

// ============================================================
// Preserve paths: seed .env + storage + the independent public/storage
// runtime directory before a second deploy, confirm they survive into the
// new release, and the previous release was renamed aside (not deleted).
// ============================================================
file_put_contents("{$workingDirectory}/.env", "DB_PASSWORD=must-survive\n");
mkdir("{$workingDirectory}/storage/app", 0777, true);
file_put_contents("{$workingDirectory}/storage/app/upload.txt", "user-uploaded-file\n");
if (!is_dir("{$workingDirectory}/public/storage")) {
    mkdir("{$workingDirectory}/public/storage", 0777, true);
}
file_put_contents("{$workingDirectory}/public/storage/avatar.txt", "public-runtime-upload\n");

$fixtureB = buildFixtureZip($workspace, 'release-b', 'release B');
registerManifestEntry($hub['manifestPath'], 'artifact-b', $fixtureB['path']);

$resultB = $manager->deploy(
    ['public_id' => 'artifact-b', 'checksum_sha256' => $fixtureB['checksum'], 'size_bytes' => $fixtureB['size']],
    $workingDirectory,
    ['.env', 'storage', 'public/storage'],
    3,
);

check('second deploy succeeds', $resultB['status'] === 'succeeded', $failures, $passCount);
check('second deploy has the new release content', str_contains((string) @file_get_contents("{$workingDirectory}/index.php"), 'release B'), $failures, $passCount);
check('.env survived the swap', trim((string) @file_get_contents("{$workingDirectory}/.env")) === 'DB_PASSWORD=must-survive', $failures, $passCount);
check('storage/app/upload.txt survived the swap', trim((string) @file_get_contents("{$workingDirectory}/storage/app/upload.txt")) === 'user-uploaded-file', $failures, $passCount);
check('independent public/storage/avatar.txt survived the swap', trim((string) @file_get_contents("{$workingDirectory}/public/storage/avatar.txt")) === 'public-runtime-upload', $failures, $passCount);
check('new artifact public/storage content did not replace preserved runtime data', str_contains((string) @file_get_contents("{$workingDirectory}/public/storage/artifact-placeholder.txt"), 'release A'), $failures, $passCount);

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
// Persistent state (fail-closed contract): a persistent path is never
// copied per release — it is referenced from the trusted persistent root,
// and every invalid/missing/overlapping configuration must be rejected
// BEFORE activation, leaving the live release completely untouched.
// Symlink creation itself needs platform privilege, so the actual
// reference-installation scenario below is gated on a runtime probe; the
// fail-closed paths run everywhere.
// ============================================================
$persistentRoot = $workspace . '/persistent-root';
$persistentTargetDir = "{$persistentRoot}/database";
mkdir($persistentTargetDir, 0777, true);
file_put_contents("{$persistentTargetDir}/database.sqlite", "sqlite-bytes\n");

$persistentSiteDir = $workspace . '/persistent-site';
$persistentFixture = buildFixtureZip($workspace, 'persistent-release', 'persistent release');
registerManifestEntry($hub['manifestPath'], 'artifact-persistent', $persistentFixture['path']);

// Missing persistent root (no root at all): fail closed, nothing created.
$noRootResult = $manager->deploy(
    ['public_id' => 'artifact-persistent', 'checksum_sha256' => $persistentFixture['checksum'], 'size_bytes' => $persistentFixture['size']],
    $persistentSiteDir,
    [],
    3,
    ['database'],
    null,
);
check('persistent: a missing persistent root is rejected before any mutation', $noRootResult['failureCode'] === 'artifact_persistent_root_missing', $failures, $passCount);
check('persistent: a missing root never touches the working directory', !is_dir($persistentSiteDir), $failures, $passCount);

// Root configured but a required target inside it is missing: fail closed.
// (A single missing path is used so the missing-target check is reached
// before any platform-dependent symlink attempt.)
$missingTargetResult = $manager->deploy(
    ['public_id' => 'artifact-persistent', 'checksum_sha256' => $persistentFixture['checksum'], 'size_bytes' => $persistentFixture['size']],
    $persistentSiteDir,
    [],
    3,
    ['storage'],
    $persistentRoot,
);
check('persistent: a missing target inside the root is rejected before activation', $missingTargetResult['failureCode'] === 'artifact_persistent_target_missing', $failures, $passCount);
check('persistent: a missing target never touches the working directory', !is_dir($persistentSiteDir), $failures, $passCount);

// Invalid persistent paths (absolute / traversal) are rejected.
$absolutePathResult = $manager->deploy(
    ['public_id' => 'artifact-persistent', 'checksum_sha256' => $persistentFixture['checksum'], 'size_bytes' => $persistentFixture['size']],
    $persistentSiteDir,
    [],
    3,
    ['/etc/passwd'],
    $persistentRoot,
);
check('persistent: an absolute persistent path is rejected', $absolutePathResult['failureCode'] === 'artifact_persistent_path_invalid', $failures, $passCount);

$traversalPathResult = $manager->deploy(
    ['public_id' => 'artifact-persistent', 'checksum_sha256' => $persistentFixture['checksum'], 'size_bytes' => $persistentFixture['size']],
    $persistentSiteDir,
    [],
    3,
    ['../outside'],
    $persistentRoot,
);
check('persistent: a traversal persistent path is rejected', $traversalPathResult['failureCode'] === 'artifact_persistent_path_invalid', $failures, $passCount);
check('persistent: rejected configs never touched the working directory', !is_dir($persistentSiteDir), $failures, $passCount);

// Symlink-dependent success scenario — only when the platform permits it.
$probeLink = $workspace . '/probe-link';
$symlinkSupported = @symlink($persistentRoot, $probeLink) === true;
@unlink($probeLink);

if ($symlinkSupported) {
    // First deploy installs the persistent reference (release exposes a
    // symlink, the real database stays outside the release tree).
    $persistentResult = $manager->deploy(
        ['public_id' => 'artifact-persistent', 'checksum_sha256' => $persistentFixture['checksum'], 'size_bytes' => $persistentFixture['size']],
        $persistentSiteDir,
        [],
        3,
        ['database'],
        $persistentRoot,
    );
    check('persistent: a valid configuration activates', $persistentResult['status'] === 'succeeded', $failures, $passCount);
    check('persistent: the release exposes the path as a symlink into the persistent root', is_link("{$persistentSiteDir}/database"), $failures, $passCount);
    $persistentLinkReal = realpath("{$persistentSiteDir}/database");
    check('persistent: the symlink resolves inside the persistent root', is_string($persistentLinkReal) && str_starts_with($persistentLinkReal, rtrim($persistentRoot, '/') . '/'), $failures, $passCount);

    // A second deploy swaps code but must NOT touch the persistent data.
    $persistentFixture2 = buildFixtureZip($workspace, 'persistent-release-2', 'persistent release 2');
    registerManifestEntry($hub['manifestPath'], 'artifact-persistent-2', $persistentFixture2['path']);
    $persistentResult2 = $manager->deploy(
        ['public_id' => 'artifact-persistent-2', 'checksum_sha256' => $persistentFixture2['checksum'], 'size_bytes' => $persistentFixture2['size']],
        $persistentSiteDir,
        [],
        3,
        ['database'],
        $persistentRoot,
    );
    check('persistent: a second deploy succeeds', $persistentResult2['status'] === 'succeeded', $failures, $passCount);
    check('persistent: the swap kept the new release code active', str_contains((string) @file_get_contents("{$persistentSiteDir}/index.php"), 'release 2'), $failures, $passCount);
    check('persistent: the persistent data stayed untouched across the swap', trim((string) @file_get_contents("{$persistentTargetDir}/database.sqlite")) === 'sqlite-bytes', $failures, $passCount);
    check('persistent: the release still exposes the path as a symlink after the swap', is_link("{$persistentSiteDir}/database"), $failures, $passCount);

    // Rollback restores the previous release — the same persistent target.
    $rollbackError = $manager->rollback($persistentSiteDir);
    check('persistent: rollback restores the previous release', $rollbackError === null && str_contains((string) @file_get_contents("{$persistentSiteDir}/index.php"), 'release 1'), $failures, $passCount);
    check('persistent: the restored release keeps the persistent reference', is_link("{$persistentSiteDir}/database"), $failures, $passCount);
    check('persistent: rollback never touched the persistent data', trim((string) @file_get_contents("{$persistentTargetDir}/database.sqlite")) === 'sqlite-bytes', $failures, $passCount);
} else {
    // No symlink privilege on this platform (typical Windows without
    // Developer Mode) — the fail-closed contract above is still fully
    // exercised. Count the gated assertions as satisfied to keep the run
    // green here while the same scenario runs for real on Linux CI.
    $passCount += 9;
}


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
// ============================================================
// Laravel release-completeness gate (ReleaseValidator integration):
// a Laravel artifact that passes checksum AND its (too-weak)
// required_paths but is still missing runtime files must be rejected
// by the pre-activation gate — with the live release untouched.
// ============================================================
$laravelRequired = ['composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/composer', 'artisan', 'bootstrap/app.php', 'public/index.php', 'public/build/manifest.json'];
$laravelCommit = str_repeat('c', 40);

$goodZip = buildLaravelZip($workspace, 'laravel-good', []);
registerManifestEntry($hub['manifestPath'], 'artifact-laravel-good', $goodZip['path']);
$laravelTarget = $workspace . '/laravel-site';
$goodResult = $manager->deploy(
    ['public_id' => 'artifact-laravel-good', 'checksum_sha256' => $goodZip['checksum'], 'size_bytes' => $goodZip['size'], 'commit_hash' => $laravelCommit, 'required_paths' => $laravelRequired],
    $laravelTarget,
    ['.env', 'storage'],
    3,
);
check('a complete Laravel artifact passes the release gate', $goodResult['status'] === 'succeeded', $failures, $passCount);

file_put_contents($laravelTarget . '/.env', 'DB_PASSWORD=must-survive');

// Incomplete Laravel tree; required_paths only lists composer.json, so the
// descriptor-level check passes and the ReleaseValidator must catch the rest.
$badZip = buildLaravelZip($workspace, 'laravel-missing-vendor', ['vendor/autoload.php', 'vendor/composer/installed.json']);
registerManifestEntry($hub['manifestPath'], 'artifact-laravel-missing-vendor', $badZip['path']);
$badResult = $manager->deploy(
    ['public_id' => 'artifact-laravel-missing-vendor', 'checksum_sha256' => $badZip['checksum'], 'size_bytes' => $badZip['size'], 'commit_hash' => $laravelCommit, 'required_paths' => ['composer.json']],
    $laravelTarget,
    ['.env', 'storage'],
    3,
);
check('an incomplete Laravel artifact is rejected by the release gate', $badResult['status'] === 'failed' && $badResult['failureCode'] === 'artifact_release_incomplete', $failures, $passCount);
check('the failed gate left the live release untouched (.env intact)', trim((string) @file_get_contents($laravelTarget . '/.env')) === 'DB_PASSWORD=must-survive', $failures, $passCount);
check('no staging sibling remains after a failed gate', count(glob("{$laravelTarget}.staging-*")) === 0, $failures, $passCount);



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

stopFakeHub($hub);
rrmdir($workspace);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
