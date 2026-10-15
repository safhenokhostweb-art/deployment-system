<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

/**
 * NO implementation or issued receipts exist in this phase.
 * A future broker must be outside the untrusted Laravel process, authenticate
 * its observer/recipient, and independently bind every operation to RUN_ID,
 * a broker-owned session, purpose, expiry and a single-use sequence/nonce.
 * Caller status strings, booleans and fromMetadata() objects are not authority.
 * Only a private issuance registry can accept registered live leases; deny
 * forged, stale, replayed, cross-session, expired and revoked descriptions.
 * Generate separate CSPRNG secrets (at least 32 random bytes), never export
 * values or use environment/CLI/files/stdout for transfer. Verify containment,
 * ACLs and bootstrap through a real trusted observer before application handoff.
 * Revoke on expiry/interruption/end, invalidate sessions and handoff nonces,
 * destroy secret material, and preserve sanitized evidence. Cleanup is idempotent.
 */
interface MysqlCredentialBroker
{
    public function generate(MysqlCredentialPolicy $policy, string $purpose, string $sessionId,
        int $now, int $expiresAt): MysqlSecretLease;

    public function handoff(MysqlCredentialPolicy $policy, MysqlSecretLease $lease, string $sessionId, int $now): void;

    public function cleanup(MysqlCredentialPolicy $policy, MysqlSecretLease $lease, string $sessionId): void;
}
