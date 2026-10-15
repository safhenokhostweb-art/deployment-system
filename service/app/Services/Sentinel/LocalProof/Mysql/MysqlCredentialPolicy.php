<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use InvalidArgumentException;
use JsonSerializable;

/** Metadata validation only. There is no trusted credential broker in this phase. */
final class MysqlCredentialPolicy implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = [];

    public function __construct(MysqlRunOwnership $owner)
    {
        $owner->assertRun($owner->runId);
        $this->remember(['owner' => $owner]);
    }

    public function assertDistinctLeases(MysqlSecretLease $control, MysqlSecretLease $application,
        int $now, string $sessionId): void
    {
        $owner = $this->contractState()['owner'];
        $control->assertMetadataMatches($owner, 'DATABASE_CONTROL', $sessionId, $now);
        $application->assertMetadataMatches($owner, 'APPLICATION', $sessionId, $now);
        if ($control->hasSameReferenceAs($application)) {
            throw new InvalidArgumentException('proof_mysql_secret_reuse_rejected');
        }
    }

    /** Arbitrary flags/objects, even all true, are not authenticated broker observations. */
    public function assertHandoff(MysqlSecretLease $lease, int $now, array $requirements): never
    {
        $this->contractState();
        throw new InvalidArgumentException('proof_mysql_credential_broker_unavailable');
    }

    public function assertCleanup(MysqlSecretLease $lease, string $runId, array $requirements): never
    {
        $this->contractState();
        throw new InvalidArgumentException('proof_mysql_credential_broker_unavailable');
    }
}
