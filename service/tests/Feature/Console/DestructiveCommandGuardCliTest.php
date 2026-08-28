<?php

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real, subprocess-level proof that DestructiveCommandGuard is actually
 * wired into `php artisan <command>` for genuine CLI invocations — the
 * exact failure mode of the original incident.
 *
 * Deliberately NOT using Artisan::call()/$this->artisan() in-process:
 * Laravel only dispatches the CommandStarting event (which
 * AppServiceProvider listens on) when NOT app()->runningUnitTests() —
 * see Illuminate\Foundation\Console\Kernel::rerouteSymfonyCommandEvents().
 * An in-process Artisan::call() during this very test suite would never
 * trigger the guard at all, proving nothing. A real child `php artisan`
 * process is a genuinely separate process with its own environment, so
 * it exercises the real interception path end to end.
 *
 * SAFETY: every subprocess below explicitly sets APP_ENV/DB_CONNECTION/
 * DB_DATABASE itself — never inherited, never ambiguous — and every
 * "deny" scenario's database target is a unique, disposable temp file
 * this test creates and deletes itself. No subprocess here is ever
 * allowed to resolve to the real service/database/database.sqlite.
 */
class DestructiveCommandGuardCliTest extends TestCase
{
    private const REAL_DEV_DB = __DIR__ . '/../../../database/database.sqlite';

    private function runArtisan(array $args, array $envOverrides, int $timeout = 30): Process
    {
        $servicePath = realpath(__DIR__ . '/../../..');

        $process = new Process([PHP_BINARY, 'artisan', ...$args], $servicePath, $envOverrides);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    private function disposableTempSqlitePath(string $label): string
    {
        $path = sys_get_temp_dir() . '/deployment-system-guard-test-' . $label . '-' . bin2hex(random_bytes(6)) . '.sqlite';

        // Never, ever proceed with a "disposable" path that happens to
        // collide with the real development database.
        $this->assertNotEquals(realpath(self::REAL_DEV_DB) ?: self::REAL_DEV_DB, $path);

        return $path;
    }

    protected function tearDownDisposable(string $path): void
    {
        @unlink($path);
    }

    // Real-CLI proof of the ALLOW path — testing + :memory: must still work normally.
    public function test_migrate_fresh_succeeds_against_real_cli_testing_memory_sqlite(): void
    {
        $process = $this->runArtisan(['migrate:fresh'], [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]);

        $this->assertTrue($process->isSuccessful(), 'stderr: ' . $process->getErrorOutput() . ' stdout: ' . $process->getOutput());
        $this->assertStringNotContainsString('Refusing to run', $process->getOutput() . $process->getErrorOutput());
    }

    // H + real-CLI proof of the DENY path: db:wipe outside testing.
    public function test_db_wipe_is_denied_against_a_non_testing_environment(): void
    {
        $target = $this->disposableTempSqlitePath('wipe-local');

        try {
            $process = $this->runArtisan(['db:wipe', '--force'], [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $target,
            ]);

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Refusing to run', $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString("not 'testing'", $process->getOutput() . $process->getErrorOutput());
            $this->assertFileDoesNotExist($target, 'db:wipe must never have touched the disposable target.');
        } finally {
            $this->tearDownDisposable($target);
        }
    }

    // F. --env=testing without a safe EFFECTIVE database cannot bypass the guard.
    public function test_env_testing_flag_does_not_bypass_the_guard_when_the_effective_db_is_unsafe(): void
    {
        $target = $this->disposableTempSqlitePath('env-flag-unsafe');

        try {
            // Simulates exactly the original incident's shape: --env=testing
            // is passed, but the EFFECTIVE resolved database (forced here
            // via explicit env, standing in for a misconfigured/missing
            // .env.testing) is not an approved one.
            $process = $this->runArtisan(['migrate:fresh', '--env=testing'], [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $target,
            ]);

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Refusing to run', $process->getOutput() . $process->getErrorOutput());
            $this->assertFileDoesNotExist($target, 'migrate:fresh must never have touched the disposable target.');
        } finally {
            $this->tearDownDisposable($target);
        }
    }

    // G. --force cannot bypass the guard.
    public function test_force_flag_does_not_bypass_the_guard(): void
    {
        $target = $this->disposableTempSqlitePath('force-flag');

        try {
            $process = $this->runArtisan(['migrate:fresh', '--force'], [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $target,
            ]);

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Refusing to run', $process->getOutput() . $process->getErrorOutput());
            $this->assertFileDoesNotExist($target, '--force must never have let migrate:fresh touch the disposable target.');
        } finally {
            $this->tearDownDisposable($target);
        }
    }

    // The literal original incident shape, reproduced end to end against a disposable target only.
    public function test_the_original_incident_command_shape_is_now_denied(): void
    {
        $target = $this->disposableTempSqlitePath('original-incident-shape');

        try {
            // php artisan migrate:fresh --env=testing --force, with no
            // .env.testing available to the child process's search path
            // resolution (APP_ENV/DB_* forced local+unsafe here exactly as
            // the real fallback once produced).
            $process = $this->runArtisan(['migrate:fresh', '--env=testing', '--force'], [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $target,
            ]);

            $this->assertFalse($process->isSuccessful());
            $this->assertFileDoesNotExist($target);
        } finally {
            $this->tearDownDisposable($target);
        }
    }
}
