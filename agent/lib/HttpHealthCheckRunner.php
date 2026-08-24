<?php

declare(strict_types=1);

namespace DeploymentAgent;

final class HttpHealthCheckRunner
{
    /** @param array<string, mixed> $config @return array{status:string,output:string,message:?string,failureCode:?string,durationMs:int,httpStatus:int|null,attempts:int,timedOut:bool} */
    public function run(array $config): array
    {
        $url = (string) ($config['url'] ?? '');
        $expectedStatus = (int) ($config['expected_status'] ?? 200);
        $timeout = (int) ($config['request_timeout_seconds'] ?? $config['timeout_seconds'] ?? 15);
        $maxAttempts = 1 + (int) ($config['retries'] ?? 0);
        $delay = (int) ($config['retry_delay_seconds'] ?? $config['retry_interval_seconds'] ?? 5);
        $followRedirects = (bool) ($config['allowed_redirects'] ?? false);
        $expectedContent = $config['expected_content'] ?? null;
        $mustNotContain = $config['must_not_contain'] ?? null;
        $started = microtime(true);
        $lastReason = 'health request was not attempted';
        $lastStatus = null;
        $lastTimedOut = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => $followRedirects,
                CURLOPT_MAXREDIRS => $followRedirects ? 5 : 0,
            ]);
            $body = curl_exec($ch);
            $lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $lastTimedOut = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
            curl_close($ch);

            $statusOk = $body !== false && $lastStatus === $expectedStatus;
            $contentOk = $expectedContent === null || ($body !== false && str_contains($body, (string) $expectedContent));
            $forbiddenOk = $mustNotContain === null || ($body !== false && !str_contains($body, (string) $mustNotContain));

            if ($statusOk && $contentOk && $forbiddenOk) {
                $message = "{$url} returned HTTP {$lastStatus} and passed content verification on attempt {$attempt}.";
                return [
                    'status' => 'succeeded', 'output' => $message, 'message' => null,
                    'failureCode' => null, 'durationMs' => (int) round((microtime(true) - $started) * 1000),
                    'httpStatus' => $lastStatus, 'attempts' => $attempt, 'timedOut' => false,
                ];
            }

            $lastReason = $body === false
                ? "request failed: {$error}"
                : (!$statusOk
                    ? "expected HTTP {$expectedStatus}, got {$lastStatus}"
                    : (!$contentOk ? 'expected content marker was absent' : 'response contained a forbidden marker'));

            if ($attempt < $maxAttempts && $delay > 0) {
                sleep($delay);
            }
        }

        $message = "{$url} failed customer-facing HTTP health verification after {$maxAttempts} attempt(s): {$lastReason}.";
        return [
            'status' => 'failed', 'output' => $message, 'message' => $message,
            'failureCode' => 'health_check_failed', 'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'httpStatus' => $lastStatus, 'attempts' => $maxAttempts, 'timedOut' => $lastTimedOut,
        ];
    }
}
