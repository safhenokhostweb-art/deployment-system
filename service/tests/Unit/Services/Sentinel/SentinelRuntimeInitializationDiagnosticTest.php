<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalRuntimeAdapter;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\WindowsNativeSymlinkCreator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SentinelRuntimeInitializationDiagnosticTest extends TestCase
{
    private SentinelProofRunConfig $config;
    private SentinelLocalProofFilesystem $files;

    protected function setUp(): void
    {
        $this->config = SentinelProofRunConfig::create(
            dirname(__DIR__, 5), str_repeat('a', 40), 'fixture-branch', 18572, 18573, 'fixture-project',
        );
        $this->files = new SentinelLocalProofFilesystem($this->config);
    }

    protected function tearDown(): void
    {
        $current = $this->config->path('runtime/application/current');
        if (is_link($current)) {
            if (PHP_OS_FAMILY === 'Windows') { rmdir($current); }
            else { unlink($current); }
        }
        if (is_dir($this->config->root)) {
            $this->files->cleanup(true);
        }
    }

    private function runtimeFixture(?\Closure $runner = null): array
    {
        $this->files->initialize(true);
        $artifact = $this->files->write('artifacts/fixture.zip', 'sealed-test-input');
        $deployment = 'fixture-deployment';
        $release = $this->files->directory('runtime/application/releases/' . $deployment);
        $this->files->write('runtime/application/releases/' . $deployment . '/vendor/autoload.php', '<?php');
        $this->files->write('runtime/application/releases/' . $deployment . '/bootstrap/app.php', '<?php');
        $current = $this->config->path('runtime/application/current');
        (new WindowsNativeSymlinkCreator())->create($current, $release, $this->config->root);
        return [
            new SentinelLocalRuntimeAdapter($this->files, $runner),
            ['deployment' => ['public_id' => $deployment, 'commit_hash' => $this->config->commitSha],
                'project' => ['environment' => 'local'],
                'artifact' => ['disk_path' => $artifact, 'checksum_sha256' => hash_file('sha256', $artifact)]],
        ];
    }

    public function test_diagnostic_entry_point_is_explicit_and_never_enters_proof_stages(): void
    {
        $path = dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-init-diagnostic.php';
        self::assertFileExists($path);
        $entry = file_get_contents($path);
        self::assertStringContainsString('authorized-runtime-init-diagnostic', $entry);
        self::assertStringContainsString('SENTINEL_DIAGNOSTIC_ARTIFACT_SHA256', $entry);
        self::assertStringContainsString('4f08d03d460cb01183d9bfc1c2c2e2788bf72c660bfa1c8a56ec4c3b8be263ab', $entry);
        self::assertStringContainsString('$artifactAdapter->receive(', $entry);
        self::assertStringContainsString('$hub->register(', $entry);
        self::assertStringContainsString('$releases->activate(', $entry);
        self::assertStringContainsString('$runtime->initializeOnly(', $entry);
        self::assertStringContainsString('SentinelProofDiagnostics::describe($error, $operation)', $entry);
        self::assertStringNotContainsString('SentinelProofDiagnostics::describeRuntime($error, $operation)', $entry);
        foreach (['->start(', '->awaitHealth(', '->request(', '->probe(', '->persistedSnapshot(',
            'SentinelEvidenceCollector', 'SentinelVerifier', '->execute('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $entry);
        }
    }

    private function runDiagnosticEntry(array $arguments): array
    {
        $entry = dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-init-diagnostic.php';
        $command = array_merge([PHP_BINARY, $entry], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function diagnosticArguments(string $artifact): array
    {
        return [
            '--authorized-runtime-init-diagnostic=YES',
            '--sha256=4f08d03d460cb01183d9bfc1c2c2e2788bf72c660bfa1c8a56ec4c3b8be263ab',
            '--artifact=' . $artifact,
        ];
    }

    public function test_diagnostic_cli_accepts_sha256_option_name_without_creating_a_run(): void
    {
        $result = $this->runDiagnosticEntry($this->diagnosticArguments(
            sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip',
        ));
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('proof_checksum_mismatch', $result['stderr']);
        self::assertStringNotContainsString('diagnostic_arguments_rejected', $result['stderr']);
    }

    public function test_diagnostic_cli_accepts_authorization_option_without_creating_a_run(): void
    {
        $arguments = $this->diagnosticArguments(sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip');
        $result = $this->runDiagnosticEntry($arguments);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('proof_checksum_mismatch', $result['stderr']);
    }

    public function test_diagnostic_cli_accepts_an_existing_artifact_path_before_checksum_validation(): void
    {
        $artifact = tempnam(sys_get_temp_dir(), 'sentinel-diagnostic-');
        self::assertNotFalse($artifact);
        try {
            $result = $this->runDiagnosticEntry($this->diagnosticArguments($artifact));
            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('proof_checksum_mismatch', $result['stderr']);
            self::assertStringNotContainsString('diagnostic_arguments_rejected', $result['stderr']);
        } finally {
            unlink($artifact);
        }
    }

    public function test_diagnostic_cli_rejects_unknown_options(): void
    {
        $arguments = $this->diagnosticArguments(sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip');
        $arguments[] = '--unexpected=value';
        $result = $this->runDiagnosticEntry($arguments);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('diagnostic_authorization_or_input_rejected', $result['stderr']);
    }

    public function test_diagnostic_cli_rejects_duplicate_options(): void
    {
        $arguments = $this->diagnosticArguments(sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip');
        $arguments[] = '--sha256=4f08d03d460cb01183d9bfc1c2c2e2788bf72c660bfa1c8a56ec4c3b8be263ab';
        $result = $this->runDiagnosticEntry($arguments);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('diagnostic_arguments_rejected', $result['stderr']);
    }

    public function test_diagnostic_cli_rejects_missing_required_options(): void
    {
        $arguments = $this->diagnosticArguments(sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip');
        array_shift($arguments);
        $result = $this->runDiagnosticEntry($arguments);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('diagnostic_authorization_or_input_rejected', $result['stderr']);
    }

    public function test_diagnostic_cli_rejects_malformed_arguments(): void
    {
        $arguments = $this->diagnosticArguments(sys_get_temp_dir() . '/sentinel-diagnostic-missing-artifact.zip');
        $arguments[1] = '--sha256';
        $result = $this->runDiagnosticEntry($arguments);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('diagnostic_arguments_rejected', $result['stderr']);
    }

    public function test_config_creation_assigns_unique_uncreated_diagnostic_roots(): void
    {
        $other = SentinelProofRunConfig::create(
            dirname(__DIR__, 5), str_repeat('a', 40), 'fixture-branch', 18574, 18575, 'fixture-project',
        );
        self::assertNotSame($this->config->runId, $other->runId);
        self::assertNotSame($this->config->root, $other->root);
        self::assertFalse(file_exists($this->config->root));
        self::assertFalse(file_exists($other->root));
    }

    public function test_artifact_checksum_mismatch_is_rejected_before_artifact_processing(): void
    {
        $this->files->initialize(true);
        $artifact = $this->files->write('artifacts/fixture.zip', 'not-the-expected-bytes');
        try {
            (new \App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter($this->files))
                ->receive($artifact, hash('sha256', 'different'));
            self::fail('Checksum mismatch was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_checksum_mismatch', $error->getMessage());
        }
    }

    public function test_existing_diagnostic_root_is_rejected(): void
    {
        $this->files->initialize(true);
        try {
            $this->files->initialize(true);
            self::fail('Existing root was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_run_already_exists', $error->getMessage());
        }
    }

    public function test_runtime_initializer_is_invoked_exactly_once_with_isolated_database(): void
    {
        $calls = 0;
        [$runtime, $registration] = $this->runtimeFixture(function (array $command, string $cwd, array $environment) use (&$calls): array {
            $calls++;
            self::assertSame('--initialize-runtime', $command[2]);
            self::assertSame($this->config->runtimeDatabasePath(), $environment['DB_DATABASE']);
            self::assertSame($this->config->path('run.json'), $environment['SENTINEL_PROOF_CONFIG']);
            return ['exit_code' => 0, 'successful' => true];
        });
        $result = $runtime->initializeOnly($registration, true, true);
        self::assertSame(1, $calls);
        self::assertTrue($result['successful']);
        self::assertSame($this->config->databasePath(), $this->config->path('hub/database.sqlite'));
        self::assertNotSame($this->config->databasePath(), $this->config->runtimeDatabasePath());
    }

    public function test_initialize_only_does_not_start_runtime_or_hub_servers(): void
    {
        [$runtime, $registration] = $this->runtimeFixture(
            fn (): array => ['exit_code' => 0, 'successful' => true],
        );
        $runtime->initializeOnly($registration, true, true);
        foreach (['runtime', 'hub'] as $property) {
            $reflection = new \ReflectionProperty($runtime, $property);
            self::assertNull($reflection->getValue($runtime));
        }
    }

    public function test_initialize_only_does_not_invoke_health_or_heartbeat_requests(): void
    {
        $calls = 0;
        [$runtime, $registration] = $this->runtimeFixture(function () use (&$calls): array {
            $calls++;
            return ['exit_code' => 0, 'successful' => true];
        });
        $runtime->initializeOnly($registration, true, true);
        self::assertSame(1, $calls);
        self::assertSame(['release' => null, 'live' => null], $runtime->healthStatuses());
    }

    public function test_entry_point_has_no_snapshot_reader_collector_or_verifier_path(): void
    {
        $entry = file_get_contents(dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-init-diagnostic.php');
        foreach (['RuntimeSnapshotCollector', 'SentinelEvidenceCollector', 'SentinelVerifier',
            'HEARTBEAT_ACCEPTED', 'SNAPSHOT_PERSISTED', 'EVIDENCE_COLLECTED', 'VERIFIED'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $entry);
        }
    }

    public function test_runtime_router_separates_sqlite_connection_from_migrations(): void
    {
        $router = file_get_contents(dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-router.php');
        $instrumentation = file_get_contents(dirname(__DIR__, 5)
            . '/service/app/Services/Sentinel/LocalProof/SentinelMigrationEventInstrumentation.php');
        self::assertStringContainsString("\$operation = 'SQLITE_CONNECTION';", $router);
        self::assertStringContainsString("\$app->make('db')->connection('sqlite')->getPdo();", $router);
        self::assertStringContainsString("\$operation = 'MIGRATION_COMMAND_PREPARATION';", $router);
        self::assertStringContainsString("\$kernel->call('migrate', ['--force' => true])", $router);
        $migrationInstrumentation = $router . $instrumentation;
        foreach ([
            'MIGRATION_REPOSITORY_INITIALIZATION',
            'MIGRATION_DISCOVERY',
            'MIGRATION_EXECUTION_PREPARATION',
            'MIGRATION_EXECUTION',
            'MIGRATION_REPOSITORY_RECORDING',
            'MIGRATION_EXECUTION_COMPLETED',
            'MIGRATION_COMMAND_COMPLETED',
            'MIGRATION_COMMAND_EXIT_NONZERO',
        ] as $operation) {
            self::assertStringContainsString($operation, $migrationInstrumentation);
        }
        self::assertStringNotContainsString('$event->name', $router);
        self::assertStringContainsString('SentinelMigrationEventInstrumentation', $router);
        self::assertStringContainsString("'MIGRATION_DIAGNOSTIC_INSTRUMENTATION_FAILED'", $router);
        self::assertStringContainsString('Illuminate\Database\Events\MigrationStarted::class', $router);
        self::assertStringContainsString('Illuminate\Database\Events\MigrationsStarted::class', $router);
    }

    public function test_runtime_router_marks_each_framework_bootstrapper_without_changing_bootstrap_call(): void
    {
        $router = file_get_contents(dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-router.php');
        self::assertStringContainsString('$app->beforeBootstrapping($bootstrapper', $router);
        self::assertStringContainsString('$app->afterBootstrapping($bootstrapper', $router);
        self::assertStringContainsString('$kernel->bootstrap();', $router);
        foreach ([
            'LoadEnvironmentVariables' => 'LARAVEL_BOOTSTRAP_ENVIRONMENT',
            'LoadConfiguration' => 'LARAVEL_BOOTSTRAP_CONFIGURATION',
            'HandleExceptions' => 'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS',
            'RegisterFacades' => 'LARAVEL_BOOTSTRAP_FACADES',
            'SetRequestForConsole' => 'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST',
            'RegisterProviders' => 'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION',
            'BootProviders' => 'LARAVEL_BOOTSTRAP_PROVIDER_BOOT',
        ] as $bootstrapper => $operation) {
            self::assertStringContainsString($bootstrapper . '::class => \'' . $operation . '\'', $router);
        }
    }

    public function test_runtime_router_registers_windows_drive_prefix_for_laravel_cache_paths(): void
    {
        $router = file_get_contents(dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-router.php');
        self::assertStringContainsString("preg_match('/^[A-Za-z]:/', \$root, \$drivePrefix)", $router);
        self::assertStringContainsString('$app->addAbsoluteCachePathPrefix($drivePrefix[0]);', $router);
        self::assertLessThan(
            strpos($router, '$kernel->bootstrap();'),
            strpos($router, '$app->addAbsoluteCachePathPrefix($drivePrefix[0]);'),
        );
    }

    public function test_installed_laravel_normalizes_isolated_windows_cache_paths_without_changing_other_paths(): void
    {
        $basePath = $this->config->path('runtime/application/releases/fixture-deployment');
        $cacheDirectory = str_replace('\\', '/', $this->config->cachePath(false));
        $forwardSlashPath = $cacheDirectory . '/packages.php';
        $backslashPath = str_replace('/', '\\', $forwardSlashPath);

        self::assertMatchesRegularExpression('/^[A-Za-z]:\//', $forwardSlashPath);
        self::assertSame($forwardSlashPath, $this->laravelPackagesCachePath($basePath, $forwardSlashPath));
        self::assertSame($backslashPath, $this->laravelPackagesCachePath($basePath, $backslashPath));

        $normalizedCachePath = str_replace('\\', '/', $this->laravelPackagesCachePath($basePath, $forwardSlashPath));
        self::assertStringStartsWith($cacheDirectory . '/', $normalizedCachePath);
        self::assertSame($cacheDirectory . '/packages.php', $normalizedCachePath);
        self::assertNotSame($basePath . '/' . $forwardSlashPath, $normalizedCachePath);

        $unixPath = '/isolated/runtime/cache/packages.php';
        self::assertSame($unixPath, $this->laravelPackagesCachePath($basePath, $unixPath));

        $relativePath = 'runtime/cache/packages.php';
        self::assertSame(
            str_replace('\\', '/', $basePath . '/' . $relativePath),
            str_replace('\\', '/', $this->laravelPackagesCachePath($basePath, $relativePath)),
        );
    }

    private function laravelPackagesCachePath(string $basePath, string $cachePath): string
    {
        $previousApplication = Application::getInstance();
        $previousGetenv = getenv('APP_PACKAGES_CACHE');
        $hadEnvValue = array_key_exists('APP_PACKAGES_CACHE', $_ENV);
        $previousEnvValue = $_ENV['APP_PACKAGES_CACHE'] ?? null;
        $hadServerValue = array_key_exists('APP_PACKAGES_CACHE', $_SERVER);
        $previousServerValue = $_SERVER['APP_PACKAGES_CACHE'] ?? null;

        putenv('APP_PACKAGES_CACHE=' . $cachePath);
        $_ENV['APP_PACKAGES_CACHE'] = $cachePath;
        $_SERVER['APP_PACKAGES_CACHE'] = $cachePath;
        Env::enablePutenv();

        try {
            $app = new Application($basePath);
            if (preg_match('/^[A-Za-z]:/', $basePath, $drivePrefix) === 1) {
                $app->addAbsoluteCachePathPrefix($drivePrefix[0]);
            }

            return $app->getCachedPackagesPath();
        } finally {
            if ($previousGetenv === false) {
                putenv('APP_PACKAGES_CACHE');
            } else {
                putenv('APP_PACKAGES_CACHE=' . $previousGetenv);
            }
            if ($hadEnvValue) {
                $_ENV['APP_PACKAGES_CACHE'] = $previousEnvValue;
            } else {
                unset($_ENV['APP_PACKAGES_CACHE']);
            }
            if ($hadServerValue) {
                $_SERVER['APP_PACKAGES_CACHE'] = $previousServerValue;
            } else {
                unset($_SERVER['APP_PACKAGES_CACHE']);
            }
            Env::enablePutenv();
            Application::setInstance($previousApplication);
        }
    }

    public function test_failed_initialization_evidence_keeps_exception_text_sanitized(): void
    {
        [$runtime, $registration] = $this->runtimeFixture(function (): array {
            $this->files->write('runtime/logs/router-errors.log', json_encode([
                'failure_stage' => 'RUNTIME_BOOTSTRAP_OR_MIGRATION',
                'failure_operation' => 'LARAVEL_KERNEL_BOOTSTRAP',
                'exception_class' => 'RuntimeException',
                'sanitized_exception_message' => '[REDACTED: unrecognized exception message]',
                'exception_file' => '[REDACTED]',
                'exception_line' => 42,
                'previous_exception_chain' => [],
                'exception_chain' => [],
            ], JSON_THROW_ON_ERROR));
            return ['exit_code' => 1, 'successful' => false];
        });
        try {
            $runtime->initializeOnly($registration, true, true);
            self::fail('Initialization failure was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_runtime_initialization_failed', $error->getMessage());
            $caught = $error;
        }
        $evidence = file_get_contents($this->config->path('evidence/runtime-initialization.json'));
        $decoded = json_decode($evidence, true, 512, JSON_THROW_ON_ERROR);
        $report = SentinelProofDiagnostics::describeRuntime($caught, 'RUNTIME_INITIALIZATION');
        self::assertSame('LARAVEL_KERNEL_BOOTSTRAP', $decoded['diagnostics']['failure_operation']);
        self::assertSame('[REDACTED: unrecognized exception message]',
            $decoded['diagnostics']['sanitized_exception_message']);
        self::assertStringNotContainsString('raw-secret', $evidence);
        self::assertSame('proof_runtime_initialization_failed', $report['sanitized_exception_message']);
    }

    public function test_previous_run_marker_is_unchanged_by_diagnostic_root_operations(): void
    {
        $path = sys_get_temp_dir() . '/sentinel-previous-fixture-' . bin2hex(random_bytes(8)) . '.marker';
        file_put_contents($path, 'previous-run-fixture');
        $before = hash_file('sha256', $path);
        try {
            $this->files->initialize(true);
            self::assertSame($before, hash_file('sha256', $path));
        } finally {
            unlink($path);
        }
    }

    public function test_source_fixture_is_unchanged_by_runtime_initialization(): void
    {
        $sourceFile = dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-router.php';
        $before = hash_file('sha256', $sourceFile);
        [$runtime, $registration] = $this->runtimeFixture(
            fn (): array => ['exit_code' => 0, 'successful' => true],
        );
        $runtime->initializeOnly($registration, true, true);
        self::assertSame($before, hash_file('sha256', $sourceFile));
    }

    public function test_isolated_database_and_runtime_writable_paths_are_beneath_new_root(): void
    {
        foreach ([$this->config->databasePath(), $this->config->runtimeDatabasePath(),
            $this->config->storagePath(), $this->config->releaseRoot()] as $path) {
            self::assertStringStartsWith($this->config->root . '/', str_replace('\\', '/', $path));
        }
        self::assertNotSame($this->config->databasePath(), $this->config->runtimeDatabasePath());
        self::assertSame($this->config->runtimeDatabasePath(), $this->config->environment(false)['DB_DATABASE']);
        self::assertSame($this->config->databasePath(), $this->config->environment(true)['DB_DATABASE']);
    }

    public function test_initialization_fails_closed_without_both_authorizations_or_matching_commit(): void
    {
        $calls = 0;
        [$runtime, $registration] = $this->runtimeFixture(function () use (&$calls): array {
            $calls++;
            return ['exit_code' => 0, 'successful' => true];
        });
        try {
            $runtime->initializeOnly($registration, false, true);
            self::fail('Unauthorized initialization was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_execution_not_authorized', $error->getMessage());
        }
        try {
            $runtime->initializeOnly($registration, true, false);
            self::fail('Schema initialization without authorization was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_execution_not_authorized', $error->getMessage());
        }
        $registration['deployment']['commit_hash'] = str_repeat('b', 40);
        try {
            $runtime->initializeOnly($registration, true, true);
            self::fail('Mismatched commit was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('proof_runtime_attribution_mismatch', $error->getMessage());
        }
        self::assertSame(0, $calls);
    }
}
