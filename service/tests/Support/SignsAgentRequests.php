<?php

namespace Tests\Support;

use App\Services\Security\RequestSigner;

trait SignsAgentRequests
{
    /**
     * Builds the headers a legitimate agent would send, mirroring
     * agent/lib/Client.php exactly.
     *
     * @return array<string, string>
     */
    protected function agentHeaders(string $method, string $path, string $body, string $serverPublicId, string $token, string $secret, ?string $timestampOverride = null, ?string $nonceOverride = null): array
    {
        $signer = app(RequestSigner::class);
        $timestamp = $timestampOverride ?? (string) time();
        $nonce = $nonceOverride ?? bin2hex(random_bytes(16));
        $signature = $signer->sign($method, $path, $timestamp, $nonce, $body, $secret);

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Deployment-Server-Id' => $serverPublicId,
            'X-Deployment-Timestamp' => $timestamp,
            'X-Deployment-Nonce' => $nonce,
            'X-Deployment-Signature' => $signature,
        ];
    }

    /**
     * Headers for the agent self-registration handshake — same RequestSigner
     * algorithm, keyed by the one-time registration_token instead of a
     * signing_secret, on its own distinct header names (see
     * AgentRegistrationController).
     *
     * @return array<string, string>
     */
    protected function registrationHeaders(string $method, string $path, string $body, string $registrationId, string $registrationToken, ?string $timestampOverride = null, ?string $nonceOverride = null): array
    {
        $signer = app(RequestSigner::class);
        $timestamp = $timestampOverride ?? (string) time();
        $nonce = $nonceOverride ?? bin2hex(random_bytes(16));
        $signature = $signer->sign($method, $path, $timestamp, $nonce, $body, $registrationToken);

        return [
            'X-Deployment-Registration-Id' => $registrationId,
            'X-Deployment-Registration-Timestamp' => $timestamp,
            'X-Deployment-Registration-Nonce' => $nonce,
            'X-Deployment-Registration-Signature' => $signature,
        ];
    }
}
