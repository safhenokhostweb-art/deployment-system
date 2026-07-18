<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * HMAC request/payload signing shared by the agent and the service.
 * The canonical string format and algorithm here MUST stay identical to
 * service/app/Services/Security/RequestSigner.php.
 */
final class Signer
{
    public static function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function sign(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::canonicalString($method, $path, $timestamp, $nonce, $body), $secret);
    }

    public static function verify(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret, string $signature): bool
    {
        $expected = self::sign($method, $path, $timestamp, $nonce, $body, $secret);

        return hash_equals($expected, $signature);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
