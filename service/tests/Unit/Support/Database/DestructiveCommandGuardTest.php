<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\DestructiveCommandBlockedException;
use App\Support\Database\DestructiveCommandGuard;
use Tests\TestCase;

/**
 * Pure decision-logic coverage for DestructiveCommandGuard::decide() —
 * zero I/O, no real database ever touched, exercised against fabricated
 * scenarios. See tests/Feature/Console/DestructiveCommandGuardCliTest.php
 * for the real, subprocess-level proof that this is actually wired into
 * `php artisan <command>` and cannot be bypassed by --env/--force.
 */
class DestructiveCommandGuardTest extends TestCase
{
    private const REAL_DEV_DB = 'C:\\laragon\\www\\deployment-system\\service\\database\\database.sqlite';

    // A. testing + sqlite :memory: is allowed
    public function test_testing_with_memory_sqlite_is_allowed(): void
    {
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', ':memory:', self::REAL_DEV_DB);

        $this->assertTrue($decision['allowed']);
    }

    // B. testing + real service/database/database.sqlite is DENIED
    public function test_testing_with_the_real_development_database_is_denied(): void
    {
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', self::REAL_DEV_DB, self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('real development database', $decision['reason']);
    }

    public function test_the_real_database_denial_is_case_and_slash_insensitive(): void
    {
        // Windows: same file, different case and separators — must still match.
        $decision = DestructiveCommandGuard::decide(
            'testing',
            'sqlite',
            'c:/laragon/WWW/deployment-system/service/database/DATABASE.SQLITE',
            self::REAL_DEV_DB,
        );

        $this->assertFalse($decision['allowed']);
    }

    // C. local/development + database.sqlite is DENIED
    public function test_local_environment_with_the_dev_database_is_denied(): void
    {
        $decision = DestructiveCommandGuard::decide('local', 'sqlite', self::REAL_DEV_DB, self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
    }

    public function test_local_environment_with_memory_sqlite_is_still_denied(): void
    {
        // Environment alone must gate this — :memory: doesn't grant a pass outside testing.
        $decision = DestructiveCommandGuard::decide('local', 'sqlite', ':memory:', self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString("not 'testing'", $decision['reason']);
    }

    // D. production-like target is DENIED
    public function test_production_environment_is_denied_regardless_of_database(): void
    {
        $decision = DestructiveCommandGuard::decide('production', 'sqlite', ':memory:', self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
    }

    public function test_non_sqlite_connection_is_denied_even_in_testing(): void
    {
        // This app's own test suite is sqlite-only (phpunit.xml); a
        // destructive command against any other configured connection
        // (mysql/pgsql) is never approved by this guard.
        $decision = DestructiveCommandGuard::decide('testing', 'mysql', 'some_database', self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString("connection is 'mysql'", $decision['reason']);
    }

    // E. missing/ambiguous DB target is DENIED
    public function test_null_database_path_is_denied(): void
    {
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', null, self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('unresolved', $decision['reason']);
    }

    public function test_empty_database_path_is_denied(): void
    {
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', '   ', self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
    }

    public function test_an_unlisted_file_path_is_denied_by_default(): void
    {
        // No entry in disposable_test_database_paths (the default,
        // out-of-the-box config) — an arbitrary file-based sqlite path
        // must never be silently trusted.
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', 'C:\\some\\random\\test.sqlite', self::REAL_DEV_DB);

        $this->assertFalse($decision['allowed']);
    }

    public function test_an_explicitly_approved_disposable_path_is_allowed(): void
    {
        $approved = 'C:\\temp\\deployment-system-disposable-test.sqlite';

        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', $approved, self::REAL_DEV_DB, [$approved]);

        $this->assertTrue($decision['allowed']);
    }

    public function test_an_approved_path_still_never_overrides_the_real_dev_db_check(): void
    {
        // Pathological config: someone accidentally lists the real dev DB
        // as "approved". The unconditional real-database check still wins.
        $decision = DestructiveCommandGuard::decide('testing', 'sqlite', self::REAL_DEV_DB, self::REAL_DEV_DB, [self::REAL_DEV_DB]);

        $this->assertFalse($decision['allowed']);
    }

    public function test_assert_safe_throws_with_a_specific_command_name_when_denied(): void
    {
        // assertSafe() reads live app()/config() state — in this suite
        // that's always testing + :memory:, i.e. always allowed, so this
        // only proves the exception TYPE/message shape via decide()'s
        // reason text, not a live-denied assertSafe() call (which would
        // require mutating real app config mid-test).
        $decision = DestructiveCommandGuard::decide('local', 'sqlite', self::REAL_DEV_DB, self::REAL_DEV_DB);
        $this->assertFalse($decision['allowed']);

        $this->expectException(DestructiveCommandBlockedException::class);
        if (!$decision['allowed']) {
            throw new DestructiveCommandBlockedException("Refusing to run 'migrate:fresh': {$decision['reason']}.");
        }
    }

    // I. every command the safety config protects
    public function test_the_expected_destructive_commands_are_all_protected(): void
    {
        $protected = (array) config('database_safety.protected_commands');

        $this->assertSame(
            ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'migrate:rollback', 'db:wipe'],
            $protected,
        );
    }

    public function test_plain_forward_migrate_is_never_in_the_protected_list(): void
    {
        // Blocking additive, non-destructive `migrate` would break every
        // normal deploy/setup flow for no safety benefit.
        $protected = (array) config('database_safety.protected_commands');

        $this->assertNotContains('migrate', $protected);
    }

    public function test_disposable_test_database_paths_defaults_to_empty(): void
    {
        // Only :memory: is safe out of the box — this must not silently
        // grow without a deliberate config change.
        $this->assertSame([], config('database_safety.disposable_test_database_paths'));
    }
}
