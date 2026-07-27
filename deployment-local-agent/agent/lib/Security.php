<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * HMAC request signing shared with the Hub. The canonical string format
 * and algorithm here MUST stay identical to
 * service/app/Services/Security/RequestSigner.php (the same class the
 * production Server Agent's lib/Signer.php already mirrors) — this is a
 * separate file, in a separate process, on purpose: the Local Agent must
 * never depend on anything inside saas-platform/deployment-system's
 * Laravel app, and must never share a codebase with the Server Agent.
 */
final class Security
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
        return hash_equals(self::sign($method, $path, $timestamp, $nonce, $body, $secret), $signature);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
