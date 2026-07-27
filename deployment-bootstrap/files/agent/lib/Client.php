<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Talks to the Deployment Service. Every request is bearer-authenticated AND
 * HMAC-signed (timestamp + nonce + body digest) so a leaked token alone is
 * not enough to forge a request, and a captured request cannot be replayed.
 *
 * The agent only ever makes OUTBOUND requests (polling). The service never
 * connects to the agent, so the production server needs no inbound ports
 * open and no public SSH.
 */
final class Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serverId,
        private readonly string $agentToken,
        private readonly string $signingSecret,
        private readonly int $timeoutSeconds,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = Signer::nonce();
        $signature = Signer::sign($method, $path, $timestamp, $nonce, $body, $this->signingSecret);

        $url = rtrim($this->baseUrl, '/') . $path;

        $headers = [
            'Authorization: Bearer ' . $this->agentToken,
            'X-Deployment-Server-Id: ' . $this->serverId,
            'X-Deployment-Timestamp: ' . $timestamp,
            'X-Deployment-Nonce: ' . $nonce,
            'X-Deployment-Signature: ' . $signature,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error("Request to {$path} failed: {$error}");

            throw new \RuntimeException("Connection to deployment service failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = [];
        if ($response !== '') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->logger->error("Non-JSON response from {$path} (HTTP {$status}): " . substr($response, 0, 500));
                $decoded = [];
            }
        }

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
