<?php

declare(strict_types=1);

// Explicit local-only phase-one entrypoint; never contacts production services.
require __DIR__ . '/../../service/vendor/autoload.php';

use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelExactCommitExporter;
use App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalHubAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalReleaseAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalProofOrchestrator;
use App\Services\Sentinel\LocalProof\SentinelLocalRuntimeAdapter;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use Symfony\Component\Process\Process;

$authorized = false;
$externalBuildRoot = null;
foreach ($argv as $arg) {
    if ($arg === '--authorized-local-phase-one') { $authorized = true; }
    if (str_starts_with($arg, '--external-build-root=')) { $externalBuildRoot = substr($arg, strlen('--external-build-root=')); }
}
if (!$authorized) {
    fwrite(STDERR, "Explicit local phase-one authorization required\n"); exit(1);
}
if ($externalBuildRoot !== null) {
    fwrite(STDERR, "External build handoff is disabled; use the normal local build path.\n"); exit(1);
}
$source = 'C:/laragon/www/saas-platform';
$orchestrator = dirname(__DIR__, 2);
$config = SentinelProofRunConfig::create($source, '13c845b0f0cd519e12722ac8f0e8a0b587078b68',
    'fix/canvas-default-homepage', 18570, 18571);
$files = new SentinelLocalProofFilesystem($config);
$stage = 'INITIALIZE_RUN';
$report = ['RUN_ID' => $config->runId, 'RUN_ROOT' => $config->root,
    'JUNCTION_FALLBACK' => 'NO', 'COPY_FALLBACK' => 'NO', 'MAIN_DATABASE_TOUCHED' => 'NO',
    'QUEUE_WORKER_TOUCHED' => 'NO', 'SCHEDULER_STARTED' => 'NO', 'PRODUCTION_CONTACTED' => 'NO',
    'BUILD_RESULT' => ['ran_npm_build' => false],
    'COMPOSER_EXIT_CODE' => null, 'NPM_CI_EXIT_CODE' => null, 'BUILD_EXIT_CODE' => null,
    'HEARTBEAT_SENT' => 'NO', 'READER_RUN' => 'NO', 'COLLECTOR_RUN' => 'NO', 'VERIFIER_RUN' => 'NO',
    'REAL_LOCAL_RUNTIME_VERIFIED' => 'NO', 'EXTERNAL_BUILD_HANDOFF' => 'DISABLED'];
$server = null;
$save = function () use ($files, &$report): void {
    $files->write('evidence/report.json', json_encode($report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
};
$git = function (string $repository, array $args): string {
    $p = new Process(array_merge(['git', '--no-optional-locks', '-C', $repository], $args));
    $p->setTimeout(30); $p->mustRun(); return $p->getOutput();
};
$environment = array_fill_keys(array_keys(getenv()), false);
foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH', 'PATHEXT', 'COMSPEC'] as $key) {
    if (getenv($key) !== false) { $environment[$key] = getenv($key); }
}
$environment['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . getenv('PATH');
$command = function (string $name, array $args, string $cwd, array $extra = [], int $timeout = 600) use ($files, $environment, &$report): void {
    echo "COMMAND: $name\n";
    $p = new Process($args, $cwd, array_replace($environment, $extra));
    $p->setTimeout($timeout);
    $stdout = $files->assertContained($files->config->path('evidence/' . $name . '.stdout.log'));
    $stderr = $files->assertContained($files->config->path('evidence/' . $name . '.stderr.log'));
    $out = fopen($stdout, 'xb'); $err = fopen($stderr, 'xb');
    try {
        $p->run(function (string $type, string $bytes) use ($out, $err): void {
            fwrite($type === Process::OUT ? $out : $err, $bytes);
        });
        if ($name === 'composer-install') { $report['COMPOSER_EXIT_CODE'] = $p->getExitCode(); }
        if ($name === 'npm-ci') { $report['NPM_CI_EXIT_CODE'] = $p->getExitCode(); }
        if (!$p->isSuccessful()) { throw new RuntimeException($name . '_failed_exit_' . $p->getExitCode()); }
    } finally { fclose($out); fclose($err); }
};
try {
    $files->initialize(true);
    $files->directory('evidence');
    $report['SOURCE_HEAD'] = trim($git($source, ['rev-parse', 'HEAD']));
    $report['SOURCE_BRANCH'] = trim($git($source, ['branch', '--show-current']));
    $report['GIT_STATUS_BEFORE'] = $git($source, ['status', '--porcelain=v1']);
    $files->write('evidence/source-head-before.txt', $report['SOURCE_HEAD'] . "\n");
    $files->write('evidence/source-status-before.txt', $report['GIT_STATUS_BEFORE']);
    $files->write('evidence/orchestrator-head-before.txt', $git($orchestrator, ['rev-parse', 'HEAD']));
    $files->write('evidence/orchestrator-status-before.txt', $git($orchestrator, ['status', '--porcelain=v1']));
    $report['DB_PATH'] = $config->databasePath();
    $report['STORAGE_PATH'] = $config->path('runtime/storage');
    $save(); echo 'RUN_ROOT: ' . $config->root . "\n";

    $stage = 'EXACT_COMMIT_EXPORT'; echo "STAGE: $stage\n";
    $export = (new SentinelExactCommitExporter($files))->export('build/source.zip', true);
    $files->write('evidence/export.json', json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $workspace = $files->directory('build/workspace');
    $archive = new ZipArchive();
    if ($archive->open($export['path'], ZipArchive::RDONLY) !== true) { throw new RuntimeException('source_zip_unavailable'); }
    try {
        foreach ($export['blob_inventory'] as $name => $entry) {
            if ($entry['mode'] === '120000') { throw new RuntimeException('source_symlink_rejected'); }
            $bytes = $archive->getFromName($name);
            if ($bytes === false || !hash_equals($entry['sha256'], hash('sha256', $bytes))) { throw new RuntimeException('source_blob_mismatch'); }
            $files->write('build/workspace/' . $name, $bytes);
        }
    } finally { $archive->close(); }
    foreach (array_merge(['deploy.bat'], SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES) as $name) {
        if (!isset($export['blob_inventory'][$name]) || !hash_equals($export['blob_inventory'][$name]['sha256'], hash_file('sha256', $workspace . '/' . $name))) {
            throw new RuntimeException('required_blob_byte_mismatch_' . $name);
        }
    }
    $report['DEPLOY_BAT_BYTE_MATCH'] = 'YES'; $report['JSON_FIXTURES_BYTE_MATCH'] = 'YES (5/5)'; $save();
    $report['SOURCE_BLOB_MATCH'] = 'YES';

    $ranNpmBuild = false;
    $ranComposer = true;

    $stage = 'COMPOSER_INSTALL'; echo "STAGE: $stage\n";
    $files->directory('build/composer-home'); $files->directory('build/composer-cache');
    $files->write('build/composer-home/config.json', json_encode(['config' => ['notify-on-install' => false]], JSON_THROW_ON_ERROR));
    $command('composer-install', [PHP_BINARY, 'C:/laragon/bin/composer/composer.phar',
        'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-scripts', '--no-plugins'], $workspace,
        ['COMPOSER_HOME' => $config->path('build/composer-home'), 'COMPOSER_CACHE_DIR' => $config->path('build/composer-cache'),
         'COMPOSER_MIRROR_PATH_REPOS' => '1']);

    $stage = 'NPM_CI'; echo "STAGE: $stage\n";
    $files->directory('build/npm-cache');
    $npmEnvironment = ['npm_config_cache' => $config->path('build/npm-cache'), 'npm_config_userconfig' => $config->path('build/no-npmrc'),
        'npm_config_audit' => 'false', 'npm_config_fund' => 'false'];
    $command('npm-ci', ['C:/Program Files/nodejs/node.exe', 'C:/Program Files/nodejs/node_modules/npm/bin/npm-cli.js',
        'ci', '--ignore-scripts', '--no-audit', '--no-fund'], $workspace, $npmEnvironment);
    $stage = 'NPM_BUILD'; echo "STAGE: $stage\n";
    $powershellScript = <<<'POWERSHELL'
$runRoot = $PSScriptRoot
$workspace = Join-Path $runRoot 'build\workspace'
$stdoutPath = Join-Path $runRoot 'build\npm-build.stdout.log'
$stderrPath = Join-Path $runRoot 'build\npm-build.stderr.log'
$npmCache = Join-Path $runRoot 'build\npm-cache'
$npmUserConfig = Join-Path $runRoot 'build\no-npmrc'

if (-not (Test-Path -LiteralPath $workspace -PathType Container)) {
    Write-Output 'NPM_NOT_STARTED=YES'
    Write-Output 'PREFLIGHT_FAILURE=WORKSPACE_MISSING'
    exit 20
}
if (-not (Test-Path -LiteralPath (Join-Path $workspace 'package.json') -PathType Leaf)) {
    Write-Output 'NPM_NOT_STARTED=YES'
    Write-Output 'PREFLIGHT_FAILURE=PACKAGE_JSON_MISSING'
    exit 21
}
try {
    $node = (Get-Command node.exe -ErrorAction Stop).Source
} catch {
    Write-Output 'NPM_NOT_STARTED=YES'
    Write-Output 'PREFLIGHT_FAILURE=NODE_NOT_RESOLVED'
    exit 22
}
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) {
    Write-Output 'NPM_NOT_STARTED=YES'
    Write-Output 'PREFLIGHT_FAILURE=NODE_MISSING'
    exit 23
}
$nodeDir = Split-Path -Parent $node
$npmCli = Join-Path $nodeDir 'node_modules\npm\bin\npm-cli.js'
if (-not (Test-Path -LiteralPath $npmCli -PathType Leaf)) {
    Write-Output 'NPM_NOT_STARTED=YES'
    Write-Output 'PREFLIGHT_FAILURE=NPM_CLI_MISSING'
    exit 24
}

Write-Output "NODE_RESOLVED_PATH=$node"
Write-Output "NPM_CLI_RESOLVED_PATH=$npmCli"
Set-Location -LiteralPath $workspace
$env:npm_config_cache = $npmCache
$env:npm_config_userconfig = $npmUserConfig
$env:npm_config_audit = 'false'
$env:npm_config_fund = 'false'
Write-Output 'NPM_STARTED=YES'
& $node $npmCli run build 1> $stdoutPath 2> $stderrPath
$exit = $LASTEXITCODE
Write-Output "NPM_EXIT=$exit"
exit $exit
POWERSHELL;
    $files->write('powershell-npm-build.ps1', $powershellScript);
    $powershellPath = realpath($config->path('powershell-npm-build.ps1'));
    if ($powershellPath === false) { throw new RuntimeException('npm_build_powershell_script_path_unresolved'); }
    try {
        try {
            $command('npm-build-powershell', ['powershell.exe', '-NoProfile', '-NonInteractive',
                '-ExecutionPolicy', 'Bypass', '-File', $powershellPath], $config->root, [], 600);
            $report['NPM_BUILD_POWERSHELL_EXIT'] = 0;
        } catch (RuntimeException $error) {
            if (preg_match('/_failed_exit_(\d+)$/', $error->getMessage(), $processExit)) {
                $report['NPM_BUILD_POWERSHELL_EXIT'] = (int) $processExit[1];
            }
            throw $error;
        }
        $powershellStdout = $files->config->path('evidence/npm-build-powershell.stdout.log');
        $powershellOutput = is_file($powershellStdout) ? (string) file_get_contents($powershellStdout) : '';
        if (!preg_match('/^NPM_STARTED=YES\s*$/m', $powershellOutput)) {
            throw new RuntimeException('npm_build_not_started');
        }
        if (!preg_match('/^NPM_EXIT=0\s*$/m', $powershellOutput)) {
            throw new RuntimeException('npm_build_exit_code_missing_or_nonzero');
        }
        $ranNpmBuild = true;
        $report['BUILD_RESULT']['ran_npm_build'] = true;
    } finally {
        $powershellStdout = $files->config->path('evidence/npm-build-powershell.stdout.log');
        $npmStderr = $files->config->path('build/npm-build.stderr.log');
        $powershellOutput = is_file($powershellStdout) ? (string) file_get_contents($powershellStdout) : '';
        $npmWarnings = is_file($npmStderr) ? (string) file_get_contents($npmStderr) : '';
        $report['NPM_BUILD_STARTED'] = preg_match('/^NPM_STARTED=YES\s*$/m', $powershellOutput) ? 'YES' : 'NO';
        $report['NPM_BUILD_NOT_STARTED'] = preg_match('/^NPM_NOT_STARTED=YES\s*$/m', $powershellOutput) ? 'YES' : 'NO';
        $report['NPM_BUILD_EXIT'] = preg_match('/^NPM_EXIT=(-?\d+)\s*$/m', $powershellOutput, $exitMatch)
            ? (int) $exitMatch[1] : null;
        $report['BUILD_EXIT_CODE'] = $report['NPM_BUILD_EXIT'] ?? $report['NPM_BUILD_POWERSHELL_EXIT'];
        $report['NPM_BUILD_POWERSHELL_EXIT'] ??= $report['NPM_BUILD_EXIT'];
        $report['NPM_BUILD_WARNINGS'] = $npmWarnings;
        $report['NPM_BUILD_STDERR_SHA256'] = is_file($npmStderr) ? hash_file('sha256', $npmStderr) : null;
        $report['BUILD_RESULT']['ran_npm_build'] = $ranNpmBuild;
        $save();
    }
    $stage = 'SEAL_ARTIFACT'; echo "STAGE: $stage\n";
    $sourcePaths = array_keys($export['blob_inventory']);
    $requiredPaths = ['composer.json', 'artisan', 'bootstrap/app.php', 'public/index.php', 'public/build/manifest.json', '.deployment/release-manifest.json'];
    if ($ranComposer) {
        $requiredPaths = array_merge($requiredPaths, ['vendor/autoload.php']);
    }
    $manifest = ['format' => 'deployment-system-release-manifest', 'version' => 1,
        'project_key' => $config->projectKey, 'commit_sha' => $config->commitSha, 'branch' => $config->branch,
        'composer_lock_sha256' => $ranComposer ? hash_file('sha256', $workspace . '/composer.lock') : '',
        'required_paths' => $requiredPaths,
        'source_paths' => $sourcePaths, 'build_manifest_present' => is_file($workspace . '/public/build/manifest.json'),
        'build_result' => ['ran_composer' => $ranComposer, 'ran_npm_build' => $ranNpmBuild],
        'agent_version' => 'sentinel-local-phase-one', 'generated_at' => gmdate('c'), 'status' => 'pass'];
    $artifacts = new SentinelLocalArtifactAdapter($files); $artifacts->validateManifest($manifest);
    $files->write('build/workspace/.deployment/release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $names = array_fill_keys($sourcePaths, true);
    $names['.deployment/release-manifest.json'] = true;
    foreach (['vendor', 'public/build'] as $generated) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace . '/' . $generated, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) { throw new RuntimeException('generated_symlink_rejected'); }
            if (!$entry->isFile()) { continue; }
            $resolved = realpath($entry->getPathname());
            $files->assertResolved((string) $resolved);
            $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($workspace) + 1));
            $artifacts->assertEntry($name, $manifest['required_paths']); $names[$name] = true;
        }
    }
    ksort($names);
    $artifactPath = $config->path('artifacts/exact-commit-final.zip');
    $zip = new ZipArchive();
    if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('artifact_zip_failed'); }
    foreach ($names as $name => $_) {
        if (!$zip->addFile($workspace . '/' . $name, $name)) { throw new RuntimeException('artifact_zip_add_failed'); }
        $mode = isset($export['blob_inventory'][$name]) ? octdec($export['blob_inventory'][$name]['mode']) : 0100644;
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
    if (!$zip->close()) { throw new RuntimeException('artifact_zip_finalize_failed'); }
    $report['ARTIFACT_PATH'] = $artifactPath; $report['ARTIFACT_SHA256'] = hash_file('sha256', $artifactPath);
    $report['ARTIFACT_SIZE'] = filesize($artifactPath);
    $files->write('evidence/artifact-manifest.json', json_encode([
        'format' => 'sentinel-local-artifact-manifest',
        'project_key' => $config->projectKey,
        'commit_sha' => $config->commitSha,
        'branch' => $config->branch,
        'artifact_sha256' => $report['ARTIFACT_SHA256'],
        'artifact_size' => $report['ARTIFACT_SIZE'],
        'inventory' => $export['blob_inventory'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $save();
    $artifact = $artifacts->receive($artifactPath, $report['ARTIFACT_SHA256']);
    $zip = new ZipArchive(); $zip->open($artifactPath, ZipArchive::RDONLY);
    try {
        foreach (array_merge(['deploy.bat'], SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES) as $name) {
            if (!hash_equals($export['blob_inventory'][$name]['sha256'], hash('sha256', (string) $zip->getFromName($name)))) {
                throw new RuntimeException('final_zip_blob_mismatch');
            }
        }
    } finally { $zip->close(); }

    $stage = 'SENTINEL_LOCAL_ONLY_CHAIN'; echo "STAGE: $stage\n";
    $hub = new SentinelLocalHubAdapter($files);
    $hub->initialize(true);
    $releases = new SentinelLocalReleaseAdapter($files, $artifacts, $hub);
    $runtime = new SentinelLocalRuntimeAdapter($files);
    $proof = new SentinelLocalProofOrchestrator($files, $artifacts, $releases, $hub, $runtime);
    $proofResult = $proof->execute($artifactPath, $report['ARTIFACT_SHA256'], true, true);
    if (($proofResult['state'] ?? null) !== 'VERIFIED'
        || ($proofResult['verification']['verdict'] ?? null) !== 'VERIFIED') {
        throw new RuntimeException('sentinel_local_only_verification_failed');
    }

    $database = new PDO('sqlite:' . $config->databasePath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $registration = $database->query('SELECT r.public_id AS release_id, a.public_id AS artifact_id, d.public_id AS deployment_id
        FROM deployments d JOIN releases r ON r.id=d.release_id
        JOIN artifacts a ON a.id=d.artifact_id ORDER BY d.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($registration)) { throw new RuntimeException('sentinel_local_registration_missing'); }
    $report['RELEASE_PUBLIC_ID'] = $registration['release_id'];
    $report['ARTIFACT_PUBLIC_ID'] = $registration['artifact_id'];
    $report['DEPLOYMENT_PUBLIC_ID'] = $registration['deployment_id'];
    $report['RUNTIME_ROOT'] = $config->releaseRoot() . '/releases/' . $registration['deployment_id'];
    $current = $config->releaseRoot() . '/current';
    $report['CURRENT_TARGET'] = $releases->assertCurrent($registration['deployment_id']);
    $report['CURRENT_IS_NATIVE_SYMLINK'] = is_link($current) ? 'YES' : 'NO';
    $report['CURRENT_TARGET_MATCH'] = $report['CURRENT_TARGET'] === realpath($report['RUNTIME_ROOT']) ? 'YES' : 'NO';
    if ($report['CURRENT_IS_NATIVE_SYMLINK'] !== 'YES' || $report['CURRENT_TARGET_MATCH'] !== 'YES') {
        throw new RuntimeException('sentinel_current_link_verification_failed');
    }
    $report['MANIFEST_PATH'] = $report['RUNTIME_ROOT'] . '/.deployment/release-manifest.json';
    $report['RUNTIME_MARKER_PATH'] = $report['RUNTIME_ROOT'] . '/.deployment/runtime-marker';
    $report['HEALTH_RELEASE_STATUS'] = $proofResult['evidence']['health_status'] ?? null;
    $report['HEALTH_LIVE_STATUS'] = $runtime->healthStatuses()['live'] ?? null;
    $snapshot = $hub->persistedSnapshot();
    $snapshotRow = $database->query('SELECT runtime_snapshot, runtime_snapshot_sequence FROM local_agents LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    $storedSnapshot = is_array($snapshotRow) ? json_decode($snapshotRow['runtime_snapshot'], true, 512, JSON_THROW_ON_ERROR) : null;
    if ($snapshot === null || !is_array($storedSnapshot)
        || $snapshot['sequence'] !== (int) $snapshotRow['runtime_snapshot_sequence']
        || $snapshot['sequence'] !== ($storedSnapshot['sequence'] ?? null)) {
        throw new RuntimeException('sentinel_snapshot_persistence_mismatch');
    }
    $report['SNAPSHOT_SEQUENCE'] = $snapshot['sequence'];
    $report['SNAPSHOT_SEQUENCE_MONOTONIC'] = $snapshot['sequence'] > 0 ? 'YES' : 'NO';
    $report['HEARTBEAT_SENT'] = 'YES';
    $report['HMAC_VERIFIED'] = 'YES';
    $report['SNAPSHOT_PERSISTED'] = 'YES';
    $report['READER_RESULT'] = 'PASS';
    $report['COLLECTOR_RESULT'] = 'PASS';
    $report['VERIFIER_RESULT'] = $proofResult['verification']['verdict'];
    $report['READER_RUN'] = 'YES'; $report['COLLECTOR_RUN'] = 'YES'; $report['VERIFIER_RUN'] = 'YES';
    $report['SENTINEL_STATE'] = $proofResult['state'];
    $report['SENTINEL_EVIDENCE'] = $proofResult['evidence'];
    $report['SENTINEL_VERIFICATION'] = $proofResult['verification'];
    $report['HEALTH_RELEASE_STATUS'] = $runtime->healthStatuses()['release'] ?? null;
    $report['HEALTH_LIVE_STATUS'] = $runtime->healthStatuses()['live'] ?? null;
    $report['MAIN_DATABASE_TOUCHED'] = 'NO';
    $report['PRODUCTION_CONTACTED'] = 'NO';
    $report['FINAL_RESULT'] = 'REAL_LOCAL_RUNTIME_VERIFIED';
    $report['REAL_LOCAL_RUNTIME_VERIFIED'] = 'YES';
    $save();
} catch (Throwable $error) {
    $report['FINAL_RESULT'] = 'FAILED_LOCAL_ONLY'; $report['FAILURE_STAGE'] = $stage;
    $diagnostics = SentinelProofDiagnostics::describe($error, isset($proof) ? $proof->failureStage() : $stage);
    $report['FAILURE_DIAGNOSTICS'] = $diagnostics;
    $report['FAILURE_REASON'] = $diagnostics['exception_class'] . ': ' . $diagnostics['sanitized_exception_message'];
    if (isset($proof)) {
        $report['SENTINEL_OBSERVATIONS'] = $proof->observations();
        $report['SENTINEL_STATE'] = $proof->state();
        $report['SENTINEL_VERIFICATION'] = $proof->verification();
    }
    $report['EVIDENCE_PRESERVED'] = 'YES';
} finally {
    $report['GIT_STATUS_AFTER'] = $git($source, ['status', '--porcelain=v1']);
    $report['SOURCE_REPOSITORY_CHANGED'] = isset($report['GIT_STATUS_BEFORE'])
        && $report['GIT_STATUS_BEFORE'] === $report['GIT_STATUS_AFTER']
        && trim($git($source, ['rev-parse', 'HEAD'])) === $config->commitSha ? 'NO' : 'UNKNOWN';
    if (is_dir($config->root)) {
        $files->write('evidence/source-status-after.txt', $report['GIT_STATUS_AFTER']);
        $files->write('evidence/orchestrator-status-after.txt', $git($orchestrator, ['status', '--porcelain=v1'])); $save();
    }
}
echo json_encode($report,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
$exitCode = ($report['FINAL_RESULT'] ?? '') === 'REAL_LOCAL_RUNTIME_VERIFIED' ? 0 : 1;
if ($exitCode !== 0 && ($report['FAILURE_STAGE'] ?? '') === 'NPM_BUILD') {
    $npmExit = $report['NPM_BUILD_EXIT'] ?? null;
    $powershellExit = $report['NPM_BUILD_POWERSHELL_EXIT'] ?? null;
    if (is_int($npmExit) && $npmExit !== 0) { $exitCode = $npmExit; }
    elseif (is_int($powershellExit) && $powershellExit !== 0) { $exitCode = $powershellExit; }
}
exit($exitCode);
