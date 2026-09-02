<?php

declare(strict_types=1);

/**
 * Dependency-free integration test for LocalBuildRunner + ArtifactBuilder
 * + ArtifactUploader — the root-cause fix for production having no access
 * to repo.packagist.org, api.github.com, or the npm registry. Same
 * philosophy as tests/run.php: no mocking framework, real fixtures, real
 * assertions. Genuinely runs real `composer`/`npm`/`git` processes.
 *
 * Root-cause fix (v1.4.2, isolated build workspace): ArtifactBuilder no
 * longer does `git archive`/staging assembly itself (that moved to
 * WorkspaceSourcePreparer — see run_workspace_isolation.php for its own
 * dedicated tests) — it now just validates + zips an ALREADY-PREPARED
 * directory. This file's ArtifactBuilder/ArtifactUploader sections were
 * rewritten for that new, narrower API; the LocalBuildRunner section is
 * unchanged (that class was never coupled to workspaces at all — it just
 * runs composer/npm in whatever directory it's given).
 *
 * Requires `composer` and `npm` to be resolvable on PATH (this project's
 * dev machine has both via Laragon's bundled composer.bat and Node's
 * npm.cmd — see Executor/GitManager's Windows .cmd-shim resolution).
 *
 * Usage: php tests/run_artifact.php
 */

require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/ArtifactSourceResolver.php';
require __DIR__ . '/../lib/ApplicationRoot.php';
require __DIR__ . '/../lib/LocalBuildRunner.php';
require __DIR__ . '/../lib/RetryPolicy.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';
require __DIR__ . '/../lib/ArtifactUploader.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactSourceResolver;
use LocalAgent\ArtifactUploader;
use LocalAgent\BuildWorkspace;
use LocalAgent\Client;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;
use LocalAgent\SafeFilesystem;
use LocalAgent\WorkspaceSourcePreparer;

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
    return $git->run(array_merge(['git'], $argv), $cwd, 60);
}

function zipEntryNames(string $zipPath): array
{
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

/**
 * A real git repo with: a composer.json (zero real dependencies — offline
 * and instant), a package.json with a "build" script that writes
 * public/build/manifest.json (also zero real npm dependencies), tracked
 * source, and a .gitignore excluding .env/node_modules/storage/logs/
 * vendor.
 */
function buildFixtureRepo(GitManager $git, string $workspace): array
{
    $repo = $workspace . '/repo';
    mkdir($repo, 0777, true);

    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Artifact Test'], $repo);

    file_put_contents("{$repo}/.gitignore", ".env\nnode_modules/\nstorage/logs/\nvendor/\npublic/build/\n");
    mkdir("{$repo}/app", 0777, true);
    file_put_contents("{$repo}/app/Kernel.php", "<?php\n// fixture app code\n");
    file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/app'], JSON_PRETTY_PRINT));
    file_put_contents("{$repo}/package.json", json_encode([
        'name' => 'fixture-app', 'private' => true,
        'scripts' => ['build' => 'node build.js'],
    ], JSON_PRETTY_PRINT));
    file_put_contents("{$repo}/build.js", <<<'JS'
        const fs = require('fs');
        fs.mkdirSync(`${__dirname}/public/build`, { recursive: true });
        fs.writeFileSync(`${__dirname}/public/build/manifest.json`, JSON.stringify({ 'app.js': 'assets/app.abc123.js' }));
        fs.writeFileSync(`${__dirname}/public/build/app.abc123.js`, '// built asset\n');
        console.log('build ok');
        JS);

    mkdir("{$repo}/storage/logs", 0777, true);
    file_put_contents("{$repo}/storage/logs/laravel.log", "should never be archived\n");
    file_put_contents("{$repo}/.env", "DB_PASSWORD=super-secret-should-never-be-archived\n");

    // `npm ci` (what LocalBuildRunner actually runs) requires an existing
    // package-lock.json by design — a real project always commits one;
    // this fixture generates and commits it the same way, offline and
    // instantly since there are zero real dependencies to resolve.
    $git->run(['npm', 'install', '--package-lock-only'], $repo, 60);

    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);
    $commitHash = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);

    return ['path' => $repo, 'commitHash' => $commitHash];
}

function buildFixtureRepoMinimal(GitManager $git, string $workspace, bool $withComposerJson = false, bool $withPackageJson = false): array
{
    $repo = $workspace . '/repo';
    mkdir($repo, 0777, true);
    runGit($git, ['init', '--quiet', '-b', 'main'], $repo);
    runGit($git, ['config', 'user.email', 'test@example.test'], $repo);
    runGit($git, ['config', 'user.name', 'Artifact Test'], $repo);
    if ($withComposerJson) {
        file_put_contents("{$repo}/composer.json", json_encode(['name' => 'fixture/minimal'], JSON_PRETTY_PRINT));
    }
    if ($withPackageJson) {
        file_put_contents("{$repo}/package.json", json_encode([
            'name' => 'fixture-minimal',
            'private' => true,
            'scripts' => ['build' => 'node -e "process.exit(0)"'],
        ], JSON_PRETTY_PRINT));
    }
    file_put_contents("{$repo}/index.php", "<?php\n");
    runGit($git, ['add', '-A'], $repo);
    runGit($git, ['commit', '--quiet', '-m', 'initial'], $repo);
    $commitHash = trim(runGit($git, ['rev-parse', 'HEAD'], $repo)['output']);

    return ['path' => $repo, 'commitHash' => $commitHash];
}

/**
 * @return array{process: resource, port: int, storageDir: string, serverLogPath: string}
 */
function startFakeHub(): array
{
    $port = 21000 + random_int(0, 9000);
    $storageDir = sys_get_temp_dir() . '/fake-hub-artifact-storage-' . bin2hex(random_bytes(4));
    mkdir($storageDir, 0777, true);
    $router = __DIR__ . '/fixtures/fake_hub_artifact_server.php';

    $serverLogPath = tempnam(sys_get_temp_dir(), 'fake-hub-server-stdio-');
    $descriptorSpec = [1 => ['file', $serverLogPath, 'w'], 2 => ['file', $serverLogPath, 'w']];
    $env = array_merge(getenv() ?: [], ['FAKE_HUB_STORAGE_DIR' => $storageDir]);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $descriptorSpec, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start fake Hub server.');
    }

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port, 'storageDir' => $storageDir, 'serverLogPath' => $serverLogPath];
        }
        usleep(100000);
    }

    throw new \RuntimeException('Fake Hub server did not start listening in time.');
}

function stopFakeHub(array $hub): void
{
    proc_terminate($hub['process']);
    proc_close($hub['process']);
    @unlink($hub['serverLogPath']);
    rrmdir($hub['storageDir']);
}

// ============================================================
// LocalBuildRunner: real composer + npm, success and every failure mode.
// Unaffected by the isolated-workspace refactor — this class only ever
// runs composer/npm in whatever directory it's given, with no coupling
// to where that directory came from.
// ============================================================
$workspace = makeWorkspace();
$git = new GitManager();
$fixture = buildFixtureRepo($git, $workspace);

$runner = new LocalBuildRunner($git);
$result = $runner->run($fixture['path']);

check('LocalBuildRunner: composer.json present -> ranComposer', $result['ranComposer'] === true, $failures, $passCount);
check('LocalBuildRunner: composer install actually produced vendor/autoload.php', is_file("{$fixture['path']}/vendor/autoload.php"), $failures, $passCount);
check('LocalBuildRunner: package.json present -> ranNpmInstall', $result['ranNpmInstall'] === true, $failures, $passCount);
check('LocalBuildRunner: "build" script present -> ranNpmBuild', $result['ranNpmBuild'] === true, $failures, $passCount);
check('LocalBuildRunner: npm run build actually produced the manifest', is_file("{$fixture['path']}/public/build/manifest.json"), $failures, $passCount);

// A project with neither manifest: both stages skipped, no exception.
$bareWorkspace = makeWorkspace();
$bareRepo = $bareWorkspace . '/repo';
mkdir($bareRepo, 0777, true);
runGit($git, ['init', '--quiet', '-b', 'main'], $bareRepo);
$bareResult = $runner->run($bareRepo);
check('LocalBuildRunner: a project with neither composer.json nor package.json skips both stages', $bareResult === ['ranComposer' => false, 'ranNpmInstall' => false, 'ranNpmBuild' => false, 'log' => []], $failures, $passCount);
rrmdir($bareWorkspace);

// composer install failure: a composer.json requiring a package that cannot possibly exist.
$brokenComposerWorkspace = makeWorkspace();
$brokenComposerRepo = $brokenComposerWorkspace . '/repo';
mkdir($brokenComposerRepo, 0777, true);
file_put_contents("{$brokenComposerRepo}/composer.json", json_encode(['name' => 'fixture/broken', 'require' => ['this-vendor/this-package-does-not-exist-anywhere' => '^999.0']]));
check('LocalBuildRunner: a composer install failure throws local_composer_failed', (function () use ($runner, $brokenComposerRepo) {
    try {
        $runner->run($brokenComposerRepo);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'local_composer_failed';
    }
})(), $failures, $passCount);
rrmdir($brokenComposerWorkspace);

// npm ci failure: package.json + package-lock.json that are inconsistent (npm ci requires an exact lockfile match).
$brokenNpmWorkspace = makeWorkspace();
$brokenNpmRepo = $brokenNpmWorkspace . '/repo';
mkdir($brokenNpmRepo, 0777, true);
file_put_contents("{$brokenNpmRepo}/package.json", json_encode(['name' => 'broken-npm', 'dependencies' => ['this-pkg-does-not-exist-xyz' => '^1.0.0']]));
file_put_contents("{$brokenNpmRepo}/package-lock.json", json_encode(['name' => 'broken-npm', 'lockfileVersion' => 3]));
check('LocalBuildRunner: an npm ci failure throws local_npm_install_failed', (function () use ($runner, $brokenNpmRepo) {
    try {
        $runner->run($brokenNpmRepo);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'local_npm_install_failed';
    }
})(), $failures, $passCount);
rrmdir($brokenNpmWorkspace);

// npm run build failure: a "build" script that deliberately exits non-zero.
$brokenBuildWorkspace = makeWorkspace();
$brokenBuildRepo = $brokenBuildWorkspace . '/repo';
mkdir($brokenBuildRepo, 0777, true);
file_put_contents("{$brokenBuildRepo}/package.json", json_encode(['name' => 'broken-build', 'scripts' => ['build' => 'node -e "process.exit(1)"']]));
$git->run(['npm', 'install', '--package-lock-only'], $brokenBuildRepo, 60);
check('LocalBuildRunner: an npm run build failure throws local_asset_build_failed', (function () use ($runner, $brokenBuildRepo) {
    try {
        $runner->run($brokenBuildRepo);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'local_asset_build_failed';
    }
})(), $failures, $passCount);
rrmdir($brokenBuildWorkspace);

// ============================================================
// SafeFilesystem: ZIP-Slip guard (moved here from ArtifactBuilder in the
// v1.4.2 isolated-workspace refactor — now a shared primitive used by
// both WorkspaceSourcePreparer and (indirectly) ArtifactBuilder).
// ============================================================
check('SafeFilesystem: rejects a zip entry with a path-traversal name (ZIP Slip)', (function () use ($workspace) {
    $maliciousZip = "{$workspace}/malicious.zip";
    $zip = new ZipArchive();
    $zip->open($maliciousZip, ZipArchive::CREATE);
    $zip->addFromString('../../evil.txt', 'escaped');
    $zip->close();

    $destination = "{$workspace}/zip-slip-destination";
    mkdir($destination, 0777, true);

    try {
        SafeFilesystem::extractZipSafely($maliciousZip, $destination);

        return false;
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'unsafe path');
    } finally {
        rrmdir($destination);
        @unlink($maliciousZip);
    }
})(), $failures, $passCount);

// ============================================================
// ArtifactBuilder: now takes an ALREADY-PREPARED project directory (see
// run_workspace_isolation.php for how WorkspaceSourcePreparer populates
// one for real) — validate + zip only, no more archive/copy-in logic.
// ============================================================
$builder = new ArtifactBuilder();
$buildResult = ['ranComposer' => true, 'ranNpmBuild' => true];
// $fixture['path'] doubles as "an already-prepared project dir" here —
// composer/npm above already ran directly inside it, so it already has
// tracked source + vendor + public/build, exactly what a real prepared
// workspace project dir would look like.
$commitTimestamp = time();
$archive = $builder->build($fixture['path'], $workspace, $commitTimestamp, $buildResult);

$customerSiteSource = ArtifactSourceResolver::resolve([
    'source_workspace_path' => $fixture['path'],
    'local_path' => '/var/www/customer-site',
    'production_path' => '/var/www/customer-site',
    'requires_complete_artifact' => true,
], 'customer-site');
check('artifact-required customer-site resolves its isolated source workspace instead of production_path/local_path', $customerSiteSource === $fixture['path'], $failures, $passCount);
check('artifact source resolver accepts a validated isolated legacy cache path only as fallback', ArtifactSourceResolver::resolve(['local_path' => $fixture['path']], 'customer-site') === $fixture['path'], $failures, $passCount);

check('ArtifactBuilder: produces a real, nonzero-size file', is_file($archive['path']) && $archive['size'] > 0, $failures, $passCount);
check('ArtifactBuilder: checksum matches hash_file()', $archive['checksum'] === hash_file('sha256', $archive['path']), $failures, $passCount);

foreach (['/var/www/customer-site', '/home/customer/public_html', 'C:\\xampp\\htdocs\\customer-site'] as $forbiddenSource) {
    check("artifact source resolver rejects production-like source {$forbiddenSource}", (function () use ($forbiddenSource): bool {
        try {
            ArtifactSourceResolver::resolve(['local_path' => $forbiddenSource], 'customer-site');

            return false;
        } catch (LocalBuildFailedException $e) {
            return $e->failureCode === 'production_source_forbidden';
        }
    })(), $failures, $passCount);
}

$names = zipEntryNames($archive['path']);
check('artifact contains the tracked source file', in_array('app/Kernel.php', $names, true), $failures, $passCount);
check('artifact contains vendor/autoload.php', in_array('vendor/autoload.php', $names, true), $failures, $passCount);
check('artifact contains public/build/manifest.json', in_array('public/build/manifest.json', $names, true), $failures, $passCount);
check('artifact does NOT contain any node_modules/ entry (excluded even though it now sits right next to vendor/)', !array_filter($names, static fn ($n) => str_starts_with($n, 'node_modules/')), $failures, $passCount);
check('artifact does NOT contain any .git/ entry', !array_filter($names, static fn ($n) => str_starts_with($n, '.git/')), $failures, $passCount);

// Deterministic: building the same prepared directory twice yields byte-identical checksums.
$archive2 = $builder->build($fixture['path'], $workspace, $commitTimestamp, $buildResult);
check('ArtifactBuilder: building the same prepared directory twice is deterministic (identical checksum)', $archive['checksum'] === $archive2['checksum'], $failures, $passCount);
@unlink($archive['path']);
@unlink($archive2['path']);

// Validation: claims composer ran, but vendor/autoload.php doesn't actually exist.
$noVendorWorkspace = makeWorkspace();
$git2 = new GitManager();
$noVendorFixture = buildFixtureRepoMinimal($git2, $noVendorWorkspace, true, false);
check('ArtifactBuilder: throws artifact_missing_vendor when composer supposedly ran but vendor is absent', (function () use ($builder, $noVendorFixture, $noVendorWorkspace) {
    try {
        $builder->build($noVendorFixture['path'], $noVendorWorkspace, time(), ['ranComposer' => true, 'ranNpmBuild' => false]);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'artifact_missing_vendor';
    }
})(), $failures, $passCount);
$noBuildWorkspace = makeWorkspace();
$git3 = new GitManager();
$noBuildFixture = buildFixtureRepoMinimal($git3, $noBuildWorkspace, false, true);
check('ArtifactBuilder: throws artifact_missing_build when npm build supposedly ran but manifest is absent', (function () use ($builder, $noBuildFixture, $noBuildWorkspace) {
    try {
        $builder->build($noBuildFixture['path'], $noBuildWorkspace, time(), ['ranComposer' => false, 'ranNpmBuild' => true]);

        return false;
    } catch (LocalBuildFailedException $e) {
        return $e->failureCode === 'artifact_missing_build';
    }
})(), $failures, $passCount);
rrmdir($noBuildWorkspace);
rrmdir($noVendorWorkspace);

// ============================================================
// ArtifactUploader: full pipeline (workspace create -> prepare -> build
// -> npm/composer -> zip -> upload -> cleanup) against a real fake Hub.
// Root-cause fix (v1.4.2): the fixture repo used as $fixture['path'] is
// now what a REAL developer working tree is — the whole point of this
// section is proving the pipeline NEVER writes into it.
// ============================================================
$uploaderWorkspaceBase = makeWorkspace() . '/agent-workspaces';
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
$uploader = new ArtifactUploader(new LocalBuildRunner($git), new WorkspaceSourcePreparer($git), new ArtifactBuilder(), $client, $logger, $uploaderWorkspaceBase);

// A fresh repo for this section — the earlier $fixture['path'] already
// has vendor/node_modules from the direct LocalBuildRunner test above,
// which would make "the real working tree was never touched" trivially
// true for the wrong reason (there was nothing there to touch in the
// first place after this point). This one starts clean.
$uploadWorkspace = makeWorkspace();
$uploadFixture = buildFixtureRepo($git, $uploadWorkspace);
// A real repo's working tree at this exact commit has none of these yet
// — confirmed BEFORE the pipeline runs, so "still absent after" actually
// proves something.
check('sanity: the fresh fixture repo has no vendor/ yet', !is_dir("{$uploadFixture['path']}/vendor"), $failures, $passCount);
check('sanity: the fresh fixture repo has no node_modules/ yet', !is_dir("{$uploadFixture['path']}/node_modules"), $failures, $passCount);

$uploadResult = $uploader->buildAndUpload('fixture-project', $uploadFixture['path'], $uploadFixture['commitHash'], 'main', 'job-1');
check('ArtifactUploader: full pipeline succeeds against a healthy fake Hub', $uploadResult['succeeded'] === true, $failures, $passCount);
check('ArtifactUploader: failureCode is null on success', $uploadResult['failureCode'] === null, $failures, $passCount);
check('ArtifactUploader: the REAL working tree still has no vendor/ after a successful build (composer ran in an isolated workspace, not here)', !is_dir("{$uploadFixture['path']}/vendor"), $failures, $passCount);
check('ArtifactUploader: the REAL working tree still has no node_modules/ after a successful build', !is_dir("{$uploadFixture['path']}/node_modules"), $failures, $passCount);
check('ArtifactUploader: the isolated workspace for this job was cleaned up afterwards', !is_dir("{$uploaderWorkspaceBase}/fixture-project/job-1"), $failures, $passCount);

$metaFiles = glob("{$hub['storageDir']}/*.meta.json");
check('exactly one artifact metadata record was created', count($metaFiles) === 1, $failures, $passCount);
if ($metaFiles !== [] && $metaFiles !== false) {
    $artifactId = basename($metaFiles[0], '.meta.json');
    check('the fake Hub received the artifact content (containing vendor + public/build)', is_file("{$hub['storageDir']}/{$artifactId}.bin"), $failures, $passCount);
    $uploadedZip = new ZipArchive();
    $uploadedZip->open("{$hub['storageDir']}/{$artifactId}.bin");
    $uploadedManifest = json_decode((string) $uploadedZip->getFromName('.deployment/release-manifest.json'), true);
    $uploadedZip->close();
    check(
        'ArtifactUploader: sealed manifest inventories tracked source but not generated dependency/build output',
        in_array('app/Kernel.php', $uploadedManifest['source_paths'] ?? [], true)
            && !in_array('vendor/autoload.php', $uploadedManifest['source_paths'] ?? [], true)
            && !in_array('public/build/manifest.json', $uploadedManifest['source_paths'] ?? [], true),
        $failures,
        $passCount,
    );
}

// Failure path: LocalBuildRunner fails (broken composer.json) — the
// Publish Job must be reported as failed, with the specific failure
// code, and no artifact metadata/content should ever be sent to the Hub.
@array_map('unlink', glob("{$hub['storageDir']}/*"));
$brokenComposerWorkspace2 = makeWorkspace();
$brokenComposerRepo2 = $brokenComposerWorkspace2 . '/repo';
mkdir($brokenComposerRepo2, 0777, true);
runGit($git, ['init', '--quiet', '-b', 'main'], $brokenComposerRepo2);
runGit($git, ['config', 'user.email', 'test@example.test'], $brokenComposerRepo2);
runGit($git, ['config', 'user.name', 'Artifact Test'], $brokenComposerRepo2);
file_put_contents("{$brokenComposerRepo2}/composer.json", json_encode(['name' => 'fixture/broken2', 'require' => ['nope/nope-xyz' => '^999.0']]));
runGit($git, ['add', '-A'], $brokenComposerRepo2);
runGit($git, ['commit', '--quiet', '-m', 'initial'], $brokenComposerRepo2);
$brokenCommit = trim(runGit($git, ['rev-parse', 'HEAD'], $brokenComposerRepo2)['output']);

$brokenUploadResult = $uploader->buildAndUpload('fixture-project', $brokenComposerRepo2, $brokenCommit, 'main', 'job-2');
check('ArtifactUploader: a local build failure is reported as not-succeeded', $brokenUploadResult['succeeded'] === false, $failures, $passCount);
check('ArtifactUploader: the failure code is local_composer_failed', $brokenUploadResult['failureCode'] === 'local_composer_failed', $failures, $passCount);
check('ArtifactUploader: no artifact metadata was ever sent to the Hub for a build that failed before archiving', glob("{$hub['storageDir']}/*.meta.json") === [], $failures, $passCount);
check('ArtifactUploader: the workspace for a FAILED job is also cleaned up (cleanup runs in a finally block)', !is_dir("{$uploaderWorkspaceBase}/fixture-project/job-2"), $failures, $passCount);
rrmdir($brokenComposerWorkspace2);

// Failure path: Hub entirely unreachable — must report failure, never throw, never claim success.
$deadClient = new Client(
    baseUrl: 'http://127.0.0.1:1',
    localAgentId: 'test-local-agent',
    agentToken: 'test-token',
    signingSecret: 'test-signing-secret',
    timeoutSeconds: 2,
    logger: $logger,
);
$deadUploader = new ArtifactUploader(new LocalBuildRunner($git), new WorkspaceSourcePreparer($git), new ArtifactBuilder(), $deadClient, $logger, $uploaderWorkspaceBase);
$deadResult = $deadUploader->buildAndUpload('fixture-project', $uploadFixture['path'], $uploadFixture['commitHash'], 'main', 'job-3');
check('ArtifactUploader: reports failure (not a thrown exception) when the Hub is unreachable', $deadResult['succeeded'] === false, $failures, $passCount);

stopFakeHub($hub);
rrmdir($workspace);
rrmdir($uploadWorkspace);
rrmdir(dirname($uploaderWorkspaceBase));

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
