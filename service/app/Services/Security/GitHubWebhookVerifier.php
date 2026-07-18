<?php

namespace App\Services\Security;

class GitHubWebhookVerifier
{
    /**
     * Verifies the `X-Hub-Signature-256` header GitHub sends with every
     * webhook delivery against the per-project secret configured in the
     * GitHub repo settings.
     */
    public function verify(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
