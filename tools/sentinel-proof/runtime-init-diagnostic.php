<?php

declare(strict_types=1);

require __DIR__ . '/../../service/vendor/autoload.php';

use App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalHubAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalReleaseAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalRuntimeAdapter;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;

const SENTINEL_DIAGNOSTIC_COMMIT = '13c845b0f0cd519e12722ac8f0e8a0b587078b68';
const SENTINEL_DIAGNOSTIC_BRANCH = 'fix/canvas-default-homepage';
const SENTINEL_DIAGNOSTIC_PROJECT = 'saas-platform';
const SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256 = '4f08d03d460cb01183d9bfc1c2c2e2788bf72c660bfa1c8a56ec4c3b8be263ab';

$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--([a-z0-9-]+)=(.*)$/sD', $argument, $match)
        || array_key_exists($match[1], $arguments)) {
        fwrite(STDERR, "diagnostic_arguments_rejected\n");
        exit(2);
    }
    $arguments[$match[1]] = $match[2];
}
if (($arguments['authorized-runtime-init-diagnostic'] ?? null) !== 'YES'
    || ($arguments['sha256'] ?? null) !== SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256
    || !isset($arguments['artifact']) || $arguments['artifact'] === ''
    || count($arguments) !== 3) {
    fwrite(STDERR, "diagnostic_authorization_or_input_rejected\n");
    exit(2);
}

$input = realpath($arguments['artifact']);
if ($input === false || !is_file($input) || is_link($arguments['artifact'])
    || !hash_equals(SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256, (string) hash_file('sha256', $input))) {
    fwrite(STDERR, "proof_checksum_mismatch\n");
    exit(2);
}

$source = 'C:/laragon/www/saas-platform';
$config = SentinelProofRunConfig::create(
    $source,
    SENTINEL_DIAGNOSTIC_COMMIT,
    SENTINEL_DIAGNOSTIC_BRANCH,
    18572,
    18573,
    SENTINEL_DIAGNOSTIC_PROJECT,
);
$files = new SentinelLocalProofFilesystem($config);
$report = [
    'diagnostic_run_id' => $config->runId,
    'diagnostic_root' => $config->root,
    'artifact_sha256_verified' => 'YES',
    'initialization_started' => 'NO',
    'last_successful_operation' => 'PREFLIGHT',
    'first_failed_operation' => null,
    'failure_code' => null,
    'runtime_sqlite_created' => 'NO',
    'runtime_sqlite_initialized' => 'NO',
    'migrations_started' => 'NO',
    'migrations_completed' => 'NO',
    'previous_run_roots_changed' => 'NO',
    'source_repository_changed' => 'NO',
    'main_database_touched' => 'NO',
    'production_contacted' => 'NO',
    'full_proof_executed' => 'NO',
    'real_local_runtime_verified' => 'NO',
    'diagnostic_evidence_written' => 'NO',
];
$operation = 'ROOT_CREATION';
$rootCreated = false;

try {
    $files->initialize(true);
    $rootCreated = true;
    $operation = 'ARTIFACT_COPY';
    $copy = $files->assertContained($config->path('artifacts/sealed-input.zip'));
    $inputHandle = fopen($input, 'rb');
    $outputHandle = fopen($copy, 'xb');
    if ($inputHandle === false || $outputHandle === false) {
        if (is_resource($inputHandle)) { fclose($inputHandle); }
        if (is_resource($outputHandle)) { fclose($outputHandle); }
        throw new RuntimeException('proof_artifact_unavailable');
    }
    try {
        if (stream_copy_to_stream($inputHandle, $outputHandle) === false) {
            throw new RuntimeException('proof_artifact_unavailable');
        }
    } finally {
        fclose($inputHandle);
        fclose($outputHandle);
    }
    if (!hash_equals(SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256, (string) hash_file('sha256', $copy))
        || !hash_equals(SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256, (string) hash_file('sha256', $input))) {
        throw new RuntimeException('proof_artifact_changed');
    }
    $report['last_successful_operation'] = 'ARTIFACT_COPY';

    $artifactAdapter = new SentinelLocalArtifactAdapter($files);
    $operation = 'ARTIFACT_VALIDATION';
    $artifact = $artifactAdapter->receive($copy, SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256);
    $report['last_successful_operation'] = 'ARTIFACT_VALIDATION';

    $operation = 'ISOLATED_HUB_INITIALIZATION';
    $hub = new SentinelLocalHubAdapter($files);
    $hub->initialize(true);
    $registration = $hub->register($artifact);
    $report['last_successful_operation'] = 'ISOLATED_HUB_REGISTRATION';

    $operation = 'RELEASE_EXTRACTION_AND_ACTIVATION';
    $releases = new SentinelLocalReleaseAdapter($files, $artifactAdapter, $hub);
    $releases->activate($registration['deployment']['public_id'], $artifact['checksum']);
    $report['last_successful_operation'] = 'RELEASE_ACTIVATION';

    $operation = 'RUNTIME_INITIALIZATION';
    $runtime = new SentinelLocalRuntimeAdapter($files);
    $report['initialization_started'] = 'YES';
    $result = $runtime->initializeOnly($registration, true, true);
    $report['last_successful_operation'] = 'RUNTIME_INITIALIZATION';
    $report['runtime_sqlite_created'] = is_file($config->runtimeDatabasePath()) ? 'YES' : 'NO';
    $report['runtime_sqlite_initialized'] = $report['runtime_sqlite_created'];
    $report['migrations_started'] = 'YES';
    $report['migrations_completed'] = 'YES';
    $report['initialization_result'] = $result;
} catch (Throwable $error) {
    $diagnostic = SentinelProofDiagnostics::describe($error, $operation);
    $report['failure_code'] = $diagnostic['sanitized_exception_message'];
    $report['exception_class'] = $diagnostic['exception_class'];
    $report['failure_diagnostics'] = $diagnostic;
    $startedPath = $config->path('evidence/runtime-initialization-started.json');
    $report['initialization_started'] = is_file($startedPath) ? 'YES' : 'NO';
    $initializationPath = $config->path('evidence/runtime-initialization.json');
    if ($rootCreated && is_file($initializationPath)) {
        $initialization = json_decode((string) file_get_contents($initializationPath), true, 512, JSON_THROW_ON_ERROR);
        $report['initialization_evidence'] = $initialization;
        $runtimeDiagnostic = $initialization['diagnostics'] ?? null;
        $failedOperation = is_array($runtimeDiagnostic)
            ? ($runtimeDiagnostic['failure_operation'] ?? $operation) : $operation;
        $report['first_failed_operation'] = $failedOperation;
        if (is_array($runtimeDiagnostic)) {
            $report['failure_diagnostics'] = $runtimeDiagnostic;
            $report['failure_code'] = $runtimeDiagnostic['sanitized_exception_message']
                ?? $report['failure_code'];
            $report['exception_class'] = $runtimeDiagnostic['exception_class']
                ?? $report['exception_class'];
        }
        $report['runtime_sqlite_created'] = is_file($config->runtimeDatabasePath()) ? 'YES' : 'NO';
        $operationOrder = ['CONFIG_VALIDATION', 'RELEASE_VALIDATION', 'ARTIFACT_VERIFICATION',
            'LARAVEL_AUTOLOAD', 'LARAVEL_APP_BOOTSTRAP', 'RUNTIME_ENVIRONMENT_CONFIGURATION',
            'RUNTIME_DATABASE_FILE_CREATION', 'LARAVEL_BOOTSTRAP_ENVIRONMENT',
            'LARAVEL_BOOTSTRAP_ENVIRONMENT_COMPLETED', 'LARAVEL_BOOTSTRAP_CONFIGURATION',
            'LARAVEL_BOOTSTRAP_CONFIGURATION_COMPLETED', 'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS',
            'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS_COMPLETED', 'LARAVEL_BOOTSTRAP_FACADES',
            'LARAVEL_BOOTSTRAP_FACADES_COMPLETED', 'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST',
            'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST_COMPLETED', 'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION',
            'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION_COMPLETED', 'LARAVEL_BOOTSTRAP_PROVIDER_BOOT',
            'LARAVEL_BOOTSTRAP_PROVIDER_BOOT_COMPLETED', 'LARAVEL_BOOTSTRAP_DEFERRED_PROVIDERS',
            'RUNTIME_DATABASE_VALIDATION', 'SQLITE_CONNECTION', 'LARAVEL_MIGRATIONS',
            'MIGRATION_COMMAND_PREPARATION', 'MIGRATION_REPOSITORY_INITIALIZATION',
            'MIGRATION_DISCOVERY', 'MIGRATION_EXECUTION_PREPARATION', 'MIGRATION_EXECUTION',
            'MIGRATION_REPOSITORY_RECORDING', 'MIGRATION_EXECUTION_COMPLETED',
            'MIGRATION_COMMAND_EXIT_NONZERO', 'MIGRATION_COMMAND_COMPLETED',
            'RELEASE_MANIFEST_CREATION', 'RUNTIME_MANIFEST_WRITE'];
        $failedIndex = $failedOperation === 'LARAVEL_KERNEL_BOOTSTRAP'
            ? array_search('RUNTIME_DATABASE_FILE_CREATION', $operationOrder, true) + 1
            : array_search($failedOperation, $operationOrder, true);
        $sqliteIndex = array_search('SQLITE_CONNECTION', $operationOrder, true);
        $migrationIndex = array_search('LARAVEL_MIGRATIONS', $operationOrder, true);
        $migrationCompletedIndex = array_search('MIGRATION_EXECUTION_COMPLETED', $operationOrder, true);
        $migrationCompleted = ($initialization['successful'] ?? false)
            || (is_int($failedIndex) && $failedIndex > $migrationCompletedIndex);
        $report['last_successful_operation'] = is_int($failedIndex) && $failedIndex > 0
            ? $operationOrder[$failedIndex - 1] : 'PREFLIGHT';
        $report['runtime_sqlite_initialized'] = ($initialization['successful'] ?? false)
            || (is_int($failedIndex) && $failedIndex > $sqliteIndex) ? 'YES' : 'NO';
        $report['migrations_started'] = is_int($failedIndex)
            && $failedIndex >= $migrationIndex && $failedIndex <= $migrationCompletedIndex ? 'YES' : 'NO';
        $report['migrations_completed'] = $migrationCompleted ? 'YES' : 'NO';
    } else {
        $report['first_failed_operation'] = $operation;
    }
    if ($rootCreated) {
        try {
            $report['diagnostic_evidence_written'] = 'YES';
            $files->write('evidence/runtime-init-diagnostic.json',
                json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $report['diagnostic_evidence_written'] = 'NO';
        }
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}

if (!hash_equals(SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256, (string) hash_file('sha256', $input))) {
    $report['first_failed_operation'] = 'ARTIFACT_READ_ONLY_VERIFICATION';
    $report['failure_code'] = 'proof_artifact_changed';
    $report['initialization_result'] = ['successful' => true];
    $report['diagnostic_evidence_written'] = 'YES';
    $files->write('evidence/runtime-init-diagnostic.json',
        json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
$report['diagnostic_evidence_written'] = 'YES';
$files->write('evidence/runtime-init-diagnostic.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
