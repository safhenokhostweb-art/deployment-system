<?php

namespace App\Services\HealthCheck;

use App\Models\DeploymentTask;

/**
 * Task 5: the framework contract every health check provider implements.
 * Only HttpStatusHealthChecker exists as a concrete provider in this
 * phase — deliberately a proof of concept, not a complete library (e.g.
 * a future "command" or "application response" checker would just add
 * another class implementing this same interface).
 */
interface HealthChecker
{
    /**
     * @param array<string, mixed> $config
     */
    public function check(DeploymentTask $task, array $config): HealthCheckResult;
}
