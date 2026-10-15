<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelMigrationEventInstrumentation;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SentinelMigrationEventInstrumentationTest extends TestCase
{
    public function test_single_migration_events_use_the_migration_object_and_record_only_a_stable_hash(): void
    {
        $operations = [];
        $identifiers = [];
        $instrumentation = $this->instrumentation($operations, $identifiers);
        $migration = new class extends Migration {};
        $rawName = '2031_02_03_040506_sensitive_migration';

        $instrumentation->migrationStarted(new MigrationStarted($migration, 'up', null));
        $instrumentation->migrationStarted(new MigrationStarted($migration, 'up', $rawName));
        $instrumentation->migrationEnded(new MigrationEnded($migration, 'up', $rawName));

        $expectedIdentifier = hash('sha256', str_replace('.php', '', basename(__FILE__)));
        self::assertSame([
            'MIGRATION_EXECUTION',
            'MIGRATION_EXECUTION',
            'MIGRATION_REPOSITORY_RECORDING',
        ], $operations);
        self::assertSame([null, $expectedIdentifier, null, $expectedIdentifier], $identifiers);

        $failure = new RuntimeException('SQL: select secret from users at C:\\sensitive\\database.sqlite');
        $evidence = SentinelProofDiagnostics::describeRuntime(
            $failure,
            'MIGRATION_EXECUTION',
            $identifiers[1],
        );
        $serialized = json_encode(['events' => $operations, 'identifiers' => $identifiers, 'failure' => $evidence], JSON_THROW_ON_ERROR);
        foreach ([$rawName, 'select secret', 'C:\\sensitive\\database.sqlite', __FILE__] as $sensitiveValue) {
            self::assertStringNotContainsString($sensitiveValue, $serialized);
        }
        self::assertSame($expectedIdentifier, $evidence['failure_migration_id']);
    }

    public function test_migration_batch_events_preserve_the_existing_operation_markers(): void
    {
        $operations = [];
        $identifiers = [];
        $instrumentation = $this->instrumentation($operations, $identifiers);

        $instrumentation->migrationsStarted(new MigrationsStarted('up', ['force' => true]));
        $instrumentation->migrationsEnded(new MigrationsEnded('up', ['force' => true]));

        self::assertSame(['MIGRATION_EXECUTION_PREPARATION', 'MIGRATION_EXECUTION_COMPLETED'], $operations);
        self::assertSame([null], $identifiers);
    }

    public function test_missing_or_unexpected_migration_data_is_recorded_safely_without_an_identifier(): void
    {
        $operations = [];
        $identifiers = [];
        $failures = [];
        $instrumentation = $this->instrumentation($operations, $identifiers, $failures);

        $instrumentation->migrationStarted(new \stdClass());
        $instrumentation->migrationStarted((object) ['migration' => 'unexpected']);

        self::assertSame(['MIGRATION_EXECUTION', 'MIGRATION_EXECUTION'], $operations);
        self::assertSame([null, null], $identifiers);
        self::assertSame([true, true], $failures);
    }

    public function test_diagnostic_recording_failures_do_not_escape_or_prevent_following_migration_work(): void
    {
        $operations = [];
        $identifiers = [];
        $failures = [];
        $migrationWorkContinued = false;
        $instrumentation = new SentinelMigrationEventInstrumentation(
            static function (string $operation): void {
                throw new RuntimeException('private operation recording details');
            },
            static function (?string $identifier) use (&$identifiers): void {
                $identifiers[] = $identifier;
            },
            static function () use (&$failures): void {
                $failures[] = true;
            },
        );

        $instrumentation->migrationStarted(new MigrationStarted(new class extends Migration {}, 'up', null));
        $migrationWorkContinued = true;

        self::assertTrue($migrationWorkContinued);
        self::assertSame([true], $failures);

        $identifierFailure = new SentinelMigrationEventInstrumentation(
            static function (string $operation) use (&$operations): void {
                $operations[] = $operation;
            },
            static function (?string $identifier): void {
                if ($identifier !== null) {
                    throw new RuntimeException('identifier recording unavailable');
                }
            },
            static function () use (&$failures): void {
                $failures[] = true;
            },
        );
        $identifierFailure->migrationStarted(new MigrationStarted(new class extends Migration {}, 'up', null));

        self::assertSame(['MIGRATION_EXECUTION'], $operations);
        self::assertSame([true, true], $failures);

        $unavailableFailureMarker = new SentinelMigrationEventInstrumentation(
            static function (string $operation): void {
                throw new RuntimeException('private operation recording details');
            },
            static function (?string $identifier): void {},
            static function (): void {
                throw new RuntimeException('private failure marker details');
            },
        );
        $unavailableFailureMarker->migrationStarted(new MigrationStarted(new class extends Migration {}, 'up', null));
        self::assertTrue($migrationWorkContinued);
    }

    public function test_genuine_migration_exception_after_event_instrumentation_is_preserved(): void
    {
        $operations = [];
        $identifiers = [];
        $instrumentation = $this->instrumentation($operations, $identifiers);
        $migrationFailure = new RuntimeException('genuine migration failure');

        try {
            $instrumentation->migrationStarted(new MigrationStarted(new class extends Migration {}, 'up', null));
            throw $migrationFailure;
        } catch (RuntimeException $actual) {
            self::assertSame($migrationFailure, $actual);
        }
    }

    private function instrumentation(
        array &$operations,
        array &$identifiers,
        array &$failures = [],
        ?\Closure $failureRecorder = null,
    ): SentinelMigrationEventInstrumentation {
        return new SentinelMigrationEventInstrumentation(
            static function (string $operation) use (&$operations): void {
                $operations[] = $operation;
            },
            static function (?string $identifier) use (&$identifiers): void {
                $identifiers[] = $identifier;
            },
            $failureRecorder ?? static function () use (&$failures): void {
                $failures[] = true;
            },
        );
    }
}
