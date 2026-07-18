<?php

namespace App\Services\Security;

use Illuminate\Support\Str;

/**
 * Issues and verifies the two secrets each Server holds:
 *  - agent_token: bearer credential, stored only as a hash (like a Sanctum
 *    personal access token). Identifies the caller.
 *  - signing_secret: shared HMAC key, stored encrypted (recoverable,
 *    because both sides must compute the same signature). Proves requests
 *    and job payloads were not tampered with.
 *
 * Deliberately two different secrets so a leak of one (e.g. a token logged
 * by an intermediary proxy) does not also compromise the other.
 */
class AgentCredentialService
{
    public function generateToken(): string
    {
        return 'agt_' . Str::random(48);
    }

    public function generateSigningSecret(): string
    {
        return 'sec_' . Str::random(48);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verifyToken(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hashToken($token));
    }
}
