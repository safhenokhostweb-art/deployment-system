<?php

namespace App\Services\Sentinel;

use InvalidArgumentException;
use JsonException;

/**
 * SentinelVerificationResult — the only three outcomes the Deployment
 * Proof engine may ever report:
 *
 *   VERIFIED  every required evidence link present and matching
 *   FAILED    at least one link is proven wrong
 *   UNKNOWN   evidence is incomplete or uninterpretable — NOT a pass
 *
 * UNKNOWN and FAILED are deliberately distinct: UNKNOWN means "we
 * cannot prove it", FAILED means "we proved it wrong". Neither is ever
 * collapsed into a success state, and VERIFIED is only ever produced
 * when every individual check passed (fail-closed aggregation in
 * SentinelVerifier).
 *
 * The result is immutable and serializable so it can be stored,
 * hashed and diffed alongside its evidence document.
 */
final class SentinelVerificationResult
{
    public const VERIFIED = SentinelEvidenceContract::RESULT_VERIFIED;
    public const FAILED = SentinelEvidenceContract::RESULT_FAILED;
    public const UNKNOWN = SentinelEvidenceContract::RESULT_UNKNOWN;

    /**
     * @param array<string, array{status: string, detail?: string}> $checks
     * @param array<string, mixed>                                  $expectation the context this verdict answers
     */
    private function __construct(
        private readonly string $result,
        private readonly array $checks,
        private readonly string $evidenceHash,
        private readonly array $expectation,
    ) {
        if (! in_array($result, [self::VERIFIED, self::FAILED, self::UNKNOWN], true)) {
            throw new InvalidArgumentException("Sentinel verification result must be VERIFIED, FAILED or UNKNOWN, got '{$result}'.");
        }
    }

    /**
     * @param array<string, array{status: string, detail?: string}> $checks
     * @param array<string, mixed>                                  $expectation
     */
    public static function make(string $result, array $checks, string $evidenceHash, array $expectation = []): self
    {
        return new self($result, $checks, $evidenceHash, $expectation);
    }

    public function result(): string
    {
        return $this->result;
    }

    public function isVerified(): bool
    {
        return $this->result === self::VERIFIED;
    }

    public function isFailed(): bool
    {
        return $this->result === self::FAILED;
    }

    public function isUnknown(): bool
    {
        return $this->result === self::UNKNOWN;
    }

    /**
     * @return array<string, array{status: string, detail?: string}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function statusOf(string $checkId): ?string
    {
        return $this->checks[$checkId]['status'] ?? null;
    }

    public function evidenceHash(): string
    {
        return $this->evidenceHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function expectation(): array
    {
        return $this->expectation;
    }

    /**
     * Machine-readable reasons for every non-passing check. Empty for
     * VERIFIED. This is what a report renders — never a bare status.
     *
     * @return array<int, string>
     */
    public function reasons(): array
    {
        $reasons = [];

        foreach ($this->checks as $id => $check) {
            if (($check['status'] ?? null) === SentinelEvidenceContract::CHECK_STATUS_PASS) {
                continue;
            }
            $detail = $check['detail'] ?? 'no detail';

            $reasons[] = "{$id} [".strtoupper((string) ($check['status'] ?? 'unknown'))."]: {$detail}";
        }

        return $reasons;
    }

    /**
     * Stamp this verdict onto the evidence document, returning a NEW
     * evidence contract (the original stays untouched).
     */
    public function stamp(SentinelEvidenceContract $evidence): SentinelEvidenceContract
    {
        return $evidence->withResult($this->result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'result' => $this->result,
            'evidence_hash' => $this->evidenceHash,
            'checks' => $this->checks,
            'reasons' => $this->reasons(),
            'expectation' => $this->expectation,
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
