<?php

namespace App\Services\Sentinel\Readiness;

/**
 * StorageReadinessChecker — the read-only storage capability assessor.
 *
 * It probes a single proposed storage root through a
 * HostReadinessObserver and evaluates the observations against a
 * StoragePolicy. It never creates, modifies or deletes anything.
 *
 * Hard safety rules:
 *
 *  - A missing root is BLOCKED, never PASS. It is reported via the
 *    distinct reasonCode STORAGE_ROOT_MISSING so a caller cannot
 *    mistake "we could not see it" for "we verified it".
 *  - UNKNOWN never becomes PASS. Any probe that cannot be answered
 *    contributes UNKNOWN to the aggregate.
 *  - No native reparse-point security validation is claimed. The
 *    checker only reports the observer's reparsePointAbove flag and,
 *    when the root itself is a reparse point, a POLICY-level check
 *    records it. This is an observation, not a native security
 *    containment proof.
 *  - No path is canonicalized by the checker. The canonicalPath comes
 *    exclusively from the observer.
 */
final class StorageReadinessChecker
{
    public const CHECK_FIXED_VOLUME = 'storage_fixed_volume';
    public const CHECK_FILESYSTEM = 'storage_filesystem';
    public const CHECK_CAPACITY = 'storage_capacity';
    public const CHECK_ROOT_EXISTS = 'storage_root_exists';
    public const CHECK_ROOT_REPARSE = 'storage_root_reparse_point';
    public const CHECK_CANONICAL_PATH = 'storage_canonical_path';

    public const REASON_FIXED_VOLUME = 'storage_fixed_volume_required';
    public const REASON_FILESYSTEM = 'storage_ntfs_required';
    public const REASON_CAPACITY = 'storage_capacity_below_effective_floor';
    public const REASON_ROOT_MISSING = 'STORAGE_ROOT_MISSING';
    public const REASON_ROOT_REPARSE = 'storage_root_is_reparse_point';
    public const REASON_CANONICAL_UNAVAILABLE = 'storage_canonical_path_unavailable';
    public const REASON_OBSERVATION_FAILED = 'storage_observation_unavailable';

    public function __construct(
        private readonly HostReadinessObserver $observer,
        private readonly StoragePolicy $policy,
    ) {
    }

    /** The immutable policy used by this checker's canonical evaluation context. */
    public function policy(): StoragePolicy
    {
        return $this->policy;
    }

    /**
     * @return array{report: StorageReport, checks: StorageCheckResult[]}
     */
    public function check(string $rootPath): array
    {
        $checks = [];
        $now = $this->observedAt();

        try {
            $fs = $this->observer->observeFilesystem($rootPath);
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = StorageCheckResult::unknown(
                self::CHECK_ROOT_EXISTS,
                self::REASON_OBSERVATION_FAILED . ':' . $e->code(),
                $now,
            );

            return [
                'report' => StorageReport::aggregate($rootPath, $checks, false, $this->policy),
                'checks' => $checks,
            ];
        }

        $rootExists = is_array($fs) && ($fs['exists'] ?? false) === true;

        $checks[] = $rootExists
            ? StorageCheckResult::measured(self::CHECK_ROOT_EXISTS, 'storage_root_present', true, true, $now)
            : StorageCheckResult::unknown(self::CHECK_ROOT_EXISTS, self::REASON_ROOT_MISSING, $now);

        $isFixed = is_array($fs) && ($fs['type'] ?? '') === 'fixed';
        $filesystem = is_array($fs) && isset($fs['filesystem']) && is_string($fs['filesystem'])
            ? $fs['filesystem']
            : null;

        if ($this->policy->requireFixedVolume()) {
            $checks[] = ($rootExists && $isFixed)
                ? StorageCheckResult::measured(self::CHECK_FIXED_VOLUME, 'storage_fixed_volume_ok', $isFixed, true, $now)
                : StorageCheckResult::violated(self::CHECK_FIXED_VOLUME, self::REASON_FIXED_VOLUME, $isFixed, true, $now);
        }

        if ($this->policy->requireNtfs()) {
            $checks[] = ($rootExists && $filesystem !== null)
                ? ($filesystem === 'NTFS'
                    ? StorageCheckResult::measured(self::CHECK_FILESYSTEM, 'storage_filesystem_ntfs', $filesystem, 'NTFS', $now)
                    : StorageCheckResult::violated(self::CHECK_FILESYSTEM, self::REASON_FILESYSTEM, $filesystem, 'NTFS', $now))
                : StorageCheckResult::unknown(self::CHECK_FILESYSTEM, self::REASON_FILESYSTEM, $now);
        }

        $canonical = is_array($fs) && isset($fs['canonicalPath']) && is_string($fs['canonicalPath'])
            ? $fs['canonicalPath']
            : null;
        if ($canonical !== null) {
            $parsed = (new Transport\PowershellObservationParser)->parseCanonicalPath(['canonicalPath' => $canonical], 'canonicalPath');
            $canonical = $parsed['ok'] && ! preg_match('/[\x00-\x1F\x7F]/', $canonical) ? $parsed['canonicalPath'] : null;
        }
        $checks[] = ($rootExists && $canonical !== null)
            ? StorageCheckResult::measured(self::CHECK_CANONICAL_PATH, 'storage_canonical_path_ok', $canonical, $canonical, $now)
            : StorageCheckResult::unknown(self::CHECK_CANONICAL_PATH, self::REASON_CANONICAL_UNAVAILABLE, $now);

        $reparseAbove = $fs['reparsePointAbove'] ?? null;
        $checks[] = ! is_bool($reparseAbove)
            ? StorageCheckResult::unknown(self::CHECK_ROOT_REPARSE, self::REASON_OBSERVATION_FAILED . ':' . self::CHECK_ROOT_REPARSE, $now)
            : ($reparseAbove
                ? StorageCheckResult::violated(self::CHECK_ROOT_REPARSE, self::REASON_ROOT_REPARSE, true, false, $now)
                : StorageCheckResult::policy(self::CHECK_ROOT_REPARSE, 'storage_no_reparse_above', false, $now, false));

        if (! $rootExists) {
            return [
                'report' => StorageReport::aggregate($rootPath, $checks, false, $this->policy),
                'checks' => $checks,
            ];
        }

        try {
            $space = $this->observer->observeFreeSpace($rootPath);
            $bytesFree = is_array($space) && isset($space['bytesFree']) && is_int($space['bytesFree'])
                ? $space['bytesFree']
                : null;
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = StorageCheckResult::unknown(
                self::CHECK_CAPACITY,
                self::REASON_OBSERVATION_FAILED . ':' . $e->code(),
                $now,
            );

            return [
                'report' => StorageReport::aggregate($rootPath, $checks, true, $this->policy),
                'checks' => $checks,
            ];
        }

        $floor = $this->policy->effectiveFloorBytes();

        if ($bytesFree === null) {
            $checks[] = StorageCheckResult::unknown(self::CHECK_CAPACITY, self::REASON_CAPACITY, $now);
        } else {
            $checks[] = $bytesFree >= $floor
                ? StorageCheckResult::measured(self::CHECK_CAPACITY, 'storage_capacity_ok', $bytesFree, $floor, $now)
                : StorageCheckResult::violated(self::CHECK_CAPACITY, self::REASON_CAPACITY, $bytesFree, $floor, $now);
        }

        return [
            'report' => StorageReport::aggregate($rootPath, $checks, true, $this->policy),
            'checks' => $checks,
        ];
    }

    private function observedAt(): string
    {
        return date('c');
    }
}
