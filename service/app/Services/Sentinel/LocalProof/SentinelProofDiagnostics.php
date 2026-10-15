<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use Throwable;

/** No trace arguments, SQL bindings, raw messages, credentials or absolute paths. */
final class SentinelProofDiagnostics
{
    public static function describeRuntime(
        Throwable $error,
        string $operation,
        ?string $migrationIdentifier = null,
    ): array
    {
        $operations = [
            'CONFIG_VALIDATION',
            'RELEASE_VALIDATION',
            'ARTIFACT_VERIFICATION',
            'LARAVEL_AUTOLOAD',
            'LARAVEL_APP_BOOTSTRAP',
            'RUNTIME_ENVIRONMENT_CONFIGURATION',
            'RUNTIME_DATABASE_FILE_CREATION',
            'LARAVEL_KERNEL_BOOTSTRAP',
            'LARAVEL_BOOTSTRAP_ENVIRONMENT',
            'LARAVEL_BOOTSTRAP_ENVIRONMENT_COMPLETED',
            'LARAVEL_BOOTSTRAP_CONFIGURATION',
            'LARAVEL_BOOTSTRAP_CONFIGURATION_COMPLETED',
            'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS',
            'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS_COMPLETED',
            'LARAVEL_BOOTSTRAP_FACADES',
            'LARAVEL_BOOTSTRAP_FACADES_COMPLETED',
            'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST',
            'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST_COMPLETED',
            'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION',
            'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION_COMPLETED',
            'LARAVEL_BOOTSTRAP_PROVIDER_BOOT',
            'LARAVEL_BOOTSTRAP_PROVIDER_BOOT_COMPLETED',
            'LARAVEL_BOOTSTRAP_DEFERRED_PROVIDERS',
            'RUNTIME_DATABASE_VALIDATION',
            'SQLITE_CONNECTION',
            'LARAVEL_MIGRATIONS',
            'MIGRATION_COMMAND_PREPARATION',
            'MIGRATION_REPOSITORY_INITIALIZATION',
            'MIGRATION_DISCOVERY',
            'MIGRATION_EXECUTION_PREPARATION',
            'MIGRATION_EXECUTION',
            'MIGRATION_REPOSITORY_RECORDING',
            'MIGRATION_EXECUTION_COMPLETED',
            'MIGRATION_COMMAND_COMPLETED',
            'MIGRATION_COMMAND_EXIT_NONZERO',
            'RELEASE_MANIFEST_CREATION',
            'RUNTIME_MANIFEST_WRITE',
            'HTTP_KERNEL_BOOTSTRAP',
            'HTTP_REQUEST_HANDLING',
        ];
        $diagnostics = self::describe($error, 'RUNTIME_BOOTSTRAP_OR_MIGRATION')
            + ['failure_operation' => in_array($operation, $operations, true) ? $operation : 'UNKNOWN'];
        if ($diagnostics['failure_operation'] === 'MIGRATION_EXECUTION'
            && is_string($migrationIdentifier)
            && preg_match('/^[a-f0-9]{64}$/D', $migrationIdentifier) === 1) {
            $diagnostics['failure_migration_id'] = $migrationIdentifier;
        }
        return $diagnostics;
    }

    public static function describe(Throwable $error, string $stage): array
    {
        $chain = [];
        $root = $error;
        $gitFailure = null;
        $failureCode = null;
        for ($current = $error, $depth = 0; $current !== null && $depth < 16; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof SentinelProofFailure) { $stage = $current->failureStage; }
            if ($current instanceof SentinelGitFailure) {
                $gitFailure = $current;
                $failureCode ??= $current->getMessage();
            } elseif ($failureCode === null) {
                $candidate = self::entry($current)['sanitized_exception_message'];
                if ($candidate !== '[REDACTED: unrecognized exception message]'
                    && !in_array($candidate, ['proof_execution_failed', 'proof_step_failed'], true)) {
                    $failureCode = $candidate;
                }
            }
            $root = $current;
            if ($depth > 0) { $chain[] = self::entry($current); }
        }
        $report = ['failure_stage' => $stage] + self::entry($root)
            + ['previous_exception_chain' => $chain, 'exception_chain' => $chain];
        if ($gitFailure !== null) {
            $report = array_merge($report, [
                'failure_code' => $failureCode ?? $gitFailure->getMessage(),
                'git_failure_code' => $gitFailure->getMessage(),
                'git_operation' => $gitFailure->gitMetadata['git_operation'] ?? null,
                'blob_oid' => $gitFailure->gitMetadata['blob_oid'] ?? null,
                'repository_relative_path' => $gitFailure->gitMetadata['repository_relative_path'] ?? null,
                'git_exit_code' => $gitFailure->gitMetadata['git_exit_code'] ?? null,
                'git_timeout' => $gitFailure->gitMetadata['git_timeout'] ?? false,
                'stderr_classification' => $gitFailure->gitMetadata['stderr_classification'] ?? 'UNKNOWN_GIT_FAILURE',
                'stderr_present' => $gitFailure->gitMetadata['stderr_present'] ?? false,
            ]);
            $report['sanitized_exception_message'] = $report['failure_code'];
        } else {
            $report['failure_code'] = $report['sanitized_exception_message'];
        }
        return $report;
    }

    private static function entry(Throwable $error): array
    {
        $file = str_replace('\\', '/', $error->getFile());
        $repository = str_replace('\\', '/', dirname(__DIR__, 5));
        $safeFile = '[REDACTED]';
        if (str_starts_with($file, $repository . '/')) {
            $relative = substr($file, strlen($repository) + 1);
            if (preg_match('~^(service/(app|vendor|tests)/|tools/sentinel-proof/|local-agent/lib/)[A-Za-z0-9_./-]+\.php$~D', $relative)
                && !preg_match('~(^|/)(credentials[^/]*|auth\.json|\.env[^/]*)(/|$)~i', $relative)) { $safeFile = $relative; }
        }
        // Only controlled failure codes; arbitrary exception text is denied by default.
        $message = $error->getMessage();
        $codes = ['proof_execution_failed', 'proof_step_failed', 'proof_observation_unavailable',
            'proof_source_changed', 'proof_source_git_failed', 'proof_source_blob_unavailable',
            'proof_source_inventory_mismatch', 'proof_source_fixture_outside_root',
            'proof_artifact_entry_rejected', 'proof_zip_symlink', 'proof_untracked_content',
            'proof_live_worktree_content', 'proof_checksum_mismatch', 'proof_lock_mismatch',
            'proof_committed_source_missing', 'proof_zip_size_or_duplicate', 'proof_manifest_mismatch',
            'proof_registration_mismatch', 'proof_runtime_initialization_failed',
            'proof_runtime_initialization_result_invalid',
            'proof_symlink_failed', 'proof_symlink_rejected', 'proof_loopback_connection_failed',
            'proof_source_mode_rejected', 'proof_source_path_missing', 'proof_source_empty',
            'proof_source_blob_mismatch', 'proof_source_symlink_escape', 'proof_source_symlink_or_submodule',
            'proof_source_zip_mismatch', 'proof_artifact_changed', 'proof_artifact_unavailable',
            'proof_config_rejected', 'proof_configuration_rejected', 'proof_current_already_exists',
            'proof_current_mismatch', 'proof_current_rejected', 'proof_database_already_exists',
            'proof_database_missing', 'proof_deployment_missing', 'proof_deployment_rejected',
            'proof_directory_failed', 'proof_execution_not_authorized', 'proof_expectation_missing',
            'proof_export_already_exists', 'proof_health_path_rejected', 'proof_identity_unavailable',
            'proof_manifest_incomplete', 'proof_manifest_npm_build_required', 'proof_method_rejected',
            'proof_native_symlink_executor_unavailable', 'proof_parent_redirected', 'proof_path_escape',
            'proof_path_rejected', 'proof_path_unresolved', 'proof_process_start_failed',
            'proof_release_already_populated', 'proof_release_record_mismatch', 'proof_required_path_missing',
            'proof_root_inside_repository', 'proof_run_already_exists', 'proof_run_already_started',
            'proof_run_metadata_rejected', 'proof_run_root_rejected', 'proof_runtime_artifact_rejected',
            'proof_runtime_attribution_mismatch', 'proof_runtime_content_changed', 'proof_runtime_database_failed',
            'proof_runtime_database_mismatch', 'proof_runtime_identity_rejected', 'proof_runtime_incomplete',
            'proof_runtime_manifest_failed', 'proof_runtime_marker_mismatch', 'proof_runtime_schema_failed',
            'proof_runtime_zip_path_rejected', 'proof_runtime_zip_rejected', 'proof_state_prerequisite_missing',
            'proof_symlink_activation_failed', 'proof_symlink_containment_rejected', 'proof_symlink_escape',
            'proof_symlink_path_rejected', 'proof_symlink_verification_failed', 'proof_url_rejected',
            'proof_vendor_not_declared', 'proof_write_failed', 'proof_zip_finalize_failed', 'proof_zip_invalid',
            'proof_zip_write_failed', 'A facade root has not been set.'];
        $safeMessage = in_array($message, $codes, true) ? $message : '[REDACTED: unrecognized exception message]';
        // Keep database error category without SQL, connection names, paths or bindings.
        if (preg_match('/^SQLSTATE\[([A-Z0-9]{5})\]/', $message, $match)) { $safeMessage = 'SQLSTATE[' . $match[1] . '] [details redacted]'; }
        $entry = ['exception_class' => get_class($error), 'sanitized_exception_message' => $safeMessage,
            'exception_origin' => $safeFile . ':' . $error->getLine(),
            'exception_file' => $safeFile, 'exception_line' => $error->getLine()];
        if ($error instanceof SentinelGitFailure) {
            $entry['git_failure'] = [
                'failure_code' => $error->getMessage(),
                'git_operation' => $error->gitMetadata['git_operation'] ?? null,
                'blob_oid' => $error->gitMetadata['blob_oid'] ?? null,
                'repository_relative_path' => $error->gitMetadata['repository_relative_path'] ?? null,
                'git_exit_code' => $error->gitMetadata['git_exit_code'] ?? null,
                'git_timeout' => $error->gitMetadata['git_timeout'] ?? false,
                'stderr_classification' => $error->gitMetadata['stderr_classification'] ?? 'UNKNOWN_GIT_FAILURE',
                'stderr_present' => $error->gitMetadata['stderr_present'] ?? false,
            ];
        }
        return $entry;
    }
}
