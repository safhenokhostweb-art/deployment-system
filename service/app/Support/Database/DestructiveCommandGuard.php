<?php

namespace App\Support\Database;

/**
 * Central fail-closed policy deciding whether a schema/data-destroying
 * Artisan command (migrate:fresh, db:wipe, ...) is allowed to run at all.
 *
 * Exists because `php artisan migrate:fresh --env=testing --force` once
 * wiped the real local development database: --env=testing tried loading
 * a nonexistent .env.testing, silently fell back to the normal .env
 * (APP_ENV=local, DB_DATABASE=the real file), and nothing stopped it.
 * Every check in decide() below is independently sufficient to have
 * caught that exact failure — this is deliberately layered, not a single
 * check that could itself have a single point of failure.
 *
 * decide() is pure (no I/O, no framework calls, no config()/app()) so it
 * is directly unit-testable against fabricated scenarios without ever
 * touching a real database — see
 * tests/Unit/Support/Database/DestructiveCommandGuardTest.php.
 * assertSafe() is the thin, live wiring — see AppServiceProvider::boot()
 * for how it's actually invoked (a CommandStarting listener, so this
 * runs and can throw BEFORE the destructive command's own handle() is
 * ever called).
 *
 * A second, independent layer (tests/bootstrap.php + TestCase::setUp())
 * protects the automated test suite itself, in case a future change ever
 * causes PHPUnit to resolve the wrong database without going through any
 * Artisan command at all — see those files' own docblocks.
 */
final class DestructiveCommandGuard
{
    /**
     * @param array<int, string> $disposableTestDatabasePaths
     * @return array{allowed: bool, reason: string}
     */
    public static function decide(
        string $environment,
        string $connection,
        ?string $databasePath,
        string $realDevDatabasePath,
        array $disposableTestDatabasePaths = [],
    ): array {
        // Checked first and unconditionally, independent of every other
        // check below: no environment/config combination is ever allowed
        // to point a destructive command at the real development
        // database — this is the exact file the original incident wiped.
        if ($connection === 'sqlite' && $databasePath !== null && self::samePath($databasePath, $realDevDatabasePath)) {
            return ['allowed' => false, 'reason' => "target database resolves to the real development database ({$realDevDatabasePath})"];
        }

        if ($environment !== 'testing') {
            return ['allowed' => false, 'reason' => "environment is '{$environment}', not 'testing'"];
        }

        if ($connection !== 'sqlite') {
            return ['allowed' => false, 'reason' => "connection is '{$connection}' — only 'sqlite' is ever approved for destructive test commands"];
        }

        if ($databasePath === null || trim($databasePath) === '') {
            return ['allowed' => false, 'reason' => 'database path is empty or unresolved'];
        }

        if ($databasePath === ':memory:') {
            return ['allowed' => true, 'reason' => "sqlite ':memory:'"];
        }

        foreach ($disposableTestDatabasePaths as $approved) {
            if (is_string($approved) && $approved !== '' && self::samePath($databasePath, $approved)) {
                return ['allowed' => true, 'reason' => "approved disposable test database path ({$approved})"];
            }
        }

        return ['allowed' => false, 'reason' => "'{$databasePath}' is not ':memory:' and is not in the approved disposable test database path list"];
    }

    /**
     * @throws DestructiveCommandBlockedException
     */
    public static function assertSafe(string $commandName): void
    {
        $environment = app()->environment();
        $connection = (string) config('database.default');
        $databasePath = config("database.connections.{$connection}.database");
        $databasePath = is_string($databasePath) ? $databasePath : null;
        $realDevDatabasePath = database_path('database.sqlite');
        $disposablePaths = array_values((array) config('database_safety.disposable_test_database_paths', []));

        $decision = self::decide($environment, $connection, $databasePath, $realDevDatabasePath, $disposablePaths);

        if (!$decision['allowed']) {
            throw new DestructiveCommandBlockedException(
                "Refusing to run '{$commandName}': {$decision['reason']}. " .
                'This command permanently destroys data and is only ever allowed against an approved, disposable test database. ' .
                'Run the test suite via vendor/bin/phpunit (or `composer test`) instead of invoking this command directly.'
            );
        }
    }

    /**
     * Windows paths are case-insensitive and mix \ and / — normalize both
     * before comparing so a real match is never missed, which would be a
     * fail-OPEN bug and the one failure mode this class must never have.
     */
    private static function samePath(string $a, string $b): bool
    {
        $normalize = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', trim($p)), '/'));

        return $normalize($a) === $normalize($b);
    }
}
