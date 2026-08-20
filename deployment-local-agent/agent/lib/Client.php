<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Talks to the Deployment Hub. Every request is bearer-authenticated AND
 * HMAC-signed (timestamp + nonce + body digest) — the same scheme the
 * production Server Agent's lib/Client.php uses, just under
 * X-Deployment-Local-Agent-Id instead of X-Deployment-Server-Id (the Hub
 * resolves these two identities through separate, independent
 * middleware — see VerifyLocalAgentRequestSignature).
 *
 * Only ever makes OUTBOUND requests (polling). The Hub never connects to
 * the developer's machine — no inbound port, no public SSH, no NAT/
 * firewall configuration required on the developer's side.
 */
final class Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $localAgentId,
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
    /**
     * Root-cause fix (Local Agent network resilience): the Hub is reached
     * through a CDN (and, on a VPN-routed machine like this one, a tunnel)
     * whose DNS resolution and HTTPS round trips are intermittently slow
     * or briefly failing. A single immediate retry with a FRESH
     * timestamp/nonce/signature — a re-signed request is required because
     * if the first attempt actually reached the Hub but its response was
     * lost, its nonce was already consumed — turns those transient stalls
     * into successes without ever weakening the bearer/HMAC scheme. Only
     * transport-level failures are retried; an HTTP status response is a
     * valid answer and is returned to the caller unchanged.
     */
    private const MAX_TRANSPORT_ATTEMPTS = 2;

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $url = rtrim($this->baseUrl, '/') . $path;

        for ($attempt = 1; $attempt <= self::MAX_TRANSPORT_ATTEMPTS; $attempt++) {
            $timestamp = (string) time();
            $nonce = Security::nonce();
            $signature = Security::sign($method, $path, $timestamp, $nonce, $body, $this->signingSecret);

            $headers = [
                'Authorization: Bearer ' . $this->agentToken,
                'X-Deployment-Local-Agent-Id: ' . $this->localAgentId,
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

                if ($attempt < self::MAX_TRANSPORT_ATTEMPTS) {
                    $this->logger->warn("Request to {$path} failed (attempt {$attempt}/" . self::MAX_TRANSPORT_ATTEMPTS . "): {$error} — retrying with a fresh signature.");
                    sleep(1);

                    continue;
                }

                $this->logger->error("Request to {$path} failed: {$error}");

                throw new \RuntimeException("Connection to Deployment Hub failed: {$error}");
            }

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = [];
            if ($response !== '') {
                try {
                    $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->logger->error("Non-JSON response from {$path} (HTTP {$status}): " . self::redactForLog(substr($response, 0, 500)));
                    $decoded = [];
                }
            }

            return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
        }

        throw new \RuntimeException('Connection to Deployment Hub failed: unreachable state.');
    }

    /**
     * Root-cause fix (customer-site direct-GitHub-dependency removal):
     * uploads an artifact's raw file bytes as the ENTIRE request body
     * (Content-Type: application/octet-stream) — deliberately not
     * multipart/form-data, which PHP's php://input (what the Hub's HMAC
     * middleware reads to verify the signature) does not support at all.
     * Signs over exactly these bytes, same canonical-string scheme as
     * request()'s JSON body.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function uploadRawBody(string $method, string $path, string $rawBody): array
    {
        $timestamp = (string) time();
        $nonce = Security::nonce();
        $signature = Security::sign($method, $path, $timestamp, $nonce, $rawBody, $this->signingSecret);

        $url = rtrim($this->baseUrl, '/') . $path;

        $headers = [
            'Authorization: Bearer ' . $this->agentToken,
            'X-Deployment-Local-Agent-Id: ' . $this->localAgentId,
            'X-Deployment-Timestamp: ' . $timestamp,
            'X-Deployment-Nonce: ' . $nonce,
            'X-Deployment-Signature: ' . $signature,
            'Content-Type: application/octet-stream',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            // A large artifact upload legitimately needs longer than this
            // agent's normal, small-JSON-payload request timeout — the
            // caller (ArtifactUploader) passes one sized for its file.
            CURLOPT_TIMEOUT => max($this->timeoutSeconds, 300),
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            // Root-cause fix (artifact upload over a slow VPN path): a large
            // upload can legitimately take minutes to push through the tunnel,
            // but a transfer that is completely stalled (under 1 byte/sec for
            // 60s) must fail fast instead of holding the connection until the
            // full 300s timeout.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error("Artifact upload to {$path} failed: {$error}");

            throw new \RuntimeException("Connection to Deployment Hub failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = [];
        if ($response !== '') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->logger->error("Non-JSON response from {$path} (HTTP {$status}): " . self::redactForLog(substr($response, 0, 500)));
                $decoded = [];
            }
        }

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * Phase 5.5: this agent's own token/secret never reach a log line
     * (they only ever go into curl headers, see request() above) — but an
     * unrecognized Hub response body is logged raw on a JSON-decode
     * failure, and that response is server-controlled text this agent
     * doesn't otherwise inspect. Mirrors SecretRedactor's key=value/Bearer
     * patterns (conceptually, not by reusing the class — local-agent/ has
     * zero Composer dependencies by design) as a defensive last line, not
     * because a leak is expected here.
     */
    private static function redactForLog(string $text): string
    {
        $text = preg_replace('/(api[_-]?key|secret|token|password|passwd|access[_-]?key|private[_-]?key)("?\s*[:=]\s*"?)[^\s"&,}]+/i', '$1$2[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(bearer\s+)[A-Za-z0-9._-]{16,}/i', '$1[REDACTED]', $text) ?? $text;

        return $text;
    }
}
