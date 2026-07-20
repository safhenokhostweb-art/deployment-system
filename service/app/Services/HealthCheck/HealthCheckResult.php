<?php

namespace App\Services\HealthCheck;

final class HealthCheckResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {
    }
}
