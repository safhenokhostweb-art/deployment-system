<?php

namespace App\Services\Sentinel\Readiness;

/**
 * StoragePolicy — the documented, configurable minimum storage
 * requirements for the Sentinel host.
 *
 * Every value here is a policy constant, not a measurement. A
 * measurement comes from HostReadinessObserver::observeFreeSpace();
 * this object supplies the threshold against which the measurement
 * is compared.
 *
 * The defaults are explicit and documented:
 *
 *   minimumBytesFree — the absolute floor. A host with less free
 *     space than this is BLOCKED regardless of safety reserve.
 *   safetyReserveBytes — an additional buffer on top of the minimum
 *     that the host must keep free after the required allocation is
 *     accounted for. This prevents a host from passing preflight
 *     only to fail mid-deploy when other consumers consume the
 *     remaining volume.
 *   requireNtfs — when true the volume must report an NTFS
 *     filesystem. ExFAT/FAT32/reFS are BLOCKED.
 *   requireFixedVolume — when true the path must resolve to a fixed
 *     (non-removable) volume. A removable drive is BLOCKED.
 *
 * A caller may supply a custom policy via fromArray(); unknown keys
 * are rejected so a caller cannot smuggle in a "verified" flag or
 * an authorization token.
 */
final class StoragePolicy
{
    public const KEY_MINIMUM_BYTES_FREE = 'minimum_bytes_free';
    public const KEY_SAFETY_RESERVE_BYTES = 'safety_reserve_bytes';
    public const KEY_REQUIRE_NTFS = 'require_ntfs';
    public const KEY_REQUIRE_FIXED_VOLUME = 'require_fixed_volume';

    public const ALLOWED_KEYS = [
        self::KEY_MINIMUM_BYTES_FREE,
        self::KEY_SAFETY_RESERVE_BYTES,
        self::KEY_REQUIRE_NTFS,
        self::KEY_REQUIRE_FIXED_VOLUME,
    ];

    /**
     * Default Sentinel storage policy. These values are documented
     * and stable; changing them is a policy decision, not a bug fix.
     */
    public const DEFAULT_MINIMUM_BYTES_FREE = 2147483648;      // 2 GiB absolute floor
    public const DEFAULT_SAFETY_RESERVE_BYTES = 1073741824;     // 1 GiB safety reserve
    public const DEFAULT_REQUIRE_NTFS = true;
    public const DEFAULT_REQUIRE_FIXED_VOLUME = true;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function __construct(
        private readonly int $minimumBytesFree,
        private readonly int $safetyReserveBytes,
        private readonly bool $requireNtfs,
        private readonly bool $requireFixedVolume,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_MINIMUM_BYTES_FREE,
            self::DEFAULT_SAFETY_RESERVE_BYTES,
            self::DEFAULT_REQUIRE_NTFS,
            self::DEFAULT_REQUIRE_FIXED_VOLUME,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @throws \InvalidArgumentException when an unknown key is supplied
     */
    public static function fromArray(array $overrides): self
    {
        foreach (array_keys($overrides) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "StoragePolicy field '{$key}' is not a recognized policy key.",
                );
            }
        }

        $minimum = array_key_exists(self::KEY_MINIMUM_BYTES_FREE, $overrides) ? $overrides[self::KEY_MINIMUM_BYTES_FREE] : self::DEFAULT_MINIMUM_BYTES_FREE;
        $reserve = array_key_exists(self::KEY_SAFETY_RESERVE_BYTES, $overrides) ? $overrides[self::KEY_SAFETY_RESERVE_BYTES] : self::DEFAULT_SAFETY_RESERVE_BYTES;
        $requireNtfs = array_key_exists(self::KEY_REQUIRE_NTFS, $overrides) ? $overrides[self::KEY_REQUIRE_NTFS] : self::DEFAULT_REQUIRE_NTFS;
        $requireFixed = array_key_exists(self::KEY_REQUIRE_FIXED_VOLUME, $overrides) ? $overrides[self::KEY_REQUIRE_FIXED_VOLUME] : self::DEFAULT_REQUIRE_FIXED_VOLUME;

        if (! is_int($minimum) || $minimum < 0) {
            throw new \InvalidArgumentException('minimum_bytes_free must be a non-negative integer.');
        }

        if (! is_int($reserve) || $reserve < 0) {
            throw new \InvalidArgumentException('safety_reserve_bytes must be a non-negative integer.');
        }

        if (! is_bool($requireNtfs)) {
            throw new \InvalidArgumentException('require_ntfs must be a boolean.');
        }

        if (! is_bool($requireFixed)) {
            throw new \InvalidArgumentException('require_fixed_volume must be a boolean.');
        }

        return new self($minimum, $reserve, $requireNtfs, $requireFixed);
    }

    public function minimumBytesFree(): int
    {
        return $this->minimumBytesFree;
    }

    public function safetyReserveBytes(): int
    {
        return $this->safetyReserveBytes;
    }

    public function requireNtfs(): bool
    {
        return $this->requireNtfs;
    }

    public function requireFixedVolume(): bool
    {
        return $this->requireFixedVolume;
    }

    /**
     * The effective floor the host must exceed: the documented
     * minimum plus the safety reserve. A measurement below this is
     * BLOCKED even if it clears the absolute minimum.
     *
     * @throws StoragePolicyOverflowException when the sum of the two
     *         documented policy values exceeds PHP_INT_MAX. The sum is
     *         a policy value, not a measurement: an overflowed floor
     *         would silently wrap to a small or negative number and
     *         let a host with almost no free space PASS. Failing
     *         closed here is the only safe outcome — the policy itself
     *         is unsatisfiable and must be corrected, not clamped.
     */
    public function effectiveFloorBytes(): int
    {
        $minimum = $this->minimumBytesFree;
        $reserve = $this->safetyReserveBytes;

        // Detect overflow BEFORE adding. In PHP, adding two large ints
        // silently produces a float, which would then fail the int
        // return type with a TypeError instead of the documented
        // fail-closed exception. Comparing the operands against the
        // remaining headroom catches the overflow while both values
        // are still ints.
        if ($reserve > 0 && $minimum > PHP_INT_MAX - $reserve) {
            throw new StoragePolicyOverflowException(
                'storage_policy_effective_floor_overflow',
            );
        }

        $sum = $minimum + $reserve;

        // Defensive: if the sum somehow wrapped to a negative value,
        // never let it be compared against a measurement.
        if ($sum < 0) {
            throw new StoragePolicyOverflowException(
                'storage_policy_effective_floor_overflow',
            );
        }

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::KEY_MINIMUM_BYTES_FREE => $this->minimumBytesFree,
            self::KEY_SAFETY_RESERVE_BYTES => $this->safetyReserveBytes,
            self::KEY_REQUIRE_NTFS => $this->requireNtfs,
            self::KEY_REQUIRE_FIXED_VOLUME => $this->requireFixedVolume,
            'effective_floor_bytes' => $this->effectiveFloorBytes(),
        ];
    }
}
