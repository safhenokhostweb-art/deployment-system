<?php

namespace App\Support\Deployment;

final class HttpHealthCheckPayload
{
    /** @param array<string, mixed> $recipe @param array<string, mixed>|null $config @return array<string, mixed> */
    public static function appendToRecipe(array $recipe, ?array $config): array
    {
        if (($config['enabled'] ?? false) !== true || ($config['source'] ?? null) === 'deployment_recipe') {
            return $recipe;
        }

        $recipe['steps'][] = [
            'type' => 'http_health_check',
            'name' => 'customer-facing HTTP health',
            'url' => $config['url'],
            'expected_status' => $config['expected_status'] ?? 200,
            'request_timeout_seconds' => $config['timeout_seconds'] ?? 15,
            'timeout_seconds' => (($config['timeout_seconds'] ?? 15) * (1 + ($config['retries'] ?? 3)))
                + (($config['retry_interval_seconds'] ?? 5) * ($config['retries'] ?? 3)),
            'retries' => $config['retries'] ?? 3,
            'retry_delay_seconds' => $config['retry_interval_seconds'] ?? 5,
            'allowed_redirects' => $config['allowed_redirects'] ?? false,
            'expected_content' => $config['expected_content'] ?? null,
            'must_not_contain' => null,
        ];

        return $recipe;
    }
}
