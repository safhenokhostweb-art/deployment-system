<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HypervReadinessChecker — the read-only Hyper-V capability assessor.
 *
 * It observes the host's Hyper-V capability surface through a
 * HostReadinessObserver and produces a HypervReport. It never
 * elevates privileges, never modifies Windows groups, never starts
 * services, never creates VMs and never runs arbitrary PowerShell.
 *
 * Hard safety rules:
 *
 *  - The presence of the Hyper-V module is NOT management
 *    authorization. A module that imports successfully says only that
 *    the binaries are on disk; it says nothing about the caller's
 *    authority to manage virtual machines.
 *  - ACCESS_DENIED is reported as ACCESS_DENIED, never promoted to a
 *    capability claim and never silently downgraded to NOT_INSTALLED.
 *  - UNKNOWN never becomes PASS.
 *  - No VM startup authorization is produced or implied by any code
 *    in this class.
 */
final class HypervReadinessChecker
{
    public const CHECK_MODULE = 'hyperv_module_available';
    public const CHECK_SERVICE = 'hyperv_vmms_service';
    public const CHECK_GROUP = 'hyperv_administrators_group';
    public const CHECK_ELEVATION = 'hyperv_process_elevation';

    public const REASON_MODULE_AVAILABLE = 'hyperv_module_importable';
    public const REASON_MODULE_UNAVAILABLE = 'hyperv_module_not_importable';
    public const REASON_SERVICE_RUNNING = 'hyperv_vmms_running';
    public const REASON_SERVICE_STOPPED = 'hyperv_vmms_stopped';
    public const REASON_SERVICE_ACCESS_DENIED = 'hyperv_vmms_access_denied';
    public const REASON_SERVICE_MISSING = 'hyperv_vmms_not_installed';
    public const REASON_GROUP_MEMBER = 'hyperv_group_member';
    public const REASON_GROUP_NOT_MEMBER = 'hyperv_group_not_member';
    public const REASON_GROUP_ACCESS_DENIED = 'hyperv_group_access_denied';
    public const REASON_ELEVATED = 'hyperv_process_elevated';
    public const REASON_NOT_ELEVATED = 'hyperv_process_not_elevated';
    public const REASON_ELEVATION_UNKNOWN = 'hyperv_elevation_unavailable';
    public const REASON_OBSERVATION_FAILED = 'hyperv_observation_unavailable';

    public const MODULE_HYPERV = 'Hyper-V';
    public const SERVICE_VMMS = 'vmms';
    public const GROUP_HYPERV_ADMINS = 'Hyper-V Administrators';

    public function __construct(private readonly HostReadinessObserver $observer)
    {
    }

    /**
     * @return array{report: HypervReport, checks: HypervCheckResult[]}
     */
    public function check(): array
    {
        $checks = [];
        $now = $this->observedAt();

        // --- Module availability -------------------------------------
        try {
            $module = $this->observer->observeModuleAvailable(self::MODULE_HYPERV);
            $available = is_array($module) && ($module['available'] ?? false) === true;
            $checks[] = $available
                ? HypervCheckResult::present(self::CHECK_MODULE, self::REASON_MODULE_AVAILABLE, true, $now)
                : HypervCheckResult::notInstalled(self::CHECK_MODULE, self::REASON_MODULE_UNAVAILABLE, false, $now);
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = HypervCheckResult::unknown(
                self::CHECK_MODULE,
                self::REASON_OBSERVATION_FAILED . ':' . $e->code(),
                $now,
            );
        }

        // --- Service state -------------------------------------------
        try {
            $service = $this->observer->observeServiceState(self::SERVICE_VMMS);
            $present = is_array($service) && ($service['present'] ?? false) === true;
            $state = is_array($service) && isset($service['state']) && is_string($service['state'])
                ? $service['state']
                : null;

            if (! $present) {
                $checks[] = HypervCheckResult::notInstalled(self::CHECK_SERVICE, self::REASON_SERVICE_MISSING, null, $now);
            } elseif ($state === 'Running') {
                $checks[] = HypervCheckResult::present(self::CHECK_SERVICE, self::REASON_SERVICE_RUNNING, $state, $now);
            } elseif ($state === 'Stopped') {
                $checks[] = HypervCheckResult::unavailable(self::CHECK_SERVICE, self::REASON_SERVICE_STOPPED, $state, $now);
            } else {
                $checks[] = HypervCheckResult::accessDenied(self::CHECK_SERVICE, self::REASON_SERVICE_ACCESS_DENIED, $state, $now);
            }
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = HypervCheckResult::unknown(
                self::CHECK_SERVICE,
                self::REASON_OBSERVATION_FAILED . ':' . $e->code(),
                $now,
            );
        }

        // --- Group membership ----------------------------------------
        try {
            $group = $this->observer->observeGroupMembership(self::GROUP_HYPERV_ADMINS);
            $member = is_array($group) && ($group['member'] ?? false) === true;
            $checks[] = $member
                ? HypervCheckResult::present(self::CHECK_GROUP, self::REASON_GROUP_MEMBER, true, $now)
                : HypervCheckResult::accessDenied(self::CHECK_GROUP, self::REASON_GROUP_NOT_MEMBER, false, $now);
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = HypervCheckResult::unknown(
                self::CHECK_GROUP,
                self::REASON_GROUP_ACCESS_DENIED . ':' . $e->code(),
                $now,
            );
        }

        // --- Process elevation ---------------------------------------
        try {
            $elevation = $this->observer->observeProcessElevation();
            $elevated = is_array($elevation) && ($elevation['elevated'] ?? false) === true;
            $checks[] = $elevated
                ? HypervCheckResult::present(self::CHECK_ELEVATION, self::REASON_ELEVATED, true, $now)
                : HypervCheckResult::accessDenied(self::CHECK_ELEVATION, self::REASON_NOT_ELEVATED, false, $now);
        } catch (SentinelReadinessObservationException $e) {
            $checks[] = HypervCheckResult::unknown(
                self::CHECK_ELEVATION,
                self::REASON_ELEVATION_UNKNOWN . ':' . $e->code(),
                $now,
            );
        }

        return [
            'report' => HypervReport::aggregate($checks),
            'checks' => $checks,
        ];
    }

    private function observedAt(): string
    {
        return date('c');
    }
}
