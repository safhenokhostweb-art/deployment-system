<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HypervCheckResult — the structured result of one Hyper-V capability
 * probe.
 *
 * Every probe returns a status from exactly five values:
 *
 *   ACCESS_DENIED  — the host answered but refused the observation
 *   NOT_INSTALLED  — the capability is provably absent
 *   UNAVAILABLE    — the capability cannot be reached from here
 *   PRESENT        — the capability is present and reachable; the only
 *                    status that maps to a PASS outcome
 *   UNKNOWN        — the probe could not be answered
 *
 * These are deliberately distinct from the readiness outcomes.
 * ACCESS_DENIED is not BLOCKED: it is a statement about the observer's
 * authority, not about the host's capability. A caller must not
 * promote ACCESS_DENIED to a capability claim, and must not infer
 * management authorization merely from the presence of Hyper-V
 * modules.
 *
 * This class is immutable and serializes to a sanitized array.
 */
final class HypervCheckResult
{
    public const STATUS_ACCESS_DENIED = 'ACCESS_DENIED';
    public const STATUS_NOT_INSTALLED = 'NOT_INSTALLED';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';
    public const STATUS_PRESENT = 'PRESENT';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const STATUSES = [
        self::STATUS_ACCESS_DENIED,
        self::STATUS_NOT_INSTALLED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_PRESENT,
        self::STATUS_UNKNOWN,
    ];

    /**
     * @param  string  $id          stable check identifier
     * @param  string  $status      one of the five statuses above
     * @param  string  $reasonCode  stable machine-readable reason
     * @param  mixed   $measured    the raw measurement, or null
     * @param  string  $observedAt  ISO-8601 timestamp of the probe
     */
    private function __construct(
        private readonly string $id,
        private readonly string $status,
        private readonly string $reasonCode,
        private readonly mixed $measured,
        private readonly string $observedAt,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(
                "HypervCheckResult status must be ACCESS_DENIED, NOT_INSTALLED, UNAVAILABLE, PRESENT or UNKNOWN, got '{$status}'.",
            );
        }
    }

    /**
     * @param  mixed  $measured
     */
    public static function accessDenied(string $id, string $reasonCode, mixed $measured, string $observedAt): self
    {
        return new self($id, self::STATUS_ACCESS_DENIED, $reasonCode, $measured, $observedAt);
    }

    /**
     * A capability that is present and reachable. This is the only
     * status that maps to a PASS outcome; it is used solely for
     * positive capability observations, never for authorization.
     */
    public static function present(string $id, string $reasonCode, mixed $measured, string $observedAt): self
    {
        return new self($id, self::STATUS_PRESENT, $reasonCode, $measured, $observedAt);
    }

    /**
     * @param  mixed  $measured
     */
    public static function notInstalled(string $id, string $reasonCode, mixed $measured, string $observedAt): self
    {
        return new self($id, self::STATUS_NOT_INSTALLED, $reasonCode, $measured, $observedAt);
    }

    /**
     * @param  mixed  $measured
     */
    public static function unavailable(string $id, string $reasonCode, mixed $measured, string $observedAt): self
    {
        return new self($id, self::STATUS_UNAVAILABLE, $reasonCode, $measured, $observedAt);
    }

    public static function unknown(string $id, string $reasonCode, string $observedAt): self
    {
        return new self($id, self::STATUS_UNKNOWN, $reasonCode, null, $observedAt);
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

    public function measured(): mixed
    {
        return $this->measured;
    }

    public function observedAt(): string
    {
        return $this->observedAt;
    }

    /**
     * Map a capability status to a readiness outcome. Only PRESENT
     * maps to PASS. ACCESS_DENIED, NOT_INSTALLED and UNAVAILABLE all
     * map to BLOCKED because the host cannot proceed; UNKNOWN maps to
     * UNKNOWN because the probe was not answered.
     */
    public function toOutcome(): string
    {
        return match ($this->status) {
            self::STATUS_PRESENT => HostReadinessOutcome::PASS,
            self::STATUS_UNKNOWN => HostReadinessOutcome::UNKNOWN,
            default => HostReadinessOutcome::BLOCKED,
        };
    }

    /**
     * @return array{id: string, status: string, reasonCode: string,
     *               measured: mixed, observedAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reasonCode' => $this->reasonCode,
            'measured' => $this->measured,
            'observedAt' => $this->observedAt,
        ];
    }
}