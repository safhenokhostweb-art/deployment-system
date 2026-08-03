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

    /**
     * Root-cause fix (customer-site direct-GitHub-dependency removal):
     * downloads a signed GET response's body directly to a file
     * (CURLOPT_FILE) rather than buffering it into a PHP string first —
     * unlike request()'s JSON responses (small by construction), an
     * artifact can be tens of megabytes, and there's no reason to hold
     * the whole thing in memory just to then write it back out.
     *
     * @return array{status: int, error: ?string}
     */
    public function downloadToFile(string $path, string $destinationPath): array
    {
        $timestamp = (string) time();
        $nonce = Signer::nonce();
        $signature = Signer::sign('GET', $path, $timestamp, $nonce, '', $this->signingSecret);

        $url = rtrim($this->baseUrl, '/') . $path;

        $fileHandle = fopen($destinationPath, 'wb');
        if ($fileHandle === false) {
            return ['status' => 0, 'error' => "Could not open {$destinationPath} for writing."];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->agentToken,
                'X-Deployment-Server-Id: ' . $this->serverId,
                'X-Deployment-Timestamp: ' . $timestamp,
                'X-Deployment-Nonce: ' . $nonce,
                'X-Deployment-Signature: ' . $signature,
                'Accept: application/octet-stream',
            ],
            CURLOPT_FILE => $fileHandle,
            // A download legitimately needs longer than this agent's
            // normal, small-JSON-payload request timeout.
            CURLOPT_TIMEOUT => max($this->timeoutSeconds, 300),
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $success = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $success === false ? curl_error($ch) : null;
        curl_close($ch);
        fclose($fileHandle);

        if ($error !== null) {
            @unlink($destinationPath);
            $this->logger->error("Artifact download from {$path} failed: {$error}");
        } elseif ($status !== 200) {
            // A non-200 (404/403/409) response body is a small JSON error
            // object, not artifact bytes — the file at $destinationPath is
            // that JSON, not a corrupt archive; delete it either way so
            // the caller never mistakes it for a real artifact.
            @unlink($destinationPath);
        }

        return ['status' => $status, 'error' => $error];
    }
}
