<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HypervReport — aggregated Hyper-V capability result.
 *
 * Aggregation is fail-closed:
 *
 *   any check outcome is BLOCKED  -> overall BLOCKED
 *   any check outcome is UNKNOWN  -> overall UNKNOWN
 *   else                         -> PASS
 *
 * A Hyper-V capability PASS means the host reports the capability;
 * it does NOT mean the checker is authorized to start a VM. No
 * readiness stage in this package may ever return an authorization
 * token for VM startup.
 *
 * This report contains no secrets, no absolute user-home paths and
 * no raw exception text.
 */
final class HypervReport
{
    /**
     * @param  string               $overall     one of HostReadinessOutcome
     * @param  HypervCheckResult[]  $checks      individual probe results
     * @param  string               $reasonCode  primary blocker code, or ''
     */
    private function __construct(
        private readonly string $overall,
        private readonly array $checks,
        private readonly string $reasonCode,
    ) {
    }

    /**
     * @param  HypervCheckResult[]  $checks
     */
    public static function aggregate(array $checks): self
    {
        $required = [
            HypervReadinessChecker::CHECK_MODULE,
            HypervReadinessChecker::CHECK_SERVICE,
            HypervReadinessChecker::CHECK_GROUP,
            HypervReadinessChecker::CHECK_ELEVATION,
        ];
        $normalized = [];
        $seen = [];
        $conflicts = [];
        $byId = [];
        foreach ($checks as $check) {
            if (! $check instanceof HypervCheckResult) {
                throw new \InvalidArgumentException('HypervReport::aggregate expects HypervCheckResult instances.');
            }
            $id = $check->id();
            if (! in_array($id, $required, true) && ! ($id === 'hyperv_root_path' && $check->toOutcome() === HostReadinessOutcome::UNKNOWN)) {
                throw new \InvalidArgumentException('HypervReport check identifier is not allowed.');
            }
            $evidence = $check->toArray();
            if (isset($seen[$id])) {
                if (in_array($evidence, $seen[$id], true)) {
                    continue;
                }
                $conflicts[$id] = true;
            }
            $seen[$id][] = $evidence;
            if ($check->toOutcome() === HostReadinessOutcome::PASS) {
                $expected = $id === HypervReadinessChecker::CHECK_SERVICE ? 'Running' : true;
                if ($check->measured() !== $expected) {
                    $check = HypervCheckResult::unknown($id,
                        HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id,
                        $check->observedAt());
                }
            }
            $check = self::normalizeReason($check);
            $normalized[] = $check;
            $byId[$id][] = $check->toOutcome();
        }
        foreach ($conflicts as $id => $_) {
            if (HostReadinessOutcome::aggregate($byId[$id]) === HostReadinessOutcome::PASS) {
                $normalized[] = HypervCheckResult::unknown($id,
                    HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id,
                    '1970-01-01T00:00:00+00:00');
            }
        }
        foreach ($required as $id) {
            if (! isset($seen[$id])) {
                $normalized[] = HypervCheckResult::unknown($id,
                    HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id,
                    '1970-01-01T00:00:00+00:00');
            }
        }
        $checks = $normalized;
        $outcomes = [];

        foreach ($checks as $check) {
            if (! $check instanceof HypervCheckResult) {
                throw new \InvalidArgumentException(
                    'HypervReport::aggregate expects HypervCheckResult instances.',
                );
            }

            $outcomes[] = $check->toOutcome();
        }

        $overall = HostReadinessOutcome::aggregate($outcomes);

        $reasonCode = '';

        if ($overall === HostReadinessOutcome::BLOCKED) {
            $reasonCode = self::firstBlocker($checks);
        } elseif ($overall === HostReadinessOutcome::UNKNOWN) {
            $reasonCode = self::firstUnknown($checks);
        }

        return new self($overall, $checks, $reasonCode);
    }

    /** Preserve negative status and observation when supplying an existing fallback. */
    private static function normalizeReason(HypervCheckResult $check): HypervCheckResult
    {
        if ($check->toOutcome() === HostReadinessOutcome::PASS
            || (trim($check->reasonCode()) !== '' && preg_match('/^[\s\p{Z}]*$/u', $check->reasonCode()) !== 1)) {
            return $check;
        }
        $id = $check->id();
        if ($check->status() === HypervCheckResult::STATUS_UNKNOWN) {
            $reason = $id === 'hyperv_root_path' ? HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING
                : HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id;
            return HypervCheckResult::unknown($id, $reason, $check->observedAt());
        }
        $reason = match ($id) {
            HypervReadinessChecker::CHECK_MODULE => HostReadinessReasonCodes::HYPERV_MODULE_NOT_IMPORTABLE,
            HypervReadinessChecker::CHECK_SERVICE => match ($check->status()) {
                HypervCheckResult::STATUS_ACCESS_DENIED => HostReadinessReasonCodes::HYPERV_VMMS_ACCESS_DENIED,
                HypervCheckResult::STATUS_NOT_INSTALLED => HostReadinessReasonCodes::HYPERV_VMMS_NOT_INSTALLED,
                default => $check->measured() === 'Stopped' ? HypervReadinessChecker::REASON_SERVICE_STOPPED
                    : HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id,
            },
            HypervReadinessChecker::CHECK_GROUP => $check->measured() === false || $check->status() === HypervCheckResult::STATUS_NOT_INSTALLED
                ? HostReadinessReasonCodes::HYPERV_GROUP_NOT_MEMBER
                : ($check->status() === HypervCheckResult::STATUS_ACCESS_DENIED ? HypervReadinessChecker::REASON_GROUP_ACCESS_DENIED
                    : HostReadinessReasonCodes::HYPERV_OBSERVATION_UNAVAILABLE . ':' . $id),
            HypervReadinessChecker::CHECK_ELEVATION => $check->measured() === false ? HostReadinessReasonCodes::HYPERV_PROCESS_NOT_ELEVATED
                : HypervReadinessChecker::REASON_ELEVATION_UNKNOWN,
        };
        return match ($check->status()) {
            HypervCheckResult::STATUS_ACCESS_DENIED => HypervCheckResult::accessDenied($id, $reason, $check->measured(), $check->observedAt()),
            HypervCheckResult::STATUS_NOT_INSTALLED => HypervCheckResult::notInstalled($id, $reason, $check->measured(), $check->observedAt()),
            HypervCheckResult::STATUS_UNAVAILABLE => HypervCheckResult::unavailable($id, $reason, $check->measured(), $check->observedAt()),
        };
    }

    /**
     * Every distinct failed or unknown check, in the order the checks
     * were supplied. Positive (PRESENT) checks are never included.
     * Duplicates are collapsed.
     *
     * @param  HypervCheckResult[]  $checks
     * @return array<int, string>
     */
    private static function allBlockers(array $checks): array
    {
        $seen = [];
        $blockers = [];

        foreach ($checks as $check) {
            if ($check->toOutcome() === HostReadinessOutcome::PASS) {
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
     * @param  HypervCheckResult[]  $checks
     */
    private static function firstBlocker(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check->toOutcome() === HostReadinessOutcome::BLOCKED) {
                return $check->reasonCode();
            }
        }

        return 'hyperv_capability_unavailable';
    }

    /**
     * @param  HypervCheckResult[]  $checks
     */
    private static function firstUnknown(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check->toOutcome() === HostReadinessOutcome::UNKNOWN) {
                return $check->reasonCode();
            }
        }

        return 'hyperv_observation_unavailable';
    }

    public function overall(): string
    {
        return $this->overall;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * Every distinct failed or unknown check, in a deterministic
     * order. Never includes positive (PRESENT) checks.
     *
     * @return array<int, string>
     */
    public function blockers(): array
    {
        return self::allBlockers($this->checks);
    }

    /**
     * @return HypervCheckResult[]
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
            'reasonCode' => $this->reasonCode,
            'checks' => array_map(
                static fn (HypervCheckResult $c) => $c->toArray(),
                $this->checks,
            ),
        ];
    }
}
