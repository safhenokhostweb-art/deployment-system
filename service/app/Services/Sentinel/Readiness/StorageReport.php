<?php

namespace App\Services\Sentinel\Readiness;

/**
 * StorageReport — aggregated storage readiness result.
 *
 * Aggregation is fail-closed:
 *
 *   any check outcome is BLOCKED  -> overall BLOCKED
 *   any check outcome is UNKNOWN  -> overall UNKNOWN
 *   else                         -> PASS
 *
 * A missing root is NEVER reported as fully filesystem-validated.
 * The outcome STORAGE_ROOT_NOT_CREATED is a distinct reasonCode that
 * prevents the aggregate from reaching PASS even when every other
 * check would otherwise pass.
 *
 * This report contains no absolute user-home paths and no raw
 * exception text. Measured values are integers (bytes) or
 * filesystem identifiers; policy values are the documented
 * thresholds from StoragePolicy.
 */
final class StorageReport
{
    /**
     * @param  string                $overall      one of HostReadinessOutcome
     * @param  StorageCheckResult[]  $checks       individual probe results
     * @param  string                $rootPath     the probed root path
     * @param  string                $reasonCode    primary blocker code, or ''
     * @param  bool                  $rootExists   whether the root was observed to exist
     */
    private function __construct(
        private readonly string $overall,
        private readonly array $checks,
        private readonly string $rootPath,
        private readonly string $reasonCode,
        private readonly bool $rootExists,
    ) {
    }

    /**
     * @param  StorageCheckResult[]  $checks
     * @param  StoragePolicy|null $policy The same effective policy used to evaluate
     *                                    these checks, never inferred from evidence.
     * Legacy calls without policy remain accepted but cannot produce PASS.
     */
    public static function aggregate(string $rootPath, array $checks, bool $rootExists, ?StoragePolicy $policy = null): self
    {
        // A supplied policy must be arithmetically valid even on early-return paths.
        $policy?->effectiveFloorBytes();
        $required = [StorageReadinessChecker::CHECK_ROOT_EXISTS];
        if ($policy !== null && $policy->requireFixedVolume()) {
            $required[] = StorageReadinessChecker::CHECK_FIXED_VOLUME;
        }
        if ($policy !== null && $policy->requireNtfs()) {
            $required[] = StorageReadinessChecker::CHECK_FILESYSTEM;
        }
        $required[] = StorageReadinessChecker::CHECK_CANONICAL_PATH;
        $required[] = StorageReadinessChecker::CHECK_ROOT_REPARSE;
        if ($rootExists) {
            $required[] = StorageReadinessChecker::CHECK_CAPACITY;
        }
        $allowed = [
            StorageReadinessChecker::CHECK_ROOT_EXISTS,
            StorageReadinessChecker::CHECK_FIXED_VOLUME,
            StorageReadinessChecker::CHECK_FILESYSTEM,
            StorageReadinessChecker::CHECK_CANONICAL_PATH,
            StorageReadinessChecker::CHECK_ROOT_REPARSE,
            StorageReadinessChecker::CHECK_CAPACITY,
        ];
        $normalized = [];
        $seen = [];
        $conflicts = [];
        $byId = [];
        foreach ($checks as $check) {
            if (! $check instanceof StorageCheckResult) {
                throw new \InvalidArgumentException('StorageReport::aggregate expects StorageCheckResult instances.');
            }
            $id = $check->id();
            // Existing diagnostic path is legitimate UNKNOWN evidence, not a required check.
            if (! in_array($id, $allowed, true) && ! ($id === 'storage_root_path' && ! $rootExists && $check->isUnknown())) {
                throw new \InvalidArgumentException('StorageReport check identifier is not allowed.');
            }
            $evidence = $check->toArray();
            if (isset($seen[$id])) {
                if (in_array($evidence, $seen[$id], true)) {
                    continue;
                }
                $conflicts[$id] = true;
            }
            $seen[$id][] = $evidence;
            if (! $rootExists && $id === StorageReadinessChecker::CHECK_ROOT_EXISTS
                && $check->isMeasured() && $check->measuredValue() === true) {
                throw new \InvalidArgumentException('StorageReport root-existence evidence contradicts rootExists.');
            }
            $check = self::evaluatePositive($check, $policy);
            $check = self::normalizeReason($check);
            $normalized[] = $check;
            $byId[$id][] = $check->isViolated() ? HostReadinessOutcome::BLOCKED
                : ($check->isUnknown() ? HostReadinessOutcome::UNKNOWN : HostReadinessOutcome::PASS);
        }
        foreach ($conflicts as $id => $_) {
            // Preserve existing failed evidence and distinct blocker codes. Conflicting
            // positives need explicit UNKNOWN; they are not interchangeable proof.
            if (HostReadinessOutcome::aggregate($byId[$id]) === HostReadinessOutcome::PASS) {
                $normalized[] = StorageCheckResult::unknown($id,
                    HostReadinessReasonCodes::STORAGE_OBSERVATION_UNAVAILABLE . ':' . $id,
                    '1970-01-01T00:00:00+00:00');
            }
        }
        foreach ($required as $id) {
            if (! isset($seen[$id])) {
                $normalized[] = StorageCheckResult::unknown($id,
                    HostReadinessReasonCodes::STORAGE_OBSERVATION_UNAVAILABLE . ':' . $id,
                    '1970-01-01T00:00:00+00:00');
            }
        }
        if ($policy === null) {
            $normalized[] = StorageCheckResult::unknown(StorageReadinessChecker::CHECK_ROOT_EXISTS,
                HostReadinessReasonCodes::STORAGE_OBSERVATION_UNAVAILABLE,
                '1970-01-01T00:00:00+00:00');
        }
        $checks = $normalized;
        $outcomes = [];

        foreach ($checks as $check) {
            if (! $check instanceof StorageCheckResult) {
                throw new \InvalidArgumentException(
                    'StorageReport::aggregate expects StorageCheckResult instances.',
                );
            }

            if ($check->isUnknown()) {
                $outcomes[] = HostReadinessOutcome::UNKNOWN;
            } elseif ($check->isViolated()) {
                $outcomes[] = HostReadinessOutcome::BLOCKED;
            } elseif ($check->isMeasured()) {
                $outcomes[] = $check->canContributeToPass()
                    ? HostReadinessOutcome::PASS
                    : HostReadinessOutcome::BLOCKED;
            } else {
                // POLICY: the check is governed by a documented policy
                // value and the observation did not contradict it.
                $outcomes[] = HostReadinessOutcome::PASS;
            }
        }

        $overall = HostReadinessOutcome::aggregate($outcomes);

        // Defensive structural failure must be visible in checks and blockers too.
        if (! $rootExists && $overall === HostReadinessOutcome::PASS) {
            $checks[] = StorageCheckResult::violated(StorageReadinessChecker::CHECK_ROOT_EXISTS,
                HostReadinessReasonCodes::STORAGE_ROOT_MISSING, false, true, '1970-01-01T00:00:00+00:00');
            $overall = HostReadinessOutcome::BLOCKED;
        }

        $reasonCode = '';

        if (! $rootExists && $overall === HostReadinessOutcome::BLOCKED) {
            $reasonCode = HostReadinessReasonCodes::STORAGE_ROOT_MISSING;
        } elseif ($overall === HostReadinessOutcome::BLOCKED) {
            $reasonCode = self::firstBlocker($checks);
        } elseif ($overall === HostReadinessOutcome::UNKNOWN) {
            $reasonCode = self::firstUnknown($checks);
        }

        return new self($overall, $checks, $rootPath, $reasonCode, $rootExists);
    }

    /** Preserve verdict and evidence; only blank non-PASS diagnostics need repair. */
    private static function normalizeReason(StorageCheckResult $check): StorageCheckResult
    {
        if ((! $check->isUnknown() && ! $check->isViolated())
            || (trim($check->reasonCode()) !== '' && preg_match('/^[\s\p{Z}]*$/u', $check->reasonCode()) !== 1)) {
            return $check;
        }
        $id = $check->id();
        if ($check->isUnknown()) {
            $reason = match ($id) {
                'storage_root_path' => HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING,
                StorageReadinessChecker::CHECK_CANONICAL_PATH => HostReadinessReasonCodes::STORAGE_CANONICAL_PATH_UNAVAILABLE,
                default => HostReadinessReasonCodes::STORAGE_OBSERVATION_UNAVAILABLE . ':' . $id,
            };
            return StorageCheckResult::unknown($id, $reason, $check->observedAt());
        }
        $reason = match ($id) {
            StorageReadinessChecker::CHECK_ROOT_EXISTS => HostReadinessReasonCodes::STORAGE_ROOT_MISSING,
            StorageReadinessChecker::CHECK_FIXED_VOLUME => HostReadinessReasonCodes::STORAGE_FIXED_VOLUME_REQUIRED,
            StorageReadinessChecker::CHECK_FILESYSTEM => HostReadinessReasonCodes::STORAGE_NTFS_REQUIRED,
            StorageReadinessChecker::CHECK_CAPACITY => HostReadinessReasonCodes::STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR,
            StorageReadinessChecker::CHECK_CANONICAL_PATH => HostReadinessReasonCodes::STORAGE_CANONICAL_PATH_UNAVAILABLE,
            StorageReadinessChecker::CHECK_ROOT_REPARSE => HostReadinessReasonCodes::STORAGE_ROOT_IS_REPARSE_POINT,
        };
        return StorageCheckResult::violated($id, $reason, $check->measuredValue(), $check->policyValue(), $check->observedAt());
    }

    /** Re-evaluate candidates; status and a claimed threshold are never proof. */
    private static function evaluatePositive(StorageCheckResult $check, ?StoragePolicy $policy): StorageCheckResult
    {
        if ($check->isUnknown() || $check->isViolated()) {
            return $check;
        }
        $id = $check->id();
        $value = $check->measuredValue();
        $unknown = static fn () => StorageCheckResult::unknown($id,
            $id === StorageReadinessChecker::CHECK_CANONICAL_PATH
                ? HostReadinessReasonCodes::STORAGE_CANONICAL_PATH_UNAVAILABLE
                : HostReadinessReasonCodes::STORAGE_OBSERVATION_UNAVAILABLE . ':' . $id,
            $check->observedAt());
        if ($check->isPolicy() && $id !== StorageReadinessChecker::CHECK_ROOT_REPARSE) {
            return $unknown();
        }
        $expected = null;
        $failure = '';
        switch ($id) {
            case StorageReadinessChecker::CHECK_ROOT_EXISTS:
                if (! is_bool($value)) { return $unknown(); }
                $expected = true;
                $failure = $value ? '' : HostReadinessReasonCodes::STORAGE_ROOT_MISSING;
                break;
            case StorageReadinessChecker::CHECK_FIXED_VOLUME:
                if (! is_bool($value) || $policy === null) { return $unknown(); }
                $expected = $policy->requireFixedVolume();
                $failure = $expected && ! $value ? HostReadinessReasonCodes::STORAGE_FIXED_VOLUME_REQUIRED : '';
                break;
            case StorageReadinessChecker::CHECK_FILESYSTEM:
                if (! is_string($value) || $policy === null) { return $unknown(); }
                $parsed = (new Transport\PowershellObservationParser)->parseFilesystemName(['filesystem' => $value], 'filesystem');
                if (! $parsed['ok']) { return $unknown(); }
                $expected = $policy->requireNtfs() ? 'NTFS' : null;
                $failure = $expected !== null && $value !== $expected ? HostReadinessReasonCodes::STORAGE_NTFS_REQUIRED : '';
                break;
            case StorageReadinessChecker::CHECK_CAPACITY:
                if (! is_int($value) || $value < 0 || $policy === null) { return $unknown(); }
                $expected = $policy->effectiveFloorBytes();
                $failure = $value < $expected ? HostReadinessReasonCodes::STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR : '';
                break;
            case StorageReadinessChecker::CHECK_CANONICAL_PATH:
                if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value)) { return $unknown(); }
                $parsed = (new Transport\PowershellObservationParser)->parseCanonicalPath(['canonicalPath' => $value], 'canonicalPath');
                if (! $parsed['ok']) { return $unknown(); }
                $value = $parsed['canonicalPath'];
                $expected = $value;
                break;
            case StorageReadinessChecker::CHECK_ROOT_REPARSE:
                if (! is_bool($value)) { return $unknown(); }
                $expected = false;
                $failure = $value ? HostReadinessReasonCodes::STORAGE_ROOT_IS_REPARSE_POINT : '';
                break;
            default:
                return $unknown();
        }
        if ($failure !== '') {
            return StorageCheckResult::violated($id, $failure, $value, $expected, $check->observedAt());
        }
        return $check->isPolicy()
            ? StorageCheckResult::policy($id, $check->reasonCode(), $expected, $check->observedAt(), $value)
            : StorageCheckResult::measured($id, $check->reasonCode(), $value, $expected, $check->observedAt());
    }

    /**
     * Every distinct failed or unknown check, in the order the checks
     * were supplied. Positive checks are never included. Duplicates
     * are collapsed so a repeated reason code cannot inflate the list.
     *
     * @param  StorageCheckResult[]  $checks
     * @return array<int, string>
     */
    private static function allBlockers(array $checks): array
    {
        $seen = [];
        $blockers = [];

        foreach ($checks as $check) {
            if (! $check->isUnknown() && ! $check->isViolated()) {
                continue;
            }

            $code = $check->reasonCode();

            if (isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $blockers[] = $code;
        }

        return $blockers;
    }

    /**
     * @param  StorageCheckResult[]  $checks
     */
    private static function firstBlocker(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check->isUnknown()) {
                continue;
            }

            if ($check->isViolated()) {
                return $check->reasonCode();
            }

            if ($check->isMeasured() && ! $check->canContributeToPass()) {
                return $check->reasonCode();
            }
        }

        return 'storage_root_not_created';
    }

    /**
     * @param  StorageCheckResult[]  $checks
     */
    private static function firstUnknown(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check->isUnknown()) {
                return $check->reasonCode();
            }
        }

        return 'storage_observation_unavailable';
    }

    public function overall(): string
    {
        return $this->overall;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * Every distinct failed or unknown check, in a deterministic
     * order. Never includes positive checks.
     *
     * @return array<int, string>
     */
    public function blockers(): array
    {
        $blockers = self::allBlockers($this->checks);
        // Preserve the existing structural primary reason in Host aggregation,
        // including reports whose root check carries an observation-error code.
        if (! $this->rootExists && $this->isBlocked()
            && ! in_array(HostReadinessReasonCodes::STORAGE_ROOT_MISSING, $blockers, true)) {
            $blockers[] = HostReadinessReasonCodes::STORAGE_ROOT_MISSING;
        }
        return $blockers;
    }

    public function rootExists(): bool
    {
        return $this->rootExists;
    }

    /**
     * @return StorageCheckResult[]
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function isPass(): bool
    {
        return $this->overall === HostReadinessOutcome::PASS;
    }

    public function isBlocked(): bool
    {
        return $this->overall === HostReadinessOutcome::BLOCKED;
    }

    public function isUnknown(): bool
    {
        return $this->overall === HostReadinessOutcome::UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overall' => $this->overall,
            'rootExists' => $this->rootExists,
            'reasonCode' => $this->reasonCode,
            'checks' => array_map(
                static fn (StorageCheckResult $c) => $c->toArray(),
                $this->checks,
            ),
        ];
    }
}
