<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use InvalidArgumentException;
use JsonSerializable;

/** Untrusted metadata description, NOT an issued or usable secret capability. */
final class MysqlSecretLease implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = ['runId', 'purpose', 'issuedAt', 'expiresAt'];

    private function __construct(MysqlRunOwnership $owner, string $reference, string $purpose,
        int $issuedAt, int $expiresAt, string $sessionId)
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $reference) || !preg_match('/^[0-9a-f]{64}$/D', $sessionId)
            || !in_array($purpose, ['DATABASE_CONTROL', 'APPLICATION'], true)
            || $issuedAt < 0 || $expiresAt <= $issuedAt || $expiresAt - $issuedAt > 900) {
            throw new InvalidArgumentException('proof_mysql_secret_policy_rejected');
        }
        $this->remember(['reference' => $reference, 'runId' => $owner->runId, 'purpose' => $purpose,
            'issuedAt' => $issuedAt, 'expiresAt' => $expiresAt, 'sessionId' => $sessionId]);
    }

    public static function fromMetadata(MysqlRunOwnership $owner, string $reference, string $purpose,
        int $issuedAt, int $expiresAt, string $sessionId): self
    {
        return new self($owner, $reference, $purpose, $issuedAt, $expiresAt, $sessionId);
    }

    /** Syntax/scope checks only. Matching metadata does not authorize handoff or issuance. */
    public function assertMetadataMatches(MysqlRunOwnership $owner, string $purpose, string $sessionId, int $now): void
    {
        $data = $this->contractState();
        if ($owner->runId !== $data['runId'] || $purpose !== $data['purpose'] || $sessionId !== $data['sessionId']
            || $now < $data['issuedAt'] || $now >= $data['expiresAt']) {
            throw new InvalidArgumentException('proof_mysql_secret_policy_rejected');
        }
    }

    public function assertUsable(MysqlRunOwnership $owner, string $purpose, int $now): never
    {
        $this->contractState();
        throw new InvalidArgumentException('proof_mysql_credential_broker_unavailable');
    }

    public function hasSameReferenceAs(self $other): bool
    {
        return hash_equals($this->contractState()['reference'], $other->contractState()['reference']);
    }

    public function jsonSerialize(): array
    {
        $this->contractState();
        return ['secret' => '[REDACTED]', 'issuance' => 'UNTRUSTED_METADATA'];
    }
}
