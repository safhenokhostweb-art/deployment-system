<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Verifies that a job payload returned by the service was actually issued
 * by a party holding this server's signing_secret, and was not altered in
 * transit or by a compromised intermediary. This is separate from request
 * signing (Signer): request signing authenticates the HTTP call, this
 * authenticates the deployment instructions themselves before anything is
 * ever executed.
 *
 * Canonical string layout MUST match
 * service/app/Services/Security/JobPayloadSigner.php exactly.
 */
final class JobVerifier
{
    public static function canonicalString(array $job): string
    {
        $pipelineCanonical = implode("\n", array_map(
            static fn (array $step): string => ($step['name'] ?? '') . '=' . ($step['command'] ?? ''),
            $job['pipeline'] ?? []
        ));

        return implode('|', [
            (string) ($job['id'] ?? ''),
            (string) ($job['project_key'] ?? ''),
            (string) ($job['action'] ?? ''),
            (string) ($job['commit_hash'] ?? ''),
            (string) ($job['previous_commit_hash'] ?? ''),
            (string) ($job['branch'] ?? ''),
            (string) ($job['issued_at'] ?? ''),
            hash('sha256', $pipelineCanonical),
        ]);
    }

    public static function verify(array $job, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', self::canonicalString($job), $secret);

        return hash_equals($expected, $signature);
    }
}
