<?php

namespace App\Services\HealthCheck;

use App\Models\DeploymentTask;
use Illuminate\Support\Facades\Http;

/**
 * The one concrete provider built in this phase: fetches a configured URL
 * and compares the response status code against an expected value.
 * Uses Laravel's bundled HTTP client (Illuminate\Http\Client, already a
 * framework dependency) — no new package.
 */
class HttpStatusHealthChecker implements HealthChecker
{
    public function check(DeploymentTask $task, array $config): HealthCheckResult
    {
        $url = $config['url'] ?? null;
        $expectedStatus = (int) ($config['expected_status'] ?? 200);
        $timeoutSeconds = (int) ($config['timeout_seconds'] ?? 10);

        if (!$url) {
            return new HealthCheckResult(false, 'No URL configured for this http_status health check.');
        }

        try {
            $response = Http::timeout($timeoutSeconds)->get($url);
            $actualStatus = $response->status();

            if ($actualStatus === $expectedStatus) {
                return new HealthCheckResult(true, "Received expected HTTP {$actualStatus} from {$url}.");
            }

            return new HealthCheckResult(false, "Expected HTTP {$expectedStatus} from {$url}, got {$actualStatus}.");
        } catch (\Throwable $e) {
            return new HealthCheckResult(false, "Request to {$url} failed: {$e->getMessage()}");
        }
    }
}
