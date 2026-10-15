<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness;

use App\Services\Sentinel\Readiness\HostReadinessAggregator;
use App\Services\Sentinel\Readiness\HostReadinessOutcome;
use App\Services\Sentinel\Readiness\HostReadinessReasonCodes;
use App\Services\Sentinel\Readiness\HostReadinessStage;
use App\Services\Sentinel\Readiness\HypervCheckResult;
use App\Services\Sentinel\Readiness\HypervReadinessChecker;
use App\Services\Sentinel\Readiness\StorageCheckResult;
use App\Services\Sentinel\Readiness\StoragePolicy;
use App\Services\Sentinel\Readiness\StorageReadinessChecker;
use PHPUnit\Framework\TestCase;

final class HostReadinessAggregatorTest extends TestCase
{
    private const ROOT = 'D:\Sentinel';

    private SyntheticHostReadinessObserver $observer;

    private StoragePolicy $policy;

    protected function setUp(): void
    {
        $this->observer = new SyntheticHostReadinessObserver;
        $this->policy = StoragePolicy::defaults();
    }

    private function aggregator(): HostReadinessAggregator
    {
        return new HostReadinessAggregator(
            new StorageReadinessChecker($this->observer, $this->policy),
            new HypervReadinessChecker($this->observer),
        );
    }

    private function seedHealthyHost(): void
    {
        $this->observer
            ->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', 'D:\Sentinel', false)
            ->freeSpaceObservation(self::ROOT, 10 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024)
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);
    }

    public function test_preflight_passes_on_a_healthy_host(): void
    {
        $this->seedHealthyHost();

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessStage::HOST_PREFLIGHT, $report->stage());
        $this->assertSame(HostReadinessOutcome::PASS, $report->overall());
        $this->assertSame([], $report->remainingBlockers());
        $this->assertTrue($report->isPass());
    }

    public function test_preflight_never_authorizes_vm_start(): void
    {
        $this->seedHealthyHost();

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertFalse($report->vmStartAuthorized());
        $this->assertFalse($report->toArray()['vmStartAuthorized']);
        $this->assertFalse($report->toArray()['trustedControllerImplemented']);
        $this->assertFalse($report->toArray()['trustedObserverImplemented']);
    }

    public function test_preflight_json_never_carries_authorization(): void
    {
        $this->seedHealthyHost();

        $json = $this->aggregator()->preflight(self::ROOT)->toJson();

        $this->assertStringNotContainsString('VM_START_AUTHORIZED', $json);
        $this->assertStringContainsString('"vmStartAuthorized":false', $json);
    }

    public function test_missing_root_is_blocked_not_unknown_and_not_pass(): void
    {
        $this->observer
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertFalse($report->storage()->rootExists());
        $this->assertSame(HostReadinessReasonCodes::STORAGE_ROOT_MISSING, $report->storage()->reasonCode());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_ROOT_MISSING, $report->remainingBlockers());
        $this->assertSame($report->storage()->blockers(), $report->remainingBlockers());
        $this->assertNotContains('storage_no_reparse_above', $report->remainingBlockers());
    }

    public function test_missing_root_is_never_reported_as_verified(): void
    {
        $this->observer
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertFalse($report->storage()->rootExists());
        $this->assertFalse($report->isPass());
        $this->assertStringNotContainsString('verified', $report->storage()->reasonCode());
    }

    public function test_blank_root_path_yields_unknown(): void
    {
        $report = $this->aggregator()->preflight('');

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertSame(HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING, $report->storage()->reasonCode());
    }

    public function test_null_root_path_yields_unknown(): void
    {
        $report = $this->aggregator()->preflight(null);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertSame(HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING, $report->storage()->reasonCode());
    }

    public function test_unknown_never_becomes_pass(): void
    {
        $this->seedHealthyHost();
        $this->observer->throwOn('freeSpace', 'volume_query_failed');

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertTrue($report->isUnknown());
        $this->assertFalse($report->isPass());
    }

    public function test_low_capacity_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->freeSpaceObservation(
            self::ROOT,
            $this->policy->effectiveFloorBytes() - 1,
            100 * 1024 * 1024 * 1024,
        );

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR, $report->remainingBlockers());
    }

    public function test_non_ntfs_filesystem_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->filesystemObservation(self::ROOT, true, 'fixed', 'exFAT', 'D:\Sentinel', false);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_NTFS_REQUIRED, $report->remainingBlockers());
    }

    public function test_removable_volume_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->filesystemObservation(self::ROOT, true, 'removable', 'NTFS', 'D:\Sentinel', false);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_FIXED_VOLUME_REQUIRED, $report->remainingBlockers());
    }

    public function test_reparse_point_above_root_is_reported(): void
    {
        $this->seedHealthyHost();
        $this->observer->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', 'D:\Sentinel', true);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_ROOT_IS_REPARSE_POINT, $report->remainingBlockers());
    }

    public function test_hyperv_module_absence_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->moduleObservation('Hyper-V', false);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::HYPERV_MODULE_NOT_IMPORTABLE, $report->remainingBlockers());
    }

    public function test_hyperv_module_presence_is_not_management_authorization(): void
    {
        $this->seedHealthyHost();

        $report = $this->aggregator()->preflight(self::ROOT);

        $hypervChecks = $report->hyperv()->checks();

        $moduleCheck = null;

        foreach ($hypervChecks as $check) {
            if ($check->id() === HypervReadinessChecker::CHECK_MODULE) {
                $moduleCheck = $check;
            }
        }

        $this->assertNotNull($moduleCheck);
        $this->assertSame(HypervCheckResult::STATUS_PRESENT, $moduleCheck->status());
        $this->assertFalse($report->vmStartAuthorized());
    }

    public function test_hyperv_service_stopped_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->serviceObservation('vmms', true, 'Stopped');

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains('hyperv_vmms_stopped', $report->remainingBlockers());
    }

    public function test_hyperv_service_missing_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->serviceObservation('vmms', false, null);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::HYPERV_VMMS_NOT_INSTALLED, $report->remainingBlockers());
    }

    public function test_hyperv_access_denied_is_not_promoted_to_a_capability_claim(): void
    {
        $this->seedHealthyHost();
        $this->observer->serviceObservation('vmms', true, 'AccessDenied');

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());

        $found = null;

        foreach ($report->hyperv()->checks() as $check) {
            if ($check->id() === HypervReadinessChecker::CHECK_SERVICE) {
                $found = $check;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(HypervCheckResult::STATUS_ACCESS_DENIED, $found->status());
        $this->assertNotSame(HypervCheckResult::STATUS_PRESENT, $found->status());
    }

    public function test_hyperv_group_not_member_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->groupObservation('Hyper-V Administrators', false);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::HYPERV_GROUP_NOT_MEMBER, $report->remainingBlockers());
    }

    public function test_hyperv_not_elevated_blocks_preflight(): void
    {
        $this->seedHealthyHost();
        $this->observer->elevationObservation(false);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::HYPERV_PROCESS_NOT_ELEVATED, $report->remainingBlockers());
    }

    public function test_hyperv_observation_failure_yields_unknown_not_pass(): void
    {
        $this->seedHealthyHost();
        $this->observer->throwOn('module', 'module_query_failed');

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertFalse($report->isPass());
    }

    public function test_filesystem_observation_failure_yields_unknown_not_pass(): void
    {
        $this->seedHealthyHost();
        $this->observer->throwOn('filesystem', 'fs_probe_failed');

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertFalse($report->isPass());
    }

    public function test_report_stage_is_always_host_preflight(): void
    {
        $this->seedHealthyHost();

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessStage::HOST_PREFLIGHT, $report->stage());
        $this->assertTrue(HostReadinessStage::isValid($report->stage()));
    }

    public function test_checks_map_contains_named_outcomes(): void
    {
        $this->seedHealthyHost();

        $report = $this->aggregator()->preflight(self::ROOT);

        $checks = $report->checks();

        $this->assertArrayHasKey(StorageReadinessChecker::CHECK_ROOT_EXISTS, $checks);
        $this->assertArrayHasKey(StorageReadinessChecker::CHECK_CAPACITY, $checks);
        $this->assertArrayHasKey(HypervReadinessChecker::CHECK_MODULE, $checks);
        $this->assertArrayHasKey(HypervReadinessChecker::CHECK_SERVICE, $checks);
    }

    public function test_custom_policy_is_honoured(): void
    {
        $this->seedHealthyHost();
        $this->policy = StoragePolicy::fromArray([
            StoragePolicy::KEY_MINIMUM_BYTES_FREE => 2 * 1024 * 1024 * 1024 * 1024, // 2 TiB
        ]);

        $report = $this->aggregator()->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertContains(HostReadinessReasonCodes::STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR, $report->remainingBlockers());
    }

    public function test_policy_rejects_unknown_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoragePolicy::fromArray(['verified' => true]);
    }

    public function test_storage_report_serializes_without_secrets(): void
    {
        $this->seedHealthyHost();

        $json = $this->aggregator()->preflight(self::ROOT)->toJson();

        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('token', $json);
    }

    public function test_aggregation_is_fail_closed_with_one_unknown_among_passes(): void
    {
        $outcomes = [
            'a' => HostReadinessOutcome::PASS,
            'b' => HostReadinessOutcome::UNKNOWN,
            'c' => HostReadinessOutcome::PASS,
        ];

        $this->assertSame(HostReadinessOutcome::UNKNOWN, HostReadinessOutcome::aggregate($outcomes));
    }

    public function test_aggregation_prefers_blocked_over_unknown(): void
    {
        $outcomes = [
            'a' => HostReadinessOutcome::BLOCKED,
            'b' => HostReadinessOutcome::UNKNOWN,
        ];

        $this->assertSame(HostReadinessOutcome::BLOCKED, HostReadinessOutcome::aggregate($outcomes));
    }

    public function test_invalid_outcome_is_treated_as_unknown(): void
    {
        $outcomes = ['a' => 'NOPE'];

        $this->assertSame(HostReadinessOutcome::UNKNOWN, HostReadinessOutcome::aggregate($outcomes));
    }

    public function test_storage_check_result_unknown_never_contributes_to_pass(): void
    {
        $check = StorageCheckResult::unknown('id', 'reason', '1970-01-01T00:00:00+00:00');

        $this->assertFalse($check->canContributeToPass());
        $this->assertTrue($check->isUnknown());
    }

    public function test_hyperv_check_result_present_maps_to_pass(): void
    {
        $check = HypervCheckResult::present('id', 'reason', true, '1970-01-01T00:00:00+00:00');

        $this->assertSame(HostReadinessOutcome::PASS, $check->toOutcome());
    }

    public function test_hyperv_check_result_unknown_maps_to_unknown(): void
    {
        $check = HypervCheckResult::unknown('id', 'reason', '1970-01-01T00:00:00+00:00');

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $check->toOutcome());
    }

    public function test_hyperv_check_result_access_denied_maps_to_blocked(): void
    {
        $check = HypervCheckResult::accessDenied('id', 'reason', null, '1970-01-01T00:00:00+00:00');

        $this->assertSame(HostReadinessOutcome::BLOCKED, $check->toOutcome());
    }

    public function test_hyperv_check_result_unavailable_maps_to_blocked(): void
    {
        $check = HypervCheckResult::unavailable('id', 'reason', null, '1970-01-01T00:00:00+00:00');

        $this->assertSame(HostReadinessOutcome::BLOCKED, $check->toOutcome());
    }

    public function test_hyperv_check_result_not_installed_maps_to_blocked(): void
    {
        $check = HypervCheckResult::notInstalled('id', 'reason', null, '1970-01-01T00:00:00+00:00');

        $this->assertSame(HostReadinessOutcome::BLOCKED, $check->toOutcome());
    }
}
