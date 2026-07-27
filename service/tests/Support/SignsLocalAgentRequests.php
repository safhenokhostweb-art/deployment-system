<?php

namespace Tests\Support;

use App\Services\Security\RequestSigner;

trait SignsLocalAgentRequests
{
    /**
     * Builds the headers a legitimate Local Agent would send, mirroring
     * local-agent/lib/Client.php exactly.
     *
     * @return array<string, string>
     */
    protected function localAgentHeaders(string $method, string $path, string $body, string $localAgentPublicId, string $token, string $secret, ?string $timestampOverride = null, ?string $nonceOverride = null): array
    {
        $signer = app(RequestSigner::class);
        $timestamp = $timestampOverride ?? (string) time();
        $nonce = $nonceOverride ?? bin2hex(random_bytes(16));
        $signature = $signer->sign($method, $path, $timestamp, $nonce, $body, $secret);

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Deployment-Local-Agent-Id' => $localAgentPublicId,
            'X-Deployment-Timestamp' => $timestamp,
            'X-Deployment-Nonce' => $nonce,
            'X-Deployment-Signature' => $signature,
        ];
    }

    /**
     * Headers for the Local Agent self-registration handshake — same
     * RequestSigner algorithm, keyed by the one-time registration_token
     * instead of a signing_secret, on its own distinct header names (see
     * LocalAgentRegistrationController).
     *
     * @return array<string, string>
     */
    protected function localRegistrationHeaders(string $method, string $path, string $body, string $registrationId, string $registrationToken, ?string $timestampOverride = null, ?string $nonceOverride = null): array
    {
        $signer = app(RequestSigner::class);
        $timestamp = $timestampOverride ?? (string) time();
        $nonce = $nonceOverride ?? bin2hex(random_bytes(16));
        $signature = $signer->sign($method, $path, $timestamp, $nonce, $body, $registrationToken);

        return [
            'X-Deployment-Local-Registration-Id' => $registrationId,
            'X-Deployment-Local-Registration-Timestamp' => $timestamp,
            'X-Deployment-Local-Registration-Nonce' => $nonce,
            'X-Deployment-Local-Registration-Signature' => $signature,
        ];
    }
}
