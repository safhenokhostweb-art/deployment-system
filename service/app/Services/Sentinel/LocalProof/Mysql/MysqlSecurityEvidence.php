<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Negative/unverified descriptions only. Public input is NEVER verification authority.
 * Future observations must originate outside untrusted Laravel, authenticate the
 * observer, and bind RUN_ID, observer-owned session, operation, sequence/nonce and
 * expiry. Reject replay/revocation and session mismatch before any authorization.
 * No such observer or receipts exist here; status strings cannot stand in for one.
 */
final class MysqlSecurityEvidence implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = [];
    private const DEFAULTS = [
        'endpoint_validation' => 'NOT_CHECKED', 'path_containment' => 'NOT_CHECKED',
        'process_ownership' => 'NOT_CHECKED', 'listener_ownership' => 'NOT_CHECKED',
        'os_containment' => 'NOT_VERIFIED', 'credential_bootstrap' => 'NOT_STARTED',
    ];

    private function __construct(MysqlLifecycle $lifecycle, array $checks)
    {
        $this->remember(['lifecycle' => $lifecycle, 'checks' => self::validatedChecks($lifecycle, $checks)]);
    }

    public static function forLifecycle(MysqlLifecycle $lifecycle, array $checks = []): self
    {
        return new self($lifecycle, $checks);
    }

    private static function validatedChecks(MysqlLifecycle $lifecycle, array $input): array
    {
        $cleanup = match ($lifecycle->state) {
            MysqlLifecycleState::STOPPING => 'PENDING',
            MysqlLifecycleState::STOPPED => 'NOT_CHECKED', // A logical state is not an observed OS shutdown.
            MysqlLifecycleState::CLEANUP_FAILED => 'FAILED',
            default => 'NOT_REQUIRED',
        };
        $defaults = self::DEFAULTS + ['cleanup_status' => $cleanup];
        if (array_diff(array_keys($input), array_keys($defaults)) !== []) {
            throw new InvalidArgumentException('proof_mysql_evidence_rejected');
        }
        $copy = [];
        foreach ($defaults as $field => $default) {
            $value = array_key_exists($field, $input) ? $input[$field] : $default;
            $allowed = match ($field) {
                'os_containment' => ['NOT_VERIFIED', 'FAILED'],
                'credential_bootstrap' => ['NOT_STARTED', 'PENDING', 'FAILED'],
                'cleanup_status' => [$cleanup],
                default => ['NOT_CHECKED', 'FAILED'],
            };
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('proof_mysql_evidence_rejected');
            }
            // Assignment from a scalar value strips any reference alias from input.
            $copy[$field] = (string) $value;
        }
        return $copy;
    }

    public function jsonSerialize(): array
    {
        $state = $this->contractState();
        if (array_keys($state) !== ['lifecycle', 'checks'] || !($state['lifecycle'] instanceof MysqlLifecycle)
            || !is_array($state['checks'])) {
            throw new InvalidArgumentException('proof_mysql_evidence_rejected');
        }
        // Revalidate independently at export; do not blindly serialize stored arrays.
        $lifecycleData = $state['lifecycle']->toArray();
        $checks = self::validatedChecks($state['lifecycle'], $state['checks']);
        return $lifecycleData + $checks;
    }

    public function assertGatesFor(MysqlLifecycle $lifecycle, bool $requireBootstrap, bool $requireProcess): never
    {
        $this->contractState();
        throw new InvalidArgumentException('proof_mysql_trusted_observer_unavailable');
    }
}
