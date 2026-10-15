<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use App\Services\Sentinel\LocalProof\SentinelProofFailure;
use App\Services\Sentinel\LocalProof\SentinelGitFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SentinelProofDiagnosticsTest extends TestCase
{
    public function test_nested_wrappers_preserve_origin_and_specific_failure_stage_without_secret_text(): void
    {
        $root = new RuntimeException('Bearer fixture-token password=fixture-secret C:/private/customer/database.sqlite');
        $step = new SentinelProofFailure('REGISTER_PROJECT', $root);
        $outer = new RuntimeException('proof_execution_failed', 0, new SentinelProofFailure('RELEASE_REGISTERED', $step));
        $report = SentinelProofDiagnostics::describe($outer, 'SENTINEL_LOCAL_ONLY_CHAIN');
        self::assertSame('REGISTER_PROJECT', $report['failure_stage']);
        self::assertSame($root->getLine(), $report['exception_line']);
        self::assertSame('service/tests/Unit/Services/Sentinel/SentinelProofDiagnosticsTest.php', $report['exception_file']);
        self::assertCount(3, $report['previous_exception_chain']);
        $bytes = json_encode($report);
        foreach (['fixture-token', 'fixture-secret', 'C:/private', 'Bearer', 'password='] as $secret) {
            self::assertStringNotContainsString($secret, $bytes);
        }
    }

    public function test_database_bindings_and_connection_details_are_redacted_but_category_is_retained(): void
    {
        $error = new RuntimeException('SQLSTATE[HY000]: no such column: secret (Connection: private-host, SQL: token=private-value)');
        $report = SentinelProofDiagnostics::describe($error, 'REGISTER_PROJECT');
        self::assertSame('SQLSTATE[HY000] [details redacted]', $report['sanitized_exception_message']);
        self::assertStringNotContainsString('private', json_encode($report));
    }

    public function test_controlled_failure_codes_remain_visible(): void
    {
        self::assertSame('proof_live_worktree_content', SentinelProofDiagnostics::describe(
            new RuntimeException('proof_live_worktree_content'), 'ARTIFACT_VERIFIED')['sanitized_exception_message']);
    }

    public function test_runtime_failure_operation_is_controlled_and_exception_text_stays_redacted(): void
    {
        $error = new RuntimeException('fixture-secret C:/private/database.sqlite');
        $report = SentinelProofDiagnostics::describeRuntime($error, 'LARAVEL_KERNEL_BOOTSTRAP');
        self::assertSame('LARAVEL_KERNEL_BOOTSTRAP', $report['failure_operation']);
        self::assertSame('[REDACTED: unrecognized exception message]', $report['sanitized_exception_message']);
        self::assertStringNotContainsString('fixture-secret', json_encode($report));
        self::assertStringNotContainsString('C:/private', json_encode($report));
    }

    public function test_unknown_runtime_failure_operation_is_not_echoed(): void
    {
        $report = SentinelProofDiagnostics::describeRuntime(new RuntimeException('safe'), 'secret-operation');
        self::assertSame('UNKNOWN', $report['failure_operation']);
        self::assertStringNotContainsString('secret-operation', json_encode($report));
    }

    public function test_non_runtime_failure_stage_is_preserved_by_generic_diagnostics(): void
    {
        $report = SentinelProofDiagnostics::describe(
            new RuntimeException('proof_source_blob_unavailable'),
            'RELEASE_EXTRACTION_AND_ACTIVATION',
        );
        self::assertSame('RELEASE_EXTRACTION_AND_ACTIVATION', $report['failure_stage']);
        self::assertSame('proof_source_blob_unavailable', $report['sanitized_exception_message']);
        self::assertArrayNotHasKey('failure_operation', $report);
    }

    public function test_sqlite_connection_failure_operation_is_controlled(): void
    {
        $report = SentinelProofDiagnostics::describeRuntime(new RuntimeException('private details'), 'SQLITE_CONNECTION');
        self::assertSame('SQLITE_CONNECTION', $report['failure_operation']);
        self::assertSame('[REDACTED: unrecognized exception message]', $report['sanitized_exception_message']);
    }

    public function test_migration_failure_markers_are_allowlisted_and_migration_identity_is_hashed(): void
    {
        $operations = [
            'MIGRATION_COMMAND_PREPARATION',
            'MIGRATION_REPOSITORY_INITIALIZATION',
            'MIGRATION_DISCOVERY',
            'MIGRATION_EXECUTION_PREPARATION',
            'MIGRATION_EXECUTION',
            'MIGRATION_REPOSITORY_RECORDING',
            'MIGRATION_EXECUTION_COMPLETED',
            'MIGRATION_COMMAND_EXIT_NONZERO',
            'MIGRATION_COMMAND_COMPLETED',
        ];
        foreach ($operations as $operation) {
            $report = SentinelProofDiagnostics::describeRuntime(
                new RuntimeException('private SQL and C:/sensitive/database.sqlite'),
                $operation,
            );
            self::assertSame($operation, $report['failure_operation']);
            self::assertSame('[REDACTED: unrecognized exception message]',
                $report['sanitized_exception_message']);
        }

        $migrationName = '2031_02_03_040506_fixture_private_migration';
        $report = SentinelProofDiagnostics::describeRuntime(
            new RuntimeException('private SQL and C:/sensitive/database.sqlite'),
            'MIGRATION_EXECUTION',
            hash('sha256', $migrationName),
        );
        self::assertSame(hash('sha256', $migrationName), $report['failure_migration_id']);
        self::assertStringNotContainsString($migrationName, json_encode($report, JSON_THROW_ON_ERROR));

        $invalidIdentifier = SentinelProofDiagnostics::describeRuntime(
            new RuntimeException('private SQL'),
            'MIGRATION_EXECUTION',
            'sensitive-migration-name',
        );
        self::assertArrayNotHasKey('failure_migration_id', $invalidIdentifier);
        self::assertArrayNotHasKey('failure_migration_id', SentinelProofDiagnostics::describeRuntime(
            new RuntimeException('private SQL'),
            'MIGRATION_DISCOVERY',
            hash('sha256', $migrationName),
        ));
    }

    public function test_laravel_bootstrapper_markers_are_allowlisted_without_exposing_exception_text(): void
    {
        $operations = [
            'LARAVEL_BOOTSTRAP_ENVIRONMENT',
            'LARAVEL_BOOTSTRAP_CONFIGURATION',
            'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS',
            'LARAVEL_BOOTSTRAP_FACADES',
            'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST',
            'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION',
            'LARAVEL_BOOTSTRAP_PROVIDER_BOOT',
            'LARAVEL_BOOTSTRAP_DEFERRED_PROVIDERS',
        ];
        foreach ($operations as $operation) {
            $report = SentinelProofDiagnostics::describeRuntime(
                new RuntimeException('secret token private-path'),
                $operation,
            );
            self::assertSame($operation, $report['failure_operation']);
            self::assertSame('[REDACTED: unrecognized exception message]',
                $report['sanitized_exception_message']);
            $serialized = json_encode($report, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret', $serialized);
            self::assertStringNotContainsString('private-path', $serialized);
        }
    }

    public function test_structured_git_context_and_exception_chain_survive_sanitization(): void
    {
        $git = new SentinelGitFailure('proof_source_git_failed', [
            'git_operation' => 'cat_file_blob',
            'blob_oid' => str_repeat('a', 40),
            'repository_relative_path' => 'src/Example.php',
            'git_exit_code' => 128,
            'git_timeout' => false,
            'stderr_classification' => 'GIT_OBJECT_MISSING',
            'stderr_present' => true,
        ], new RuntimeException('token=private-value C:/private/repository'));
        $blob = new RuntimeException('proof_source_blob_unavailable', 0, $git);
        $step = new SentinelProofFailure('SOURCE_VERIFIED', $blob);
        $report = SentinelProofDiagnostics::describe(
            new RuntimeException('proof_execution_failed', 0, $step),
            'SENTINEL_LOCAL_ONLY_CHAIN',
        );

        self::assertSame('SOURCE_VERIFIED', $report['failure_stage']);
        self::assertSame('proof_source_blob_unavailable', $report['failure_code']);
        self::assertSame('proof_source_git_failed', $report['git_failure_code']);
        self::assertSame('cat_file_blob', $report['git_operation']);
        self::assertSame(str_repeat('a', 40), $report['blob_oid']);
        self::assertSame('src/Example.php', $report['repository_relative_path']);
        self::assertSame(128, $report['git_exit_code']);
        self::assertFalse($report['git_timeout']);
        self::assertSame('GIT_OBJECT_MISSING', $report['stderr_classification']);
        self::assertSame(4, count($report['exception_chain']));
        self::assertSame($report['previous_exception_chain'], $report['exception_chain']);

        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private-value', 'C:/private', 'token='] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_sensitive_repository_paths_are_redacted(): void
    {
        $error = new SentinelGitFailure('proof_source_git_failed', [
            'git_operation' => 'cat_file_blob',
            'blob_oid' => str_repeat('b', 40),
            'repository_relative_path' => '[REDACTED]',
            'git_exit_code' => 128,
            'git_timeout' => false,
            'stderr_classification' => 'GIT_PROCESS_FAILED',
            'stderr_present' => true,
        ]);
        $report = SentinelProofDiagnostics::describe($error, 'SOURCE_VERIFIED');
        self::assertSame('[REDACTED]', $report['repository_relative_path']);
        self::assertStringNotContainsString('secrets/prod.json', json_encode($report));
    }
}
