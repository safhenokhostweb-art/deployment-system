<?php

declare(strict_types=1);

/**
 * Database Safety Hardening (Phase S1): the first of two independent
 * layers protecting the automated test suite itself (the second is
 * Tests\TestCase::setUp(), which re-checks after Laravel is fully
 * booted). This one runs before ANYTHING else — before Composer's
 * autoloader even finishes requiring — using only raw getenv()/$_ENV,
 * deliberately not the framework, so it cannot be fooled by a config
 * cache or a bug in Laravel's own environment resolution.
 *
 * PHPUnit applies phpunit.xml's <php><env> values via putenv() before
 * loading this bootstrap file, so at this point APP_ENV/DB_CONNECTION/
 * DB_DATABASE already reflect whatever the test run actually resolved —
 * exactly what this assertion needs to see.
 *
 * Exists because `php artisan migrate:fresh --env=testing --force` once
 * wiped the real local development database — see
 * app/Support/Database/DestructiveCommandGuard.php for the primary,
 * command-level guard this complements.
 */

require __DIR__ . '/../vendor/autoload.php';

function deployment_system_test_env(string $name): ?string
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    return $_ENV[$name] ?? $_SERVER[$name] ?? null;
}

$appEnv = deployment_system_test_env('APP_ENV');
if ($appEnv !== 'testing') {
    fwrite(STDERR, "FATAL: refusing to boot the test suite — APP_ENV resolved to '{$appEnv}', expected 'testing'.\n");
    fwrite(STDERR, "Run tests via vendor/bin/phpunit (or `composer test`), which set this correctly — never a bare `php artisan test`/`migrate` invocation with an unresolved environment.\n");
    exit(1);
}

$dbConnection = deployment_system_test_env('DB_CONNECTION');
$dbDatabase = deployment_system_test_env('DB_DATABASE');
if ($dbConnection !== 'sqlite' || $dbDatabase !== ':memory:') {
    fwrite(STDERR, "FATAL: refusing to boot the test suite — DB target resolved to '{$dbConnection}:{$dbDatabase}', expected sqlite ':memory:'.\n");
    fwrite(STDERR, "This safeguard exists because a prior test/setup command wiped the real local development database when test isolation silently fell through to it.\n");
    exit(1);
}
