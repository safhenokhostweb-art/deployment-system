<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness;

use App\Services\Sentinel\Readiness\HostReadinessAggregator;
use App\Services\Sentinel\Readiness\HostReadinessOutcome;
use App\Services\Sentinel\Readiness\HostReadinessReasonCodes;
use App\Services\Sentinel\Readiness\HostReadinessReport;
use App\Services\Sentinel\Readiness\HostReadinessStage;
use App\Services\Sentinel\Readiness\HypervCheckResult;
use App\Services\Sentinel\Readiness\HypervReadinessChecker;
use App\Services\Sentinel\Readiness\StorageCheckResult;
use App\Services\Sentinel\Readiness\StoragePolicy;
use App\Services\Sentinel\Readiness\StoragePolicyOverflowException;
use App\Services\Sentinel\Readiness\StorageReadinessChecker;
use App\Services\Sentinel\Readiness\StorageReport;
use PHPUnit\Framework\TestCase;

final class FailClosedHardeningTest extends TestCase
{
    private const ROOT = 'D:\Sentinel';

    // ==================================================================
    // PHASE 1 — empty aggregation
    // ==================================================================

    public function test_empty_aggregation_is_unknown_not_pass(): void
    {
        $this->assertSame(HostReadinessOutcome::UNKNOWN, HostReadinessOutcome::aggregate([]));
    }

    public function test_all_pass_aggregates_to_pass(): void
    {
        $this->assertSame(
            HostReadinessOutcome::PASS,
            HostReadinessOutcome::aggregate(['a' => HostReadinessOutcome::PASS, 'b' => HostReadinessOutcome::PASS]),
        );
    }

    public function test_pass_plus_unknown_is_unknown(): void
    {
        $this->assertSame(
            HostReadinessOutcome::UNKNOWN,
            HostReadinessOutcome::aggregate(['a' => HostReadinessOutcome::PASS, 'b' => HostReadinessOutcome::UNKNOWN]),
        );
    }

    public function test_pass_plus_blocked_is_blocked(): void
    {
        $this->assertSame(
            HostReadinessOutcome::BLOCKED,
            HostReadinessOutcome::aggregate(['a' => HostReadinessOutcome::PASS, 'b' => HostReadinessOutcome::BLOCKED]),
        );
    }

    public function test_unknown_plus_blocked_is_blocked(): void
    {
        $this->assertSame(
            HostReadinessOutcome::BLOCKED,
            HostReadinessOutcome::aggregate(['a' => HostReadinessOutcome::UNKNOWN, 'b' => HostReadinessOutcome::BLOCKED]),
        );
    }

    public function test_multiple_unknown_values_stay_unknown(): void
    {
        $this->assertSame(
            HostReadinessOutcome::UNKNOWN,
            HostReadinessOutcome::aggregate([
                'a' => HostReadinessOutcome::UNKNOWN,
                'b' => HostReadinessOutcome::UNKNOWN,
                'c' => HostReadinessOutcome::UNKNOWN,
            ]),
        );
    }

    // ==================================================================
    // PHASE 2 — complete blocker reporting
    // ==================================================================

    public function test_storage_report_lists_every_failed_check_not_just_the_first(): void
    {
        $checks = [
            StorageCheckResult::measured('storage_root_exists', 'storage_root_present', true, true, '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::violated('storage_filesystem', 'storage_ntfs_required', 'exFAT', 'NTFS', '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::violated('storage_fixed_volume', 'storage_fixed_volume_required', false, true, '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::violated('storage_capacity', 'storage_capacity_below_effective_floor', 100, 3000, '1970-01-01T00:00:00+00:00'),
        ];

        $report = $this->completeStorageReport($checks);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertSame(
            ['storage_ntfs_required', 'storage_fixed_volume_required', 'storage_capacity_below_effective_floor'],
            $report->blockers(),
        );
    }

    public function test_storage_report_blockers_exclude_positive_checks(): void
    {
        $checks = [
            StorageCheckResult::measured('storage_root_exists', 'storage_root_present', true, true, '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::measured('storage_capacity', 'storage_capacity_ok', PHP_INT_MAX, StoragePolicy::defaults()->effectiveFloorBytes(), '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::violated('storage_filesystem', 'storage_ntfs_required', 'exFAT', 'NTFS', '1970-01-01T00:00:00+00:00'),
        ];

        $report = $this->completeStorageReport($checks);

        $this->assertSame(['storage_ntfs_required'], $report->blockers());
    }

    public function test_storage_report_blockers_collapse_duplicates(): void
    {
        $checks = [
            StorageCheckResult::violated('storage_filesystem', 'storage_ntfs_required', 'exFAT', 'NTFS', '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::violated('storage_filesystem', 'storage_ntfs_required', 'FAT32', 'NTFS', '1970-01-01T00:00:00+00:00'),
        ];

        $report = $this->completeStorageReport($checks);

        $this->assertSame(['storage_ntfs_required'], $report->blockers());
    }

    public function test_storage_report_unknown_checks_are_blockers(): void
    {
        $checks = [
            StorageCheckResult::unknown('storage_root_exists', 'STORAGE_ROOT_MISSING', '1970-01-01T00:00:00+00:00'),
            StorageCheckResult::unknown('storage_capacity', 'storage_capacity_below_effective_floor', '1970-01-01T00:00:00+00:00'),
        ];

        $report = StorageReport::aggregate(self::ROOT, $checks, false, StoragePolicy::defaults());

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $report->overall());
        $this->assertSame(['STORAGE_ROOT_MISSING', 'storage_capacity_below_effective_floor'], array_slice($report->blockers(), 0, 2));
        $this->assertContains('storage_observation_unavailable:storage_canonical_path', $report->blockers());
    }

    public function test_hyperv_report_lists_every_failed_check_not_just_the_first(): void
    {
        $checks = [
            HypervCheckResult::present('hyperv_module_available', 'hyperv_module_importable', true, '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::notInstalled('hyperv_vmms_service', 'hyperv_vmms_not_installed', null, '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::accessDenied('hyperv_administrators_group', 'hyperv_group_not_member', false, '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::accessDenied('hyperv_process_elevation', 'hyperv_process_not_elevated', false, '1970-01-01T00:00:00+00:00'),
        ];

        $report = \App\Services\Sentinel\Readiness\HypervReport::aggregate($checks);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertSame(
            ['hyperv_vmms_not_installed', 'hyperv_group_not_member', 'hyperv_process_not_elevated'],
            $report->blockers(),
        );
    }

    public function test_hyperv_report_blockers_exclude_present_checks(): void
    {
        $checks = [
            HypervCheckResult::present('hyperv_module_available', 'hyperv_module_importable', true, '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::unknown('hyperv_vmms_service', 'hyperv_observation_unavailable', '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::unknown('hyperv_administrators_group', 'hyperv_observation_unavailable', '1970-01-01T00:00:00+00:00'),
            HypervCheckResult::unknown('hyperv_process_elevation', 'hyperv_observation_unavailable', '1970-01-01T00:00:00+00:00'),
        ];

        $report = \App\Services\Sentinel\Readiness\HypervReport::aggregate($checks);

        $this->assertSame(['hyperv_observation_unavailable'], $report->blockers());
    }

    public function test_aggregator_reports_simultaneous_blockers_across_both_categories(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer
            ->filesystemObservation(self::ROOT, true, 'removable', 'exFAT', 'D:\Sentinel', true)
            ->freeSpaceObservation(self::ROOT, 100, 1000)
            ->moduleObservation('Hyper-V', false)
            ->serviceObservation('vmms', false, null)
            ->groupObservation('Hyper-V Administrators', false)
            ->elevationObservation(false);

        $aggregator = new HostReadinessAggregator(
            new StorageReadinessChecker($observer, StoragePolicy::defaults()),
            new HypervReadinessChecker($observer),
        );

        $report = $aggregator->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $report->overall());
        $this->assertFalse($report->vmStartAuthorized());

        $blockers = $report->remainingBlockers();

        $this->assertContains('storage_fixed_volume_required', $blockers);
        $this->assertContains('storage_ntfs_required', $blockers);
        $this->assertContains('storage_root_is_reparse_point', $blockers);
        $this->assertContains('storage_capacity_below_effective_floor', $blockers);
        $this->assertContains('hyperv_module_not_importable', $blockers);
        $this->assertContains('hyperv_vmms_not_installed', $blockers);
        $this->assertContains('hyperv_group_not_member', $blockers);
        $this->assertContains('hyperv_process_not_elevated', $blockers);
    }

    public function test_aggregator_blockers_contain_no_positive_check_codes(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer
            ->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', 'D:\Sentinel', false)
            ->freeSpaceObservation(self::ROOT, 100, 1000)
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);

        $aggregator = new HostReadinessAggregator(
            new StorageReadinessChecker($observer, StoragePolicy::defaults()),
            new HypervReadinessChecker($observer),
        );

        $report = $aggregator->preflight(self::ROOT);

        foreach ($report->remainingBlockers() as $blocker) {
            $this->assertStringNotContainsString('_ok', $blocker);
            $this->assertStringNotContainsString('present', $blocker);
            $this->assertStringNotContainsString('importable', $blocker);
        }
    }

    // ==================================================================
    // PHASE 3 — malformed observer data
    // ==================================================================

    public function test_missing_filesystem_keys_fail_closed(): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = [];

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertNotSame(HostReadinessOutcome::PASS, $result['report']->overall());
    }

    public function test_string_bytes_free_is_treated_as_unknown_not_pass(): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = [
            'exists' => true,
            'type' => 'fixed',
            'filesystem' => 'NTFS',
            'canonicalPath' => 'D:\Sentinel',
            'reparsePointAbove' => false,
            'supportedFilesystem' => true,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
        $observer->freeSpacePayload = ['bytesFree' => 'lots', 'bytesTotal' => 'lots', 'observedAt' => '1970-01-01T00:00:00+00:00'];

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $result['report']->overall());
    }

    public function test_negative_capacity_is_a_violation_not_a_pass(): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = [
            'exists' => true,
            'type' => 'fixed',
            'filesystem' => 'NTFS',
            'canonicalPath' => 'D:\Sentinel',
            'reparsePointAbove' => false,
            'supportedFilesystem' => true,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
        $observer->freeSpacePayload = ['bytesFree' => -1024, 'bytesTotal' => 1000, 'observedAt' => '1970-01-01T00:00:00+00:00'];

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $result['report']->overall());
        $this->assertContains('storage_capacity_below_effective_floor', $result['report']->blockers());
    }

    public function test_invalid_service_state_fails_closed(): void
    {
        $observer = new MalformedObserver;
        $observer->servicePayload = ['present' => true, 'state' => 'OnFire', 'observedAt' => '1970-01-01T00:00:00+00:00'];

        $checker = new HypervReadinessChecker($observer);
        $result = $checker->check();

        $this->assertSame(HostReadinessOutcome::BLOCKED, $result['report']->overall());
    }

    public function test_invalid_filesystem_identifier_fails_closed(): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = [
            'exists' => true,
            'type' => 'fixed',
            'filesystem' => 42,
            'canonicalPath' => 'D:\Sentinel',
            'reparsePointAbove' => false,
            'supportedFilesystem' => true,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $result['report']->overall());
    }

    public function test_invalid_canonical_path_fails_closed(): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = [
            'exists' => true,
            'type' => 'fixed',
            'filesystem' => 'NTFS',
            'canonicalPath' => ['not', 'a', 'string'],
            'reparsePointAbove' => false,
            'supportedFilesystem' => true,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $result['report']->overall());
    }

    public function test_unexpected_observer_exception_yields_unknown_not_pass(): void
    {
        $observer = new MalformedObserver;
        $observer->throwOnFilesystem = true;

        $checker = new StorageReadinessChecker($observer, StoragePolicy::defaults());
        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::UNKNOWN, $result['report']->overall());
    }

    public function test_unexpected_hyperv_observer_exception_yields_unknown_not_pass(): void
    {
        $observer = new MalformedObserver;
        $observer->servicePayload = ['present' => true, 'state' => 'Running', 'observedAt' => '1970-01-01T00:00:00+00:00'];
        $observer->throwOnModule = true;

        $checker = new HypervReadinessChecker($observer);
        $result = $checker->check();

        // The module probe failed, so the report can never be PASS.
        // The exact verdict depends on the other probes: BLOCKED wins
        // over UNKNOWN in fail-closed precedence, and both are safe.
        $this->assertNotSame(HostReadinessOutcome::PASS, $result['report']->overall());

        $moduleCheck = null;
        foreach ($result['checks'] as $check) {
            if ($check->id() === HypervReadinessChecker::CHECK_MODULE) {
                $moduleCheck = $check;
            }
        }

        $this->assertNotNull($moduleCheck);
        $this->assertSame(HostReadinessOutcome::UNKNOWN, $moduleCheck->toOutcome());
    }

    // ==================================================================
    // PHASE 4 — integer overflow
    // ==================================================================

    public function test_effective_floor_overflow_throws_instead_of_wrapping(): void
    {
        $policy = StoragePolicy::fromArray([
            StoragePolicy::KEY_MINIMUM_BYTES_FREE => PHP_INT_MAX,
            StoragePolicy::KEY_SAFETY_RESERVE_BYTES => 1,
        ]);

        $this->expectException(StoragePolicyOverflowException::class);

        $policy->effectiveFloorBytes();
    }

    public function test_effective_floor_overflow_message_is_a_stable_code(): void
    {
        $policy = StoragePolicy::fromArray([
            StoragePolicy::KEY_MINIMUM_BYTES_FREE => PHP_INT_MAX,
            StoragePolicy::KEY_SAFETY_RESERVE_BYTES => PHP_INT_MAX,
        ]);

        try {
            $policy->effectiveFloorBytes();
            $this->fail('expected StoragePolicyOverflowException');
        } catch (StoragePolicyOverflowException $e) {
            $this->assertSame('storage_policy_effective_floor_overflow', $e->getMessage());
            $this->assertStringNotContainsString(PHP_INT_MAX . '', $e->getMessage());
        }
    }

    public function test_default_policy_floor_does_not_overflow(): void
    {
        $this->assertSame(
            StoragePolicy::DEFAULT_MINIMUM_BYTES_FREE + StoragePolicy::DEFAULT_SAFETY_RESERVE_BYTES,
            StoragePolicy::defaults()->effectiveFloorBytes(),
        );
    }

    // ==================================================================
    // PHASE 5 — report validation
    // ==================================================================

    public function test_report_rejects_an_invalid_stage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HostReadinessReport::make(
            'VM_LAUNCH',
            HostReadinessOutcome::PASS,
            [],
            StorageReport::aggregate(self::ROOT, [], false, StoragePolicy::defaults()),
            \App\Services\Sentinel\Readiness\HypervReport::aggregate([]),
        );
    }

    public function test_report_rejects_an_invalid_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HostReadinessReport::make(
            HostReadinessStage::HOST_PREFLIGHT,
            'AUTHORIZED',
            [],
            StorageReport::aggregate(self::ROOT, [], false, StoragePolicy::defaults()),
            \App\Services\Sentinel\Readiness\HypervReport::aggregate([]),
        );
    }

    public function test_report_makes_no_authorization_decision(): void
    {
        // vmStartAuthorized is not an accepted parameter of make() at
        // all; the constructor hardcodes false. Verify by reflection
        // that no parameter named vmStartAuthorized exists.
        $params = (new \ReflectionMethod(HostReadinessReport::class, 'make'))->getParameters();

        $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $params);

        $this->assertNotContains('vmStartAuthorized', $names);
        $this->assertNotContains('trustedControllerImplemented', $names);
        $this->assertNotContains('trustedObserverImplemented', $names);
    }

    public function test_forged_positive_observations_still_carry_no_authorization(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer
            ->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', 'D:\Sentinel', false)
            ->freeSpaceObservation(self::ROOT, PHP_INT_MAX, PHP_INT_MAX)
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);

        $aggregator = new HostReadinessAggregator(
            new StorageReadinessChecker($observer, StoragePolicy::defaults()),
            new HypervReadinessChecker($observer),
        );

        $report = $aggregator->preflight(self::ROOT);

        $this->assertSame(HostReadinessOutcome::PASS, $report->overall());
        $this->assertFalse($report->vmStartAuthorized());
        $this->assertFalse($report->toArray()['vmStartAuthorized']);
        $this->assertFalse($report->toArray()['trustedControllerImplemented']);
        $this->assertFalse($report->toArray()['trustedObserverImplemented']);
    }

    public function test_json_serialization_is_sanitized(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer
            ->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', 'D:\Sentinel', false)
            ->freeSpaceObservation(self::ROOT, 10 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024)
            ->moduleObservation('Hyper-V', true)
            ->serviceObservation('vmms', true, 'Running')
            ->groupObservation('Hyper-V Administrators', true)
            ->elevationObservation(true);

        $aggregator = new HostReadinessAggregator(
            new StorageReadinessChecker($observer, StoragePolicy::defaults()),
            new HypervReadinessChecker($observer),
        );

        $json = $aggregator->preflight(self::ROOT)->toJson();

        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('C:\\Users', $json);
        $this->assertStringContainsString('"vmStartAuthorized":false', $json);
    }

    // ==================================================================
    // PHASE 6 — documentation / message consistency
    // ==================================================================

    public function test_storage_check_result_error_message_names_violated(): void
    {
        try {
            // status() is typed; reach the constructor guard via reflection
            // with an invalid status to capture the message text.
            $ref = new \ReflectionClass(StorageCheckResult::class);
            $ctor = $ref->getConstructor();
            $ctor->setAccessible(true);
            $instance = $ref->newInstanceWithoutConstructor();
            $ctor->invokeArgs($instance, ['id', 'BOGUS', 'reason', null, null, '1970-01-01T00:00:00+00:00']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('VIOLATED', $e->getMessage());
        }
    }

    public function test_hyperv_check_result_error_message_names_present(): void
    {
        try {
            $ref = new \ReflectionClass(HypervCheckResult::class);
            $ctor = $ref->getConstructor();
            $ctor->setAccessible(true);
            $instance = $ref->newInstanceWithoutConstructor();
            $ctor->invokeArgs($instance, ['id', 'BOGUS', 'reason', null, '1970-01-01T00:00:00+00:00']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PRESENT', $e->getMessage());
        }
    }

    /** Complete default-policy fixture; preserves the blocker-order tests' scope. */
    private function completeStorageReport(array $checks): StorageReport
    {
        $present = array_map(static fn (StorageCheckResult $check) => $check->id(), $checks);
        foreach ([
            StorageReadinessChecker::CHECK_ROOT_EXISTS,
            StorageReadinessChecker::CHECK_FIXED_VOLUME,
            StorageReadinessChecker::CHECK_FILESYSTEM,
            StorageReadinessChecker::CHECK_CANONICAL_PATH,
            StorageReadinessChecker::CHECK_ROOT_REPARSE,
            StorageReadinessChecker::CHECK_CAPACITY,
        ] as $id) {
            if (! in_array($id, $present, true)) {
                $value = match ($id) {
                    StorageReadinessChecker::CHECK_FILESYSTEM => 'NTFS',
                    StorageReadinessChecker::CHECK_CANONICAL_PATH => self::ROOT,
                    StorageReadinessChecker::CHECK_ROOT_REPARSE => false,
                    StorageReadinessChecker::CHECK_CAPACITY => PHP_INT_MAX,
                    default => true,
                };
                $checks[] = StorageCheckResult::measured($id, 'fixture_positive', $value, null, '1970-01-01T00:00:00+00:00');
            }
        }
        return StorageReport::aggregate(self::ROOT, $checks, true, StoragePolicy::defaults());
    }
}
