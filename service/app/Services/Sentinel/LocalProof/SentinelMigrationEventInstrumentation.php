<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use ReflectionClass;
use Throwable;
use UnexpectedValueException;

final class SentinelMigrationEventInstrumentation
{
    public function __construct(
        private \Closure $recordOperation,
        private \Closure $recordIdentifier,
        private \Closure $recordInstrumentationFailure,
    ) {
    }

    public function migrationsStarted(mixed $event): void
    {
        $this->safely(function (): void {
            ($this->recordOperation)('MIGRATION_EXECUTION_PREPARATION');
        });
    }

    public function migrationStarted(mixed $event): void
    {
        $this->safely(function () use ($event): void {
            ($this->recordIdentifier)(null);
            ($this->recordOperation)('MIGRATION_EXECUTION');
            ($this->recordIdentifier)(self::migrationIdentifier($event));
        });
    }

    public function migrationEnded(mixed $event): void
    {
        $this->safely(function (): void {
            ($this->recordOperation)('MIGRATION_REPOSITORY_RECORDING');
        });
    }

    public function migrationsEnded(mixed $event): void
    {
        $this->safely(function (): void {
            ($this->recordIdentifier)(null);
            ($this->recordOperation)('MIGRATION_EXECUTION_COMPLETED');
        });
    }

    private static function migrationIdentifier(mixed $event): string
    {
        if (!is_object($event)) {
            throw new UnexpectedValueException('migration_event_invalid');
        }

        $properties = get_object_vars($event);
        $migration = $properties['migration'] ?? null;
        if (!is_object($migration)) {
            throw new UnexpectedValueException('migration_object_unavailable');
        }

        $file = (new ReflectionClass($migration))->getFileName();
        if (!is_string($file) || $file === '') {
            throw new UnexpectedValueException('migration_file_unavailable');
        }

        $name = str_replace('.php', '', basename($file));
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new UnexpectedValueException('migration_name_invalid');
        }

        return hash('sha256', $name);
    }

    private function safely(callable $record): void
    {
        try {
            $record();
        } catch (Throwable) {
            try {
                ($this->recordInstrumentationFailure)();
            } catch (Throwable) {
                // An unavailable diagnostic sink must not interrupt Laravel's migration.
            }
        }
    }
}
