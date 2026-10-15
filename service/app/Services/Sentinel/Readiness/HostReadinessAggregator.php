<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HostReadinessAggregator — the top-level read-only Host Readiness
 * Checker for the HOST_PREFLIGHT stage.
 *
 * It composes the storage and Hyper-V capability checkers, aggregates
 * their outcomes and produces a single sanitized HostReadinessReport.
 *
 * HOST_PREFLIGHT is informational only. The report's vmStartAuthorized
 * field is a constant false; no code path in this class can produce a
 * VM startup authorization, and no combination of PASS outcomes can be
 * read as one.
 *
 * UNKNOWN never becomes PASS: aggregation is fail-closed at every
 * level (individual checks, StorageReport, HypervReport and the final
 * overall outcome).
 *
 * Caller-controlled VERIFIED markers are not accepted anywhere in this
 * chain. The aggregator only reads measured observations from a
 * HostReadinessObserver and policy values from a StoragePolicy.
 */
final class HostReadinessAggregator
{
    public function __construct(
        private readonly StorageReadinessChecker $storageChecker,
        private readonly HypervReadinessChecker $hypervChecker,
    ) {
    }

    /**
     * Run HOST_PREFLIGHT for a proposed storage root.
     *
     * HOST_PREFLIGHT is informational only. The returned report has
     * vmStartAuthorized === false always, and no field of the report
     * may be read as an authorization to start a VM.
     *
     * @param  string|null  $rootPath  proposed storage root; null or
     *                                 blank yields an UNKNOWN report
     */
    public function preflight(?string $rootPath): HostReadinessReport
    {
        $checks = [];

        if ($rootPath === null || trim($rootPath) === '') {
            $storageReport = StorageReport::aggregate(
                $rootPath ?? '',
                [StorageCheckResult::unknown(
                    'storage_root_path',
                    HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING,
                    $this->observedAt(),
                )],
                false,
                $this->storageChecker->policy(),
            );
            $hypervReport = HypervReport::aggregate([
                HypervCheckResult::unknown(
                    'hyperv_root_path',
                    HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING,
                    $this->observedAt(),
                ),
            ]);
            $checks[HostReadinessReasonCodes::STORAGE_ROOT_PATH_MISSING] = HostReadinessOutcome::UNKNOWN;

            return $this->assemble($storageReport, $hypervReport, $checks);
        }

        $storage = $this->storageChecker->check($rootPath);
        $storageReport = $storage['report'];

        foreach ($storage['checks'] as $check) {
            $checks[$check->id()] = $this->storageCheckOutcome($check);
        }

        $hyperv = $this->hypervChecker->check();
        $hypervReport = $hyperv['report'];

        foreach ($hyperv['checks'] as $check) {
            $checks[$check->id()] = $check->toOutcome();
        }

        return $this->assemble($storageReport, $hypervReport, $checks);
    }

    /**
     * @param  array<string, string>  $checks
     */
    private function assemble(StorageReport $storage, HypervReport $hyperv, array $checks): HostReadinessReport
    {
        $overall = HostReadinessOutcome::aggregate([
            $storage->overall(),
            $hyperv->overall(),
        ]);

        $blockers = $overall === HostReadinessOutcome::PASS
            ? []
            : $this->remainingBlockers($storage, $hyperv);

        return HostReadinessReport::make(
            HostReadinessStage::HOST_PREFLIGHT,
            $overall,
            $checks,
            $storage,
            $hyperv,
            $blockers,
        );
    }

    /**
     * Every distinct failed or unknown check across both categories,
     * storage first then Hyper-V, in the order the checks were
     * supplied. Positive checks are never included. Duplicates across
     * the two categories are collapsed.
     *
     * @return array<int, string>
     */
    private function remainingBlockers(StorageReport $storage, HypervReport $hyperv): array
    {
        $blockers = [];

        foreach ($storage->blockers() as $blocker) {
            $blockers[] = $blocker;
        }

        foreach ($hyperv->blockers() as $blocker) {
            $blockers[] = $blocker;
        }

        return array_values(array_unique($blockers));
    }

    private function storageCheckOutcome(StorageCheckResult $check): string
    {
        if ($check->isUnknown()) {
            return HostReadinessOutcome::UNKNOWN;
        }

        if ($check->isViolated()) {
            return HostReadinessOutcome::BLOCKED;
        }

        if ($check->id() === StorageReadinessChecker::CHECK_ROOT_EXISTS) {
            return $check->reasonCode() === 'storage_root_present'
                ? HostReadinessOutcome::PASS
                : HostReadinessOutcome::BLOCKED;
        }

        return $check->canContributeToPass()
            ? HostReadinessOutcome::PASS
            : HostReadinessOutcome::BLOCKED;
    }

    private function observedAt(): string
    {
        // Deliberately NOT a real wall clock: a unit test must never
        // depend on the host's clock, and a fixed value keeps the
        // reports deterministic.
        return '1970-01-01T00:00:00+00:00';
    }
}
