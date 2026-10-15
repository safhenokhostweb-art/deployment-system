<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness;

use App\Services\Sentinel\Readiness\HostReadinessAggregator;
use App\Services\Sentinel\Readiness\HostReadinessOutcome as Outcome;
use App\Services\Sentinel\Readiness\HostReadinessReport;
use App\Services\Sentinel\Readiness\HostReadinessStage;
use App\Services\Sentinel\Readiness\HypervCheckResult;
use App\Services\Sentinel\Readiness\HypervReadinessChecker as Hyperv;
use App\Services\Sentinel\Readiness\HypervReport;
use App\Services\Sentinel\Readiness\StorageCheckResult;
use App\Services\Sentinel\Readiness\StoragePolicy;
use App\Services\Sentinel\Readiness\StorageReadinessChecker as Storage;
use App\Services\Sentinel\Readiness\StorageReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic evidence only: no host, process, filesystem or database observations. */
final class PolicyAwareReportHardeningTest extends TestCase
{
    private const ROOT = 'D:\\Sentinel';
    private const TIME = '1970-01-01T00:00:00+00:00';

    private static function policy(bool $fixed = true, bool $ntfs = true): StoragePolicy
    {
        return StoragePolicy::fromArray([
            StoragePolicy::KEY_REQUIRE_FIXED_VOLUME => $fixed,
            StoragePolicy::KEY_REQUIRE_NTFS => $ntfs,
        ]);
    }

    private static function storageChecks(StoragePolicy $policy): array
    {
        $checks = [Storage::CHECK_ROOT_EXISTS => StorageCheckResult::measured(
            Storage::CHECK_ROOT_EXISTS, 'storage_root_present', true, true, self::TIME)];
        if ($policy->requireFixedVolume()) {
            $checks[Storage::CHECK_FIXED_VOLUME] = StorageCheckResult::measured(
                Storage::CHECK_FIXED_VOLUME, 'storage_fixed_volume_ok', true, true, self::TIME);
        }
        if ($policy->requireNtfs()) {
            $checks[Storage::CHECK_FILESYSTEM] = StorageCheckResult::measured(
                Storage::CHECK_FILESYSTEM, 'storage_filesystem_ntfs', 'NTFS', 'NTFS', self::TIME);
        }
        $checks[Storage::CHECK_CANONICAL_PATH] = StorageCheckResult::measured(
            Storage::CHECK_CANONICAL_PATH, 'storage_canonical_path_ok', self::ROOT, self::ROOT, self::TIME);
        $checks[Storage::CHECK_ROOT_REPARSE] = StorageCheckResult::policy(
            Storage::CHECK_ROOT_REPARSE, 'storage_no_reparse_above', false, self::TIME, false);
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::measured(
            Storage::CHECK_CAPACITY, 'storage_capacity_ok', PHP_INT_MAX, $policy->effectiveFloorBytes(), self::TIME);
        return $checks;
    }

    private static function hypervChecks(): array
    {
        $checks = [];
        foreach ([Hyperv::CHECK_MODULE, Hyperv::CHECK_SERVICE, Hyperv::CHECK_GROUP, Hyperv::CHECK_ELEVATION] as $id) {
            $checks[$id] = HypervCheckResult::present($id, 'fixture_present', $id === Hyperv::CHECK_SERVICE ? 'Running' : true, self::TIME);
        }
        return $checks;
    }

    public static function policyCases(): array
    {
        return [
            'both enabled' => [true, true], 'fixed only' => [true, false],
            'NTFS only' => [false, true], 'both disabled' => [false, false],
        ];
    }

    #[DataProvider('policyCases')]
    public function test_completeness_tracks_the_effective_policy(bool $fixed, bool $ntfs): void
    {
        $policy = self::policy($fixed, $ntfs);
        $checks = self::storageChecks($policy);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, $policy);
        $this->assertSame(Outcome::PASS, $report->overall());
        $ids = array_map(static fn ($check) => $check->id(), $report->checks());
        $this->assertSame($fixed, in_array(Storage::CHECK_FIXED_VOLUME, $ids, true));
        $this->assertSame($ntfs, in_array(Storage::CHECK_FILESYSTEM, $ids, true));
    }

    #[DataProvider('policyCases')]
    public function test_checker_passes_its_own_policy_without_requiring_disabled_checks(bool $fixed, bool $ntfs): void
    {
        $policy = self::policy($fixed, $ntfs);
        $observer = new SyntheticHostReadinessObserver;
        $observer->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', self::ROOT, false)
            ->freeSpaceObservation(self::ROOT, PHP_INT_MAX, PHP_INT_MAX);
        $checker = new Storage($observer, $policy);
        $this->assertSame($policy, $checker->policy());
        $report = $checker->check(self::ROOT)['report'];
        $this->assertSame(Outcome::PASS, $report->overall());
        $this->assertCount(4 + (int) $fixed + (int) $ntfs, $report->checks());
    }

    public function test_disabled_optional_policy_is_preserved_for_non_ntfs_removable_volume(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer->filesystemObservation(self::ROOT, true, 'removable', 'EXFAT', self::ROOT, false)
            ->freeSpaceObservation(self::ROOT, PHP_INT_MAX, PHP_INT_MAX);
        $this->assertSame(Outcome::PASS, (new Storage($observer, self::policy(false, false)))->check(self::ROOT)['report']->overall());
    }

    public static function requiredStorageIds(): array
    {
        return array_map(static fn ($id) => [$id], [
            Storage::CHECK_ROOT_EXISTS, Storage::CHECK_CANONICAL_PATH,
            Storage::CHECK_ROOT_REPARSE, Storage::CHECK_CAPACITY,
            Storage::CHECK_FIXED_VOLUME, Storage::CHECK_FILESYSTEM,
        ]);
    }

    #[DataProvider('requiredStorageIds')]
    public function test_missing_required_storage_check_is_visible_unknown(string $id): void
    {
        $policy = self::policy();
        $checks = self::storageChecks($policy);
        unset($checks[$id]);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, $policy);
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        $this->assertContains('storage_observation_unavailable:' . $id, $report->blockers());
        $matching = array_values(array_filter($report->checks(), static fn ($check) => $check->id() === $id));
        $this->assertCount(1, $matching);
        $this->assertTrue($matching[0]->isUnknown());
    }

    public function test_no_policy_does_not_silently_assume_disabled_options(): void
    {
        $report = StorageReport::aggregate(self::ROOT, self::storageChecks(self::policy(false, false)), true);
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        $this->assertContains('storage_observation_unavailable', $report->blockers());
    }

    public function test_arbitrary_policy_claim_array_is_not_an_evaluation_context(): void
    {
        $this->expectException(\TypeError::class);
        StorageReport::aggregate(self::ROOT, self::storageChecks(self::policy(false, false)), true,
            ['require_ntfs' => false, 'require_fixed_volume' => false]);
    }

    public static function rootAbsentCases(): array
    {
        return [['fixed enabled', true, Outcome::BLOCKED], ['fixed disabled', false, Outcome::UNKNOWN]];
    }

    #[DataProvider('rootAbsentCases')]
    public function test_absent_root_preserves_outcome_and_does_not_require_capacity(string $label, bool $fixed, string $expected): void
    {
        $checker = new Storage(new SyntheticHostReadinessObserver, self::policy($fixed, false));
        $report = $checker->check(self::ROOT)['report'];
        $this->assertSame($expected, $report->overall(), $label);
        $this->assertFalse($report->rootExists());
        $this->assertNotContains(Storage::CHECK_CAPACITY, array_map(static fn ($check) => $check->id(), $report->checks()));
        $this->assertContains('STORAGE_ROOT_MISSING', $report->blockers());
    }

    public function test_empty_storage_and_hyperv_evidence_are_unknown(): void
    {
        $this->assertSame(Outcome::UNKNOWN, StorageReport::aggregate(self::ROOT, [], true, self::policy())->overall());
        $this->assertSame(Outcome::UNKNOWN, HypervReport::aggregate([])->overall());
    }

    public function test_storage_positive_subset_cannot_pass(): void
    {
        $checks = self::storageChecks(self::policy());
        $this->assertSame(Outcome::UNKNOWN, StorageReport::aggregate(self::ROOT,
            [$checks[Storage::CHECK_ROOT_EXISTS]], true, self::policy())->overall());
    }

    public function test_exact_duplicate_evidence_is_collapsed(): void
    {
        $policy = self::policy();
        $storage = array_values(self::storageChecks($policy));
        $storage[] = $storage[0];
        $this->assertSame(Outcome::PASS, ($report = StorageReport::aggregate(self::ROOT, $storage, true, $policy))->overall());
        $this->assertCount(6, $report->checks());
        $hyperv = array_values(self::hypervChecks());
        $hyperv[] = $hyperv[0];
        $this->assertSame(Outcome::PASS, ($report = HypervReport::aggregate($hyperv))->overall());
        $this->assertCount(4, $report->checks());
    }

    public function test_conflicting_positive_duplicates_are_unknown(): void
    {
        $policy = self::policy();
        $storage = array_values(self::storageChecks($policy));
        $storage[] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'storage_capacity_ok', PHP_INT_MAX - 1,
            $policy->effectiveFloorBytes(), self::TIME);
        $this->assertSame(Outcome::UNKNOWN, StorageReport::aggregate(self::ROOT, $storage, true, $policy)->overall());
        $hyperv = array_values(self::hypervChecks());
        $hyperv[] = HypervCheckResult::present(Hyperv::CHECK_SERVICE, 'second_positive_observation', 'Running', self::TIME);
        $this->assertSame(Outcome::UNKNOWN, HypervReport::aggregate($hyperv)->overall());
    }

    public function test_conflicting_outcomes_preserve_blocked_and_distinct_blockers(): void
    {
        $policy = self::policy();
        $checks = array_values(self::storageChecks($policy));
        $checks[] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'first_failure', 0, 100, self::TIME);
        $checks[] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'second_failure', 1, 100, self::TIME);
        $checks[] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'first_failure', 0, 100, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, $policy);
        $this->assertSame(Outcome::BLOCKED, $report->overall());
        $this->assertSame(['first_failure', 'second_failure'], array_values(array_filter($report->blockers(),
            static fn ($code) => in_array($code, ['first_failure', 'second_failure'], true))));
        $hyperv = array_values(self::hypervChecks());
        $hyperv[] = HypervCheckResult::unknown(Hyperv::CHECK_SERVICE, 'query_failed', self::TIME);
        $hyperv[] = HypervCheckResult::accessDenied(Hyperv::CHECK_SERVICE, 'access_denied', null, self::TIME);
        $this->assertSame(Outcome::BLOCKED, HypervReport::aggregate($hyperv)->overall());
    }

    public function test_duplicates_cannot_replace_missing_hyperv_identifiers(): void
    {
        $positive = self::hypervChecks()[Hyperv::CHECK_MODULE];
        $this->assertSame(Outcome::UNKNOWN, HypervReport::aggregate([$positive, $positive, $positive, $positive])->overall());
    }

    public static function unknownIdentifierCases(): array
    {
        return [['storage'], ['hyperv']];
    }

    #[DataProvider('unknownIdentifierCases')]
    public function test_unknown_identifiers_are_rejected_with_sanitized_errors(string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($kind === 'storage' ? 'StorageReport check identifier is not allowed.' : 'HypervReport check identifier is not allowed.');
        if ($kind === 'storage') {
            StorageReport::aggregate(self::ROOT, [StorageCheckResult::measured('private-invalid', 'private', true, true, self::TIME)], true, self::policy());
        } else {
            HypervReport::aggregate([HypervCheckResult::present('private-invalid', 'private', true, self::TIME)]);
        }
    }

    public static function hypervOutcomeCases(): array
    {
        return [['pass', Outcome::PASS], ['unknown', Outcome::UNKNOWN], ['blocked', Outcome::BLOCKED], ['missing', Outcome::UNKNOWN]];
    }

    #[DataProvider('hypervOutcomeCases')]
    public function test_hyperv_completeness_and_precedence(string $case, string $expected): void
    {
        $checks = self::hypervChecks();
        if ($case === 'unknown') {
            $checks[Hyperv::CHECK_SERVICE] = HypervCheckResult::unknown(Hyperv::CHECK_SERVICE, 'query_failed', self::TIME);
        } elseif ($case === 'blocked') {
            $checks[Hyperv::CHECK_SERVICE] = HypervCheckResult::notInstalled(Hyperv::CHECK_SERVICE, 'missing_service', null, self::TIME);
        } elseif ($case === 'missing') {
            unset($checks[Hyperv::CHECK_SERVICE]);
        }
        $this->assertSame($expected, HypervReport::aggregate($checks)->overall());
    }

    public static function hostOutcomeCases(): array
    {
        return [
            ['pass', Outcome::PASS], ['unknown', Outcome::UNKNOWN], ['blocked', Outcome::BLOCKED],
            ['hyperv unknown', Outcome::UNKNOWN], ['hyperv blocked', Outcome::BLOCKED], ['empty', Outcome::UNKNOWN],
        ];
    }

    #[DataProvider('hostOutcomeCases')]
    public function test_claimed_host_pass_cannot_override_nested_outcomes(string $case, string $expected): void
    {
        $policy = self::policy();
        $checks = self::storageChecks($policy);
        $hyperv = self::hypervChecks();
        if ($case === 'unknown') {
            unset($checks[Storage::CHECK_CAPACITY]);
        } elseif ($case === 'blocked') {
            $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'low_capacity', 0, 100, self::TIME);
        } elseif ($case === 'hyperv unknown') {
            $hyperv[Hyperv::CHECK_SERVICE] = HypervCheckResult::unknown(Hyperv::CHECK_SERVICE, 'query_failed', self::TIME);
        } elseif ($case === 'hyperv blocked') {
            $hyperv[Hyperv::CHECK_SERVICE] = HypervCheckResult::notInstalled(Hyperv::CHECK_SERVICE, 'missing_service', null, self::TIME);
        } elseif ($case === 'empty') {
            $checks = [];
            $hyperv = [];
        }
        $report = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, ['forged' => Outcome::PASS],
            StorageReport::aggregate(self::ROOT, $checks, true, $policy), HypervReport::aggregate($hyperv), []);
        $this->assertSame($expected, $report->overall());
        $this->assertArrayNotHasKey('forged', $report->checks());
        $this->assertFalse($report->vmStartAuthorized());
        $this->assertFalse($report->toArray()['vmStartAuthorized']);
        if ($case !== 'pass') {
            $this->assertNotEmpty($report->remainingBlockers());
        }
    }

    public function test_host_preserves_storage_and_hyperv_blockers_in_order(): void
    {
        $policy = self::policy();
        $storage = self::storageChecks($policy);
        $storage[Storage::CHECK_CAPACITY] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'low_capacity', 0, 100, self::TIME);
        $hyperv = self::hypervChecks();
        $hyperv[Hyperv::CHECK_SERVICE] = HypervCheckResult::accessDenied(Hyperv::CHECK_SERVICE, 'service_denied', null, self::TIME);
        $hyperv[Hyperv::CHECK_GROUP] = HypervCheckResult::unknown(Hyperv::CHECK_GROUP, 'group_unknown', self::TIME);
        $storageReport = StorageReport::aggregate(self::ROOT, $storage, true, $policy);
        $hypervReport = HypervReport::aggregate($hyperv);
        $report = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, [], $storageReport, $hypervReport, ['forged']);
        $this->assertSame(Outcome::BLOCKED, $report->overall());
        $this->assertSame(array_values(array_unique(array_merge($storageReport->blockers(), $hypervReport->blockers()))), $report->remainingBlockers());
        $this->assertNotContains('forged', $report->remainingBlockers());
        $this->assertContains('group_unknown', $report->remainingBlockers());
        $this->assertSame(Outcome::UNKNOWN, $report->checks()[Hyperv::CHECK_GROUP]);
    }

    public static function malformedPositiveEvidence(): array
    {
        $cases = [];
        foreach ([Storage::CHECK_ROOT_EXISTS, Storage::CHECK_FIXED_VOLUME, Storage::CHECK_FILESYSTEM,
            Storage::CHECK_CAPACITY, Storage::CHECK_CANONICAL_PATH, Storage::CHECK_ROOT_REPARSE] as $id) {
            foreach ([null, '', [], 1.5, 'false'] as $index => $value) {
                $cases['storage:' . $id . ':' . $index] = ['storage', $id, $value];
            }
        }
        foreach ([Hyperv::CHECK_MODULE, Hyperv::CHECK_SERVICE, Hyperv::CHECK_GROUP, Hyperv::CHECK_ELEVATION] as $id) {
            foreach ([null, '', [], 1, false, 'Stopped'] as $index => $value) {
                $cases['hyperv:' . $id . ':' . $index] = ['hyperv', $id, $value];
            }
        }
        $cases['capacity bool'] = ['storage', Storage::CHECK_CAPACITY, true];
        $cases['capacity numeric string'] = ['storage', Storage::CHECK_CAPACITY, (string) PHP_INT_MAX];
        $cases['filesystem bool'] = ['storage', Storage::CHECK_FILESYSTEM, true];
        $cases['canonical bool'] = ['storage', Storage::CHECK_CANONICAL_PATH, true];
        return $cases;
    }

    #[DataProvider('malformedPositiveEvidence')]
    public function test_positive_status_cannot_replace_valid_evidence(string $kind, string $id, mixed $value): void
    {
        if ($kind === 'storage') {
            $checks = self::storageChecks(self::policy());
            $checks[$id] = StorageCheckResult::measured($id, 'claimed_positive', $value, true, self::TIME);
            $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        } else {
            $checks = self::hypervChecks();
            $checks[$id] = HypervCheckResult::present($id, 'claimed_positive', $value, self::TIME);
            $report = HypervReport::aggregate($checks);
        }
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        $this->assertNotEmpty($report->blockers());
        $this->assertNotContains('claimed_positive', $report->blockers());
    }

    #[DataProvider('requiredStorageIds')]
    public function test_policy_only_cannot_replace_observation(string $id): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[$id] = StorageCheckResult::policy($id, 'claimed_policy', true, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        $this->assertNotEmpty($report->blockers());
    }

    public static function contradictoryStorageEvidence(): array
    {
        return [
            [Storage::CHECK_ROOT_EXISTS, false, 'STORAGE_ROOT_MISSING', Outcome::BLOCKED],
            [Storage::CHECK_FIXED_VOLUME, false, 'storage_fixed_volume_required', Outcome::BLOCKED],
            [Storage::CHECK_FILESYSTEM, 'EXFAT', 'storage_ntfs_required', Outcome::BLOCKED],
            [Storage::CHECK_CAPACITY, 0, 'storage_capacity_below_effective_floor', Outcome::BLOCKED],
            [Storage::CHECK_CAPACITY, -1, 'storage_observation_unavailable:storage_capacity', Outcome::UNKNOWN],
            [Storage::CHECK_ROOT_REPARSE, true, 'storage_root_is_reparse_point', Outcome::BLOCKED],
            [Storage::CHECK_CANONICAL_PATH, 'relative', 'storage_canonical_path_unavailable', Outcome::UNKNOWN],
        ];
    }

    #[DataProvider('contradictoryStorageEvidence')]
    public function test_negative_measurement_is_not_made_positive_by_status(string $id, mixed $value, string $reason, string $expected): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[$id] = StorageCheckResult::measured($id, 'claimed_positive', $value, null, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame($expected, $report->overall());
        $this->assertContains($reason, $report->blockers());
        $host = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, [], $report, HypervReport::aggregate(self::hypervChecks()));
        $this->assertSame($expected, $host->overall());
        $this->assertSame($expected, $host->checks()[$id]);
        $this->assertContains($reason, $host->remainingBlockers());
        $this->assertFalse($host->vmStartAuthorized());
    }

    public static function capacityBoundaries(): array
    {
        return [[99, Outcome::BLOCKED], [100, Outcome::PASS], [101, Outcome::PASS]];
    }

    #[DataProvider('capacityBoundaries')]
    public function test_capacity_is_evaluated_against_the_current_policy(int $bytes, string $expected): void
    {
        $policy = StoragePolicy::fromArray(['minimum_bytes_free' => 70, 'safety_reserve_bytes' => 30]);
        $checks = self::storageChecks($policy);
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'old_positive', $bytes, 0, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, $policy);
        $this->assertSame($expected, $report->overall());
        $capacity = array_values(array_filter($report->checks(), static fn ($c) => $c->id() === Storage::CHECK_CAPACITY))[0];
        $this->assertSame(100, $capacity->policyValue());
    }

    public function test_stricter_policy_rejects_reused_positive_capacity(): void
    {
        $weak = StoragePolicy::fromArray(['minimum_bytes_free' => 0, 'safety_reserve_bytes' => 0]);
        $observer = new SyntheticHostReadinessObserver;
        $observer->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', self::ROOT, false)->freeSpaceObservation(self::ROOT, 1, 10);
        $old = (new Storage($observer, $weak))->check(self::ROOT)['report'];
        $this->assertSame(Outcome::PASS, $old->overall());
        $new = StorageReport::aggregate(self::ROOT, $old->checks(), true, self::policy());
        $this->assertSame(Outcome::BLOCKED, $new->overall());
        $this->assertContains('storage_capacity_below_effective_floor', $new->blockers());
    }

    public function test_weaker_policy_preserves_explicit_unknown_and_violated_evidence(): void
    {
        $weak = StoragePolicy::fromArray(['minimum_bytes_free' => 0, 'safety_reserve_bytes' => 0, 'require_ntfs' => false, 'require_fixed_volume' => false]);
        $checks = self::storageChecks(self::policy());
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'old_positive', 1, PHP_INT_MAX, self::TIME);
        $this->assertSame(Outcome::PASS, StorageReport::aggregate(self::ROOT, $checks, true, $weak)->overall());
        $checks[Storage::CHECK_FILESYSTEM] = StorageCheckResult::unknown(Storage::CHECK_FILESYSTEM, 'filesystem_unknown', self::TIME);
        $this->assertSame(Outcome::UNKNOWN, StorageReport::aggregate(self::ROOT, $checks, true, $weak)->overall());
        $checks[Storage::CHECK_FIXED_VOLUME] = StorageCheckResult::violated(Storage::CHECK_FIXED_VOLUME, 'fixed_failure', false, true, self::TIME);
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'capacity_failure', 1, PHP_INT_MAX, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, $weak);
        $this->assertSame(Outcome::BLOCKED, $report->overall());
        $this->assertSame(['fixed_failure', 'filesystem_unknown', 'capacity_failure'], $report->blockers());
    }

    #[DataProvider('policyCases')]
    public function test_enabled_flags_require_previously_omitted_observations(bool $fixed, bool $ntfs): void
    {
        $report = StorageReport::aggregate(self::ROOT, self::storageChecks(self::policy(false, false)), true, self::policy($fixed, $ntfs));
        $this->assertSame($fixed || $ntfs ? Outcome::UNKNOWN : Outcome::PASS, $report->overall());
        if ($fixed) { $this->assertContains('storage_observation_unavailable:' . Storage::CHECK_FIXED_VOLUME, $report->blockers()); }
        if ($ntfs) { $this->assertContains('storage_observation_unavailable:' . Storage::CHECK_FILESYSTEM, $report->blockers()); }
    }

    public static function invalidPolicyInputs(): array
    {
        $cases = [];
        foreach (StoragePolicy::ALLOWED_KEYS as $key) { $cases[$key . ':null'] = [$key, null]; }
        foreach ([StoragePolicy::KEY_MINIMUM_BYTES_FREE, StoragePolicy::KEY_SAFETY_RESERVE_BYTES] as $key) {
            foreach ([-1, true, 1.0, '1', []] as $index => $value) { $cases[$key . ':' . $index] = [$key, $value]; }
        }
        $cases['NTFS wrong type'] = [StoragePolicy::KEY_REQUIRE_NTFS, 1];
        $cases['fixed wrong type'] = [StoragePolicy::KEY_REQUIRE_FIXED_VOLUME, 'false'];
        return $cases;
    }

    #[DataProvider('invalidPolicyInputs')]
    public function test_invalid_policy_inputs_are_rejected(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StoragePolicy::fromArray([$key => $value]);
    }

    public function test_report_cannot_skip_policy_overflow_validation(): void
    {
        $policy = StoragePolicy::fromArray(['minimum_bytes_free' => PHP_INT_MAX, 'safety_reserve_bytes' => 1]);
        $this->expectException(\App\Services\Sentinel\Readiness\StoragePolicyOverflowException::class);
        StorageReport::aggregate(self::ROOT, [], false, $policy);
    }

    public function test_root_flag_cannot_contradict_valid_positive_root_evidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('StorageReport root-existence evidence contradicts rootExists.');
        StorageReport::aggregate(self::ROOT, self::storageChecks(self::policy()), false, self::policy());
    }

    public function test_successful_policy_with_observation_is_not_a_blocker(): void
    {
        $checks = self::storageChecks(self::policy());
        $this->assertSame(false, $checks[Storage::CHECK_ROOT_REPARSE]->measuredValue());
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame(Outcome::PASS, $report->overall());
        $this->assertSame([], $report->blockers());
        $checks[Storage::CHECK_ROOT_REPARSE] = StorageCheckResult::policy(Storage::CHECK_ROOT_REPARSE, 'claimed_positive', false, self::TIME, true);
        $negative = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame(Outcome::BLOCKED, $negative->overall());
        $this->assertSame(['storage_root_is_reparse_point'], $negative->blockers());
    }

    public function test_structural_root_reason_remains_visible_in_host_blockers(): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[Storage::CHECK_ROOT_EXISTS] = StorageCheckResult::unknown(Storage::CHECK_ROOT_EXISTS, 'root_query_failed', self::TIME);
        $checks[Storage::CHECK_FIXED_VOLUME] = StorageCheckResult::violated(Storage::CHECK_FIXED_VOLUME, 'fixed_failure', false, true, self::TIME);
        $storage = StorageReport::aggregate(self::ROOT, $checks, false, self::policy());
        $this->assertSame(Outcome::BLOCKED, $storage->overall());
        $this->assertSame('STORAGE_ROOT_MISSING', $storage->reasonCode());
        $this->assertSame(['root_query_failed', 'fixed_failure', 'STORAGE_ROOT_MISSING'], $storage->blockers());
        $host = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, [], $storage, HypervReport::aggregate(self::hypervChecks()));
        $this->assertSame(Outcome::BLOCKED, $host->overall());
        $this->assertSame($storage->blockers(), $host->remainingBlockers());
        $this->assertSame(Outcome::UNKNOWN, $host->checks()[Storage::CHECK_ROOT_EXISTS]);
        $this->assertFalse($host->vmStartAuthorized());
    }

    public static function incompleteFilesystemFields(): array
    {
        return [
            ['reparsePointAbove', true, null], ['reparsePointAbove', false, null],
            ['reparsePointAbove', false, 'false'], ['reparsePointAbove', false, 0],
            ['canonicalPath', true, null], ['canonicalPath', false, null],
            ['canonicalPath', false, ''], ['canonicalPath', false, []],
            ['canonicalPath', false, 'relative'], ['canonicalPath', false, 'D:\\Sentinel\\..\\escape'],
            ['canonicalPath', false, "D:\\Sentinel\0suffix"],
        ];
    }

    #[DataProvider('incompleteFilesystemFields')]
    public function test_checker_does_not_substitute_positive_for_missing_fields(string $key, bool $missing, mixed $value): void
    {
        $observer = new MalformedObserver;
        $observer->filesystemPayload = ['exists' => true, 'type' => 'fixed', 'filesystem' => 'NTFS', 'canonicalPath' => self::ROOT, 'reparsePointAbove' => false];
        if ($missing) { unset($observer->filesystemPayload[$key]); } else { $observer->filesystemPayload[$key] = $value; }
        $observer->freeSpacePayload = ['bytesFree' => PHP_INT_MAX];
        $result = (new Storage($observer, self::policy()))->check(self::ROOT);
        $this->assertSame(Outcome::UNKNOWN, $result['report']->overall());
        $this->assertNotEmpty($result['report']->blockers());
        $id = $key === 'canonicalPath' ? Storage::CHECK_CANONICAL_PATH : Storage::CHECK_ROOT_REPARSE;
        $matching = array_values(array_filter($result['checks'], static fn ($c) => $c->id() === $id));
        $this->assertTrue($matching[0]->isUnknown());
    }

    public static function blankReasonCases(): array
    {
        $cases = [];
        $storageReasons = [
            Storage::CHECK_ROOT_EXISTS => 'STORAGE_ROOT_MISSING',
            Storage::CHECK_FIXED_VOLUME => 'storage_fixed_volume_required',
            Storage::CHECK_FILESYSTEM => 'storage_ntfs_required',
            Storage::CHECK_CAPACITY => 'storage_capacity_below_effective_floor',
            Storage::CHECK_CANONICAL_PATH => 'storage_canonical_path_unavailable',
            Storage::CHECK_ROOT_REPARSE => 'storage_root_is_reparse_point',
        ];
        foreach (['', " \t\r\n", "\u{00A0}\u{2003}"] as $blankIndex => $blank) {
            foreach ($storageReasons as $id => $negativeReason) {
                $unknownReason = $id === Storage::CHECK_CANONICAL_PATH ? 'storage_canonical_path_unavailable' : 'storage_observation_unavailable:' . $id;
                $cases['storage unknown:' . $id . ':' . $blankIndex] = ['storage', $id, 'UNKNOWN', $blank, $unknownReason];
                $cases['storage violated:' . $id . ':' . $blankIndex] = ['storage', $id, 'VIOLATED', $blank, $negativeReason];
            }
            foreach ([Hyperv::CHECK_MODULE, Hyperv::CHECK_SERVICE, Hyperv::CHECK_GROUP, Hyperv::CHECK_ELEVATION] as $id) {
                $cases['hyperv unknown:' . $id . ':' . $blankIndex] = ['hyperv', $id, 'UNKNOWN', $blank, 'hyperv_observation_unavailable:' . $id];
                foreach (['ACCESS_DENIED', 'NOT_INSTALLED', 'UNAVAILABLE'] as $status) {
                    $reason = match ($id) {
                        Hyperv::CHECK_MODULE => 'hyperv_module_not_importable',
                        Hyperv::CHECK_GROUP => 'hyperv_group_not_member',
                        Hyperv::CHECK_ELEVATION => 'hyperv_process_not_elevated',
                        Hyperv::CHECK_SERVICE => match ($status) {
                            'ACCESS_DENIED' => 'hyperv_vmms_access_denied',
                            'NOT_INSTALLED' => 'hyperv_vmms_not_installed',
                            'UNAVAILABLE' => 'hyperv_vmms_stopped',
                        },
                    };
                    $cases['hyperv negative:' . $id . ':' . $status . ':' . $blankIndex] = ['hyperv', $id, $status, $blank, $reason];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('blankReasonCases')]
    public function test_blank_non_pass_reasons_are_meaningful_without_changing_evidence(string $kind, string $id, string $status, string $blank, string $expectedReason): void
    {
        $storageChecks = self::storageChecks(self::policy());
        $hypervChecks = self::hypervChecks();
        if ($kind === 'storage') {
            $original = $status === 'UNKNOWN' ? StorageCheckResult::unknown($id, $blank, self::TIME)
                : StorageCheckResult::violated($id, $blank, ['retained_measurement'], ['retained_policy'], self::TIME);
            $storageChecks[$id] = $original;
        } else {
            $value = $id === Hyperv::CHECK_SERVICE ? 'Stopped' : false;
            $original = match ($status) {
                'UNKNOWN' => HypervCheckResult::unknown($id, $blank, self::TIME),
                'ACCESS_DENIED' => HypervCheckResult::accessDenied($id, $blank, $value, self::TIME),
                'NOT_INSTALLED' => HypervCheckResult::notInstalled($id, $blank, $value, self::TIME),
                'UNAVAILABLE' => HypervCheckResult::unavailable($id, $blank, $value, self::TIME),
            };
            $hypervChecks[$id] = $original;
        }
        $storage = StorageReport::aggregate(self::ROOT, $storageChecks, true, self::policy());
        $hyperv = HypervReport::aggregate($hypervChecks);
        $report = $kind === 'storage' ? $storage : $hyperv;
        $evaluated = array_values(array_filter($report->checks(), static fn ($c) => $c->id() === $id))[0];
        $this->assertSame(array_replace($original->toArray(), ['reasonCode' => $expectedReason]), $evaluated->toArray());
        $this->assertSame([$expectedReason], $report->blockers());
        $this->assertSame($expectedReason, $report->reasonCode());
        $expectedOutcome = $status === 'UNKNOWN' ? Outcome::UNKNOWN : Outcome::BLOCKED;
        $this->assertSame($expectedOutcome, $report->overall());
        $host = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, [], $storage, $hyperv);
        $this->assertSame($expectedOutcome, $host->overall());
        $this->assertSame([$expectedReason], $host->remainingBlockers());
        $this->assertFalse($host->vmStartAuthorized());
    }

    public function test_repaired_reasons_preserve_distinct_codes_order_and_deduplication(): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, '', 0, 100, self::TIME);
        $checks[] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'known_capacity_failure', 1, 100, self::TIME);
        $checks[] = StorageCheckResult::violated(Storage::CHECK_CAPACITY, 'known_capacity_failure', 1, 100, self::TIME);
        $storage = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $hypervChecks = self::hypervChecks();
        $hypervChecks[Hyperv::CHECK_GROUP] = HypervCheckResult::unknown(Hyperv::CHECK_GROUP, " \t", self::TIME);
        $hypervChecks[Hyperv::CHECK_SERVICE] = HypervCheckResult::accessDenied(Hyperv::CHECK_SERVICE, 'known_service_failure', null, self::TIME);
        $hyperv = HypervReport::aggregate($hypervChecks);
        $host = HostReadinessReport::make(HostReadinessStage::HOST_PREFLIGHT, Outcome::PASS, [], $storage, $hyperv);
        $this->assertSame(['storage_capacity_below_effective_floor', 'known_capacity_failure', 'known_service_failure', 'hyperv_observation_unavailable:' . Hyperv::CHECK_GROUP], $host->remainingBlockers());
        $this->assertSame(Outcome::BLOCKED, $host->overall());
        $this->assertFalse($host->vmStartAuthorized());
    }

    public static function policyObservationCases(): array
    {
        return [[false, Outcome::PASS], [true, Outcome::BLOCKED], [null, Outcome::UNKNOWN],
            ['false', Outcome::UNKNOWN], [0, Outcome::UNKNOWN], [[], Outcome::UNKNOWN]];
    }

    #[DataProvider('policyObservationCases')]
    public function test_optional_policy_observation_is_semantically_validated(mixed $value, string $expected): void
    {
        $checks = self::storageChecks(self::policy());
        $check = StorageCheckResult::policy(Storage::CHECK_ROOT_REPARSE, 'claimed_reparse', false, self::TIME, $value);
        $checks[Storage::CHECK_ROOT_REPARSE] = $check;
        $this->assertSame($value, $check->measuredValue());
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame($expected, $report->overall());
        $this->assertSame($expected === Outcome::PASS ? [] : [$expected === Outcome::BLOCKED ? 'storage_root_is_reparse_point' : 'storage_observation_unavailable:' . Storage::CHECK_ROOT_REPARSE], $report->blockers());
    }

    public static function directCanonicalCases(): array
    {
        return [["D:\\Sentinel\0suffix", Outcome::UNKNOWN], ["D:\\Sentinel\n", Outcome::UNKNOWN],
            ['', Outcome::UNKNOWN], ['relative', Outcome::UNKNOWN], ['D:\\Sentinel\\..\\escape', Outcome::UNKNOWN],
            ['D:\\' . str_repeat('a', 258), Outcome::UNKNOWN], [self::ROOT, Outcome::PASS]];
    }

    #[DataProvider('directCanonicalCases')]
    public function test_direct_factory_canonical_path_validation(string $path, string $expected): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[Storage::CHECK_CANONICAL_PATH] = StorageCheckResult::measured(Storage::CHECK_CANONICAL_PATH, 'claimed_path', $path, $path, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame($expected, $report->overall());
        $this->assertSame($expected === Outcome::PASS ? [] : ['storage_canonical_path_unavailable'], $report->blockers());
    }

    public function test_original_conflict_survives_equivalent_policy_and_path_normalization(): void
    {
        $checks = array_values(self::storageChecks(self::policy()));
        $checks[] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'storage_capacity_ok', PHP_INT_MAX, 0, self::TIME);
        $checks[] = StorageCheckResult::measured(Storage::CHECK_CANONICAL_PATH, 'storage_canonical_path_ok', 'D:/Sentinel', 'D:/Sentinel', self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        $this->assertSame(['storage_observation_unavailable:' . Storage::CHECK_CAPACITY, 'storage_observation_unavailable:' . Storage::CHECK_CANONICAL_PATH], $report->blockers());
        foreach ([Storage::CHECK_CAPACITY, Storage::CHECK_CANONICAL_PATH] as $id) {
            $matching = array_values(array_filter($report->checks(), static fn ($c) => $c->id() === $id));
            $this->assertSame($matching[0]->toArray(), $matching[1]->toArray());
            $this->assertTrue($matching[2]->isUnknown());
        }
    }

    public function test_positive_duplicates_under_stricter_policy_retain_evaluated_failure(): void
    {
        $checks = self::storageChecks(self::policy());
        $checks[Storage::CHECK_CAPACITY] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'old_positive', 1, 0, self::TIME);
        $checks[] = $checks[Storage::CHECK_CAPACITY];
        $checks[] = StorageCheckResult::measured(Storage::CHECK_CAPACITY, 'old_positive', 1, 1, self::TIME);
        $report = StorageReport::aggregate(self::ROOT, $checks, true, self::policy());
        $this->assertSame(Outcome::BLOCKED, $report->overall());
        $this->assertSame(['storage_capacity_below_effective_floor'], $report->blockers());
        $matching = array_values(array_filter($report->checks(), static fn ($c) => $c->id() === Storage::CHECK_CAPACITY));
        $this->assertCount(2, $matching);
        $this->assertTrue($matching[0]->isViolated());
        $this->assertTrue($matching[1]->isViolated());
    }

    public function test_reduced_observations_are_explicit_unknowns_in_canonical_aggregator(): void
    {
        $observer = new SyntheticHostReadinessObserver;
        $observer->filesystemObservation(self::ROOT, true, 'fixed', 'NTFS', self::ROOT, false)
            ->freeSpaceObservation(self::ROOT, PHP_INT_MAX, PHP_INT_MAX)
            ->serviceObservation('vmms', true, 'Running')
            ->throwOn('module', 'unsupported_operation')
            ->throwOn('group', 'unsupported_operation')
            ->throwOn('elevation', 'unsupported_operation');
        $report = (new HostReadinessAggregator(new Storage($observer, self::policy()), new Hyperv($observer)))->preflight(self::ROOT);
        $this->assertSame(Outcome::UNKNOWN, $report->overall());
        foreach ([Hyperv::CHECK_MODULE, Hyperv::CHECK_GROUP, Hyperv::CHECK_ELEVATION] as $id) {
            $this->assertSame(Outcome::UNKNOWN, $report->checks()[$id]);
        }
        $this->assertFalse($report->vmStartAuthorized());
    }
}
