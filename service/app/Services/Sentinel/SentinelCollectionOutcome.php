<?php

namespace App\Services\Sentinel;

use JsonException;

/**
 * The product of one collection run: the evidence document (when it
 * was representable under the sentinel.v1 contract) plus the verdict
 * produced by the existing SentinelVerifier.
 *
 * `evidence` is null only when the collected source data itself could
 * not be represented as evidence (e.g. a source tried to smuggle
 * credential-shaped content). In that case the verdict is fail-closed
 * UNKNOWN — never VERIFIED, and no source value is echoed anywhere.
 */
final class SentinelCollectionOutcome
{
    public const VERIFIED = SentinelVerificationResult::VERIFIED;
    public const FAILED = SentinelVerificationResult::FAILED;
    public const UNKNOWN = SentinelVerificationResult::UNKNOWN;

    private function __construct(
        private readonly ?SentinelEvidenceContract $evidence,
        private readonly SentinelVerificationResult $result,
    ) {
    }

    public static function captured(SentinelEvidenceContract $evidence, SentinelVerificationResult $result): self
    {
        return new self($evidence, $result);
    }

    /**
     * Fail-closed outcome for source data that cannot enter the
     * contract. Generic detail only — adapter payloads and exception
     * messages are never propagated.
     *
     * @param  array<string, mixed>  $expectation
     */
    public static function unrepresentable(array $expectation = []): self
    {
        $result = SentinelVerificationResult::make(
            SentinelVerificationResult::UNKNOWN,
            [
                'evidence_build' => [
                    'status' => SentinelEvidenceContract::CHECK_STATUS_UNKNOWN,
                    'detail' => 'collected source data could not be represented as sentinel.v1 evidence.',
                ],
            ],
            '',
            $expectation,
        );

        return new self(null, $result);
    }

    /** Generic inconclusive result for a collection adapter that failed unexpectedly. */
    public static function unavailable(array $expectation = []): self
    {
        $result = SentinelVerificationResult::make(
            SentinelVerificationResult::UNKNOWN,
            [
                'evidence_collection' => [
                    'status' => SentinelEvidenceContract::CHECK_STATUS_UNKNOWN,
                    'detail' => 'evidence collection did not complete.',
                ],
            ],
            '',
            $expectation,
        );

        return new self(null, $result);
    }

    public function evidence(): ?SentinelEvidenceContract
    {
        return $this->evidence;
    }

    public function result(): SentinelVerificationResult
    {
        return $this->result;
    }

    /** Exactly one of VERIFIED, FAILED, UNKNOWN. */
    public function verdict(): string
    {
        return $this->result->result();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'evidence_hash' => $this->result->evidenceHash(),
            'evidence' => $this->evidence?->toArray(),
            'checks' => $this->result->checks(),
            'reasons' => $this->result->reasons(),
            'expectation' => $this->result->expectation(),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
