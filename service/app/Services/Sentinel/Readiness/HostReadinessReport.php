<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HostReadinessReport — the single sanitized output of a HOST_PREFLIGHT
 * run. It is informational only and must never imply VM_START_AUTHORIZED.
 *
 * Every field is either a stable identifier, a measured value, or a
 * policy constant. The report never exposes:
 *
 *   - secrets, credentials or tokens
 *   - sensitive environment variables
 *   - absolute user-home paths
 *   - raw exception messages or stack traces
 *   - arbitrary command output
 *
 * vmStartAuthorized is a constant false. It is a field so that any
 * consumer that reads the report cannot mistake its absence for
 * authorization: the field is always present and always false.
 *
 * trustedControllerImplemented and trustedObserverImplemented are
 * likewise constant false, so no consumer can infer their presence
 * from the report shape.
 */
final class HostReadinessReport
{
    /**
     * @param  string                       $stage         one of HostReadinessStage
     * @param  string                       $overall       one of HostReadinessOutcome
     * @param  array<string, mixed>         $checks        named check results
     * @param  StorageReport                $storage       aggregated storage result
     * @param  HypervReport                 $hyperv        aggregated Hyper-V result
     * @param  array<int, string>           $remainingBlockers
     * @param  bool                         $vmStartAuthorized constant false
     */
    private function __construct(
        private readonly string $stage,
        private readonly string $overall,
        private readonly array $checks,
        private readonly StorageReport $storage,
        private readonly HypervReport $hyperv,
        private readonly array $remainingBlockers,
        private readonly bool $vmStartAuthorized,
    ) {
    }

    /**
     * @param  array<string, mixed>  $checks
     */
    public static function make(
        string $stage,
        string $overall,
        array $checks,
        StorageReport $storage,
        HypervReport $hyperv,
        array $remainingBlockers = [],
    ): self {
        if (! HostReadinessStage::isValid($stage)) {
            throw new \InvalidArgumentException(
                "HostReadinessReport stage must be one of: " . implode(', ', HostReadinessStage::all()),
            );
        }

        if (! HostReadinessOutcome::isValid($overall)) {
            throw new \InvalidArgumentException(
                "HostReadinessReport overall must be PASS, BLOCKED or UNKNOWN.",
            );
        }

        // Public arguments retain their signature, but cannot override canonical evidence.
        $overall = HostReadinessOutcome::aggregate([$storage->overall(), $hyperv->overall()]);
        $checks = [];
        foreach ($storage->checks() as $check) {
            $outcome = $check->isUnknown() ? HostReadinessOutcome::UNKNOWN
                : ($check->isViolated() ? HostReadinessOutcome::BLOCKED : HostReadinessOutcome::PASS);
            $checks[$check->id()] = HostReadinessOutcome::aggregate([
                $checks[$check->id()] ?? HostReadinessOutcome::PASS, $outcome,
            ]);
        }
        foreach ($hyperv->checks() as $check) {
            $checks[$check->id()] = HostReadinessOutcome::aggregate([
                $checks[$check->id()] ?? HostReadinessOutcome::PASS, $check->toOutcome(),
            ]);
        }
        $remainingBlockers = $overall === HostReadinessOutcome::PASS ? []
            : array_values(array_unique(array_merge($storage->blockers(), $hyperv->blockers())));

        return new self(
            $stage,
            $overall,
            $checks,
            $storage,
            $hyperv,
            $remainingBlockers,
            false,
        );
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function overall(): string
    {
        return $this->overall;
    }

    /**
     * @return array<string, mixed>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function storage(): StorageReport
    {
        return $this->storage;
    }

    public function hyperv(): HypervReport
    {
        return $this->hyperv;
    }

    /**
     * @return array<int, string>
     */
    public function remainingBlockers(): array
    {
        return $this->remainingBlockers;
    }

    /**
     * Constant false. The field is always present so its absence cannot
     * be misread as authorization by any consumer.
     */
    public function vmStartAuthorized(): bool
    {
        return false;
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
            'stage' => $this->stage,
            'overall' => $this->overall,
            'checks' => $this->checks,
            'storage' => $this->storage->toArray(),
            'hyperv' => $this->hyperv->toArray(),
            'remainingBlockers' => $this->remainingBlockers,
            'vmStartAuthorized' => $this->vmStartAuthorized,
            'trustedControllerImplemented' => false,
            'trustedObserverImplemented' => false,
        ];
    }

    /**
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
