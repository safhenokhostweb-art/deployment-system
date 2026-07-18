<?php

namespace App\Services\Security;

/**
 * Signs the job payload handed to an agent, so that even a caller who
 * possesses a valid bearer token but not the server's signing_secret cannot
 * make the agent execute arbitrary commands (defense in depth on top of
 * request signing / TLS). Canonical string layout MUST stay identical to
 * agent/lib/JobVerifier.php on the agent side.
 */
class JobPayloadSigner
{
    public function canonicalString(array $job): string
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

    public function sign(array $job, string $secret): string
    {
        return hash_hmac('sha256', $this->canonicalString($job), $secret);
    }
}
