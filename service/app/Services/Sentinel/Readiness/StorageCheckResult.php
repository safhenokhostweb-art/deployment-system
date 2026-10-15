<?php

namespace App\Services\Sentinel\Readiness;

/**
 * StorageCheckResult — the structured result of one storage probe.
 *
 * Every probe returns a status from exactly four values:
 *
 *   MEASURED   — the host answered with a concrete measurement that
 *                 satisfies the documented policy
 *   POLICY     — the check is governed by a documented policy value
 *                 (a threshold, a required filesystem, a required
 *                 path shape) that the caller supplied explicitly
 *   VIOLATED   — the host answered, or the policy resolved, in a way
 *                 that contradicts the documented policy
 *   UNKNOWN    — the probe could not be answered
 *
 * MEASURED and POLICY are distinct because a policy threshold is
 * not a measurement: conflating them would let a caller move the
 * goalposts after the fact. A check whose measurement is UNKNOWN
 * can never aggregate to PASS, regardless of what the policy says.
 * A check whose result is VIOLATED always aggregates to BLOCKED.
 *
 * This class is immutable and serializes to a sanitized array that
 * contains no secrets, no absolute user-home paths and no raw
 * exception text.
 */
final class StorageCheckResult
{
    public const STATUS_MEASURED = 'MEASURED';
    public const STATUS_POLICY = 'POLICY';
    public const STATUS_VIOLATED = 'VIOLATED';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const STATUSES = [
        self::STATUS_MEASURED,
        self::STATUS_POLICY,
        self::STATUS_VIOLATED,
        self::STATUS_UNKNOWN,
    ];

    /**
     * @param  string  $id               stable check identifier
     * @param  string  $status           MEASURED | POLICY | VIOLATED | UNKNOWN
     * @param  string  $reasonCode       stable machine-readable reason
     * @param  mixed   $measuredValue    the raw measurement, or null
     * @param  mixed   $policyValue      the policy threshold/requirement, or null
     * @param  string  $observedAt       ISO-8601 timestamp of the probe
     */
    private function __construct(
        private readonly string $id,
        private readonly string $status,
        private readonly string $reasonCode,
        private readonly mixed $measuredValue,
        private readonly mixed $policyValue,
        private readonly string $observedAt,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(
                "StorageCheckResult status must be MEASURED, POLICY, VIOLATED or UNKNOWN, got '{$status}'.",
            );
        }
    }

    /**
     * @param  mixed  $measuredValue
     * @param  mixed  $policyValue
     */
    public static function measured(string $id, string $reasonCode, mixed $measuredValue, mixed $policyValue, string $observedAt): self
    {
        return new self($id, self::STATUS_MEASURED, $reasonCode, $measuredValue, $policyValue, $observedAt);
    }

    /**
     * @param  mixed  $policyValue
     * @param  mixed  $measuredValue Actual observation; null remains policy-only.
     */
    public static function policy(string $id, string $reasonCode, mixed $policyValue, string $observedAt, mixed $measuredValue = null): self
    {
        return new self($id, self::STATUS_POLICY, $reasonCode, $measuredValue, $policyValue, $observedAt);
    }

    /**
     * A policy-governed check whose observation contradicts the
     * documented policy. Always aggregates to BLOCKED.
     *
     * @param  mixed  $measuredValue
     * @param  mixed  $policyValue
     */
    public static function violated(string $id, string $reasonCode, mixed $measuredValue, mixed $policyValue, string $observedAt): self
    {
        return new self($id, self::STATUS_VIOLATED, $reasonCode, $measuredValue, $policyValue, $observedAt);
    }

    public static function unknown(string $id, string $reasonCode, string $observedAt): self
    {
        return new self($id, self::STATUS_UNKNOWN, $reasonCode, null, null, $observedAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function measuredValue(): mixed
    {
        return $this->measuredValue;
    }

    public function policyValue(): mixed
    {
        return $this->policyValue;
    }

    public function observedAt(): string
    {
        return $this->observedAt;
    }

    public function isMeasured(): bool
    {
        return $this->status === self::STATUS_MEASURED;
    }

    public function isPolicy(): bool
    {
        return $this->status === self::STATUS_POLICY;
    }

    public function isViolated(): bool
    {
        return $this->status === self::STATUS_VIOLATED;
    }

    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
    }

    /**
     * Candidate status only; StorageReport validates raw evidence and effective policy.
     * A check whose measurement is UNKNOWN can never be treated as a
     * pass, even if the policy threshold would be satisfied. VIOLATED
     * likewise never contributes to a pass.
     */
    public function canContributeToPass(): bool
    {
        return $this->status === self::STATUS_MEASURED || $this->status === self::STATUS_POLICY;
    }

    /**
     * @return array{id: string, status: string, reasonCode: string,
     *               measured: mixed, policy: mixed, observedAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reasonCode' => $this->reasonCode,
            'measured' => $this->measuredValue,
            'policy' => $this->policyValue,
            'observedAt' => $this->observedAt,
        ];
    }
}
