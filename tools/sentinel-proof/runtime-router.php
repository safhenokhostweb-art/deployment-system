<?php

declare(strict_types=1);
require_once __DIR__ . '/../../service/app/Services/Sentinel/LocalProof/SentinelProofFailure.php';
require_once __DIR__ . '/../../service/app/Services/Sentinel/LocalProof/SentinelProofDiagnostics.php';
require_once __DIR__ . '/../../service/app/Services/Sentinel/LocalProof/SentinelMigrationEventInstrumentation.php';

/**
 * Boots ONLY the extracted application, with a new environment/storage/DB.
 * No deployment-system bootstrap, existing .env, scheduler or worker.
 */
$operation = 'CONFIG_VALIDATION';
$migrationIdentifier = null;
$migrationInstrumentationFailure = false;
try {
    $path = getenv('SENTINEL_PROOF_CONFIG');
    $temp = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
    if (!is_string($path) || !preg_match('~^' . preg_quote($temp, '~') . '/SentinelProof/[0-9a-f-]{36}/run\.json$~D', $path)) {
        throw new RuntimeException('proof_config_rejected');
    }
    $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $root = $temp . '/SentinelProof/' . $config['run_id'];
    if ($config['root'] !== $root || realpath($root) === false || is_link($root)) {
        throw new RuntimeException('proof_run_root_rejected');
    }
    $id = getenv('SENTINEL_PROOF_DEPLOYMENT_ID');
    if (!is_string($id) || !preg_match('/^[a-z0-9-]+$/D', $id)) { throw new RuntimeException('proof_deployment_rejected'); }
    $operation = 'RELEASE_VALIDATION';
    $release = realpath($root . '/runtime/application/current');
    $expected = realpath($root . '/runtime/application/releases/' . $id);
    if ($release === false || $release !== $expected
        || !str_starts_with(str_replace('\\', '/', $release), $root . '/runtime/application/releases/')) {
        throw new RuntimeException('proof_current_rejected');
    }
    $manifest = json_decode((string) file_get_contents($release . '/.deployment/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($manifest['commit_sha'] !== $config['commit_sha'] || $manifest['project_key'] !== $config['project_key']
        || trim((string) file_get_contents($release . '/.deployment/runtime-marker')) !== 'release:' . $id . '|commit:' . $manifest['commit_sha']) {
        throw new RuntimeException('proof_runtime_identity_rejected');
    }
    $operation = 'ARTIFACT_VERIFICATION';
    $artifactPath = getenv('SENTINEL_PROOF_ARTIFACT_PATH');
    $artifactChecksum = getenv('SENTINEL_PROOF_ARTIFACT_SHA256');
    $canonicalArtifact = is_string($artifactPath) ? realpath($artifactPath) : false;
    if ($canonicalArtifact === false
        || !str_starts_with(str_replace('\\', '/', $canonicalArtifact), $root . '/artifacts/')
        || !is_string($artifactChecksum) || !preg_match('/^[0-9a-f]{64}$/D', $artifactChecksum)
        || !hash_equals($artifactChecksum, hash_file('sha256', $canonicalArtifact))) {
        throw new RuntimeException('proof_runtime_artifact_rejected');
    }
    $archive = new ZipArchive();
    if ($archive->open($canonicalArtifact, ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_runtime_zip_rejected'); }
    try {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (str_ends_with($name, '/')) { continue; }
            if (str_contains($name, '\\') || str_contains($name, ':') || preg_match('~(^/|(^|/)\.\.(/|$))~', $name)) {
                throw new RuntimeException('proof_runtime_zip_path_rejected');
            }
            $file = realpath($release . '/' . $name);
            if ($file === false || !str_starts_with($file, $release . DIRECTORY_SEPARATOR)
                || !hash_equals(hash('sha256', $archive->getFromIndex($index)), hash_file('sha256', $file))) {
                throw new RuntimeException('proof_runtime_content_changed');
            }
        }
    } finally { $archive->close(); }
    $initialize = PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--initialize-runtime';
    if (!$initialize && (PHP_SAPI !== 'cli-server'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || !in_array($_SERVER['REQUEST_URI'] ?? '', ['/health/release', '/health/live'], true)
        || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
        http_response_code(404);
        exit;
    }
    $operation = 'LARAVEL_AUTOLOAD';
    require $release . '/vendor/autoload.php';
    $operation = 'LARAVEL_APP_BOOTSTRAP';
    $app = require $release . '/bootstrap/app.php';
    if (preg_match('/^[A-Za-z]:/', $root, $drivePrefix) === 1) {
        $app->addAbsoluteCachePathPrefix($drivePrefix[0]);
    }
    $operation = 'RUNTIME_ENVIRONMENT_CONFIGURATION';
    $app->loadEnvironmentFrom('__sentinel_proof_no_existing_environment__');
    $app->useStoragePath($root . '/runtime/storage');
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($root): void {
        $settings = $app->make('config');
        // Restrict secondary connections too, before any application provider boots.
        $settings->set('database.default', 'sqlite');
        $settings->set('database.connections', ['sqlite' => ['driver' => 'sqlite',
            'database' => $root . '/runtime/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $settings->set('filesystems.default', 'local');
        $settings->set('filesystems.disks', ['local' => ['driver' => 'local', 'root' => $root . '/runtime/storage/app'],
            'public' => ['driver' => 'local', 'root' => $root . '/runtime/storage/app/public']]);
        $settings->set('cache.default', 'array');
        $settings->set('cache.stores', ['array' => ['driver' => 'array', 'serialize' => false]]);
        $settings->set('session.driver', 'array');
        $settings->set('queue.default', 'null');
        $settings->set('queue.connections', []); // Any attempted dispatch fails closed; there is no worker backend.
        $settings->set('mail.default', 'log');
        $settings->set('mail.mailers', ['log' => ['transport' => 'log']]);
        $settings->set('logging.default', 'single');
        $settings->set('logging.channels', ['single' => ['driver' => 'single', 'path' => $root . '/runtime/logs/application.log', 'level' => 'warning']]);
    });
    $bootstrapOperations = [
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class => 'LARAVEL_BOOTSTRAP_ENVIRONMENT',
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class => 'LARAVEL_BOOTSTRAP_CONFIGURATION',
        Illuminate\Foundation\Bootstrap\HandleExceptions::class => 'LARAVEL_BOOTSTRAP_EXCEPTION_HANDLERS',
        Illuminate\Foundation\Bootstrap\RegisterFacades::class => 'LARAVEL_BOOTSTRAP_FACADES',
        Illuminate\Foundation\Bootstrap\SetRequestForConsole::class => 'LARAVEL_BOOTSTRAP_CONSOLE_REQUEST',
        Illuminate\Foundation\Bootstrap\RegisterProviders::class => 'LARAVEL_BOOTSTRAP_PROVIDER_REGISTRATION',
        Illuminate\Foundation\Bootstrap\BootProviders::class => 'LARAVEL_BOOTSTRAP_PROVIDER_BOOT',
    ];
    foreach ($bootstrapOperations as $bootstrapper => $bootstrapOperation) {
        $app->beforeBootstrapping($bootstrapper, static function (...$arguments) use (&$operation, $bootstrapOperation): void {
            $operation = $bootstrapOperation;
        });
        $app->afterBootstrapping($bootstrapper, static function (...$arguments) use (&$operation, $bootstrapOperation): void {
            $operation = $bootstrapOperation . '_COMPLETED';
        });
    }
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,
        static function (...$arguments) use (&$operation): void {
            $operation = 'LARAVEL_BOOTSTRAP_DEFERRED_PROVIDERS';
        });
    if ($initialize) {
        $operation = 'RUNTIME_DATABASE_FILE_CREATION';
        $database = $root . '/runtime/database.sqlite';
        if (!is_file($database) && file_put_contents($database, '') === false) { throw new RuntimeException('proof_runtime_database_failed'); }
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $operation = 'LARAVEL_KERNEL_BOOTSTRAP';
        $kernel->bootstrap();
        $operation = 'RUNTIME_DATABASE_VALIDATION';
        if (config('database.default') !== 'sqlite'
            || realpath(config('database.connections.sqlite.database')) !== realpath($database)) {
            throw new RuntimeException('proof_runtime_database_mismatch');
        }
        $operation = 'SQLITE_CONNECTION';
        $app->make('db')->connection('sqlite')->getPdo();
        $setMigrationOperation = static function (string $nextOperation) use (&$operation): void {
            $operation = $nextOperation;
        };
        $migrationInstrumentation = new App\Services\Sentinel\LocalProof\SentinelMigrationEventInstrumentation(
            $setMigrationOperation,
            static function (?string $identifier) use (&$migrationIdentifier): void {
                $migrationIdentifier = $identifier;
            },
            static function () use (&$migrationInstrumentationFailure): void {
                $migrationInstrumentationFailure = true;
            },
        );
        $migrator = $app->make('migrator');
        $instrumentedMigrator = new class(
            $migrator->getRepository(),
            $app['db'],
            $app['files'],
            $app['events'],
            $setMigrationOperation,
        ) extends Illuminate\Database\Migrations\Migrator {
            private \Closure $setOperation;

            public function __construct($repository, $resolver, $files, $events, \Closure $setOperation)
            {
                parent::__construct($repository, $resolver, $files, $events);
                $this->setOperation = $setOperation;
            }

            public function repositoryExists()
            {
                ($this->setOperation)('MIGRATION_REPOSITORY_INITIALIZATION');
                return parent::repositoryExists();
            }

            public function hasRunAnyMigrations()
            {
                ($this->setOperation)('MIGRATION_REPOSITORY_INITIALIZATION');
                $hasRunMigrations = parent::hasRunAnyMigrations();
                ($this->setOperation)('MIGRATION_COMMAND_PREPARATION');
                return $hasRunMigrations;
            }

            public function run($paths = [], array $options = [])
            {
                ($this->setOperation)('MIGRATION_DISCOVERY');
                return parent::run($paths, $options);
            }

            public function runPending(array $migrations, array $options = [])
            {
                ($this->setOperation)('MIGRATION_REPOSITORY_INITIALIZATION');
                return parent::runPending($migrations, $options);
            }
        };
        $app->instance('migrator', $instrumentedMigrator);
        $app['events']->listen(Illuminate\Database\Events\MigrationsStarted::class,
            static function ($event) use ($migrationInstrumentation): void {
                $migrationInstrumentation->migrationsStarted($event);
            });
        $app['events']->listen(Illuminate\Database\Events\MigrationStarted::class,
            static function ($event) use ($migrationInstrumentation): void {
                $migrationInstrumentation->migrationStarted($event);
            });
        $app['events']->listen(Illuminate\Database\Events\MigrationEnded::class,
            static function ($event) use ($migrationInstrumentation): void {
                $migrationInstrumentation->migrationEnded($event);
            });
        $app['events']->listen(Illuminate\Database\Events\MigrationsEnded::class,
            static function ($event) use ($migrationInstrumentation): void {
                $migrationInstrumentation->migrationsEnded($event);
            });
        $operation = 'MIGRATION_COMMAND_PREPARATION';
        $migrationExitCode = $kernel->call('migrate', ['--force' => true]);
        if ($migrationExitCode !== 0) {
            $operation = 'MIGRATION_COMMAND_EXIT_NONZERO';
            throw new RuntimeException('proof_runtime_schema_failed');
        }
        $operation = 'MIGRATION_COMMAND_COMPLETED';
        $operation = 'RELEASE_MANIFEST_CREATION';
        $manager = $app->make(SaaSPlatform\CoreUtils\Services\Deployment\ReleaseManifestManager::class);
        $data = $manager->create($id);
        // Archive has no .git. Fill provenance from the previously byte-verified artifact, not a guess.
        $data['git_commit'] = $manifest['commit_sha'];
        $data['git_branch'] = $manifest['branch'];
        unset($data['checksum']);
        $data['checksum'] = hash('sha256', json_encode($data));
        $output = $root . '/runtime/storage/app/releases/' . $id . '.json';
        $operation = 'RUNTIME_MANIFEST_WRITE';
        if (file_put_contents($output, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('proof_runtime_manifest_failed');
        }
        exit(0);
    }
    $operation = 'HTTP_KERNEL_BOOTSTRAP';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::capture();
    $operation = 'HTTP_REQUEST_HANDLING';
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable $error) {
    // Keep diagnostic evidence in this run only; never clean up a failed run.
    if (isset($root) && is_dir($root . '/runtime/logs')) {
        try {
            $diagnostics = App\Services\Sentinel\LocalProof\SentinelProofDiagnostics::describeRuntime(
                $error,
                $operation,
                $migrationIdentifier,
            );
            if ($migrationInstrumentationFailure) {
                $diagnostics['migration_instrumentation_failure'] = 'MIGRATION_DIAGNOSTIC_INSTRUMENTATION_FAILED';
            }
            file_put_contents($root . '/runtime/logs/router-errors.log', json_encode(
                $diagnostics,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Diagnostic recording must not replace the original runtime failure.
        }
    }
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "proof_runtime_failed\n"); exit(1); }
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"coherent":false}';
}
