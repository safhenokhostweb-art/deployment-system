<?php

namespace App\Services\Security;

/**
 * HMAC request signing shared with the agent. Canonical string layout MUST
 * stay identical to agent/lib/Signer.php on the agent side.
 */
class RequestSigner
{
    public function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public function sign(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', $this->canonicalString($method, $path, $timestamp, $nonce, $body), $secret);
    }

    public function verify(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret, string $signature): bool
    {
        return hash_equals($this->sign($method, $path, $timestamp, $nonce, $body, $secret), $signature);
    }
}
