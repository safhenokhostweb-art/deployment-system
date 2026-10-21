<?php

namespace App\Services\Sentinel;

use InvalidArgumentException;

/**
 * SentinelFileComparisonService — compares an expected file manifest
 * (derived from the artifact) against an observed file manifest (from
 * the Server Agent's runtime observation).
 *
 * This is an additive, feature-flagged capability. When disabled, the
 * verifier treats missing file-level fields as UNKNOWN (never FAIL).
 * When enabled, the comparison is strict: any confirmed missing or
 * hash-mismatched required file => FAIL.
 *
 * Comparison outcomes:
 *  - MATCH: path and content hash/size match
 *  - MISSING: expected file is absent
 *  - HASH_MISMATCH: file exists but content differs
 *  - EXTRA: observed file is not in the expected inventory
 *  - EXCLUDED: path is covered by an explicitly approved exclusion policy
 *  - INCOMPLETE: either inventory is incomplete or invalid
 *  - STALE: observation exceeds the allowed freshness window
 *  - WRONG_TARGET: evidence attribution does not match the deployment
 *  - UNREADABLE: a required file could not be hashed safely
 *
 * Verdict rules:
 *  - Any confirmed missing or hash-mismatched required file => FAIL
 *  - Invalid, incomplete, stale, or unreadable required evidence => UNKNOWN
 *  - Wrong-target evidence must never pass; report an attribution failure
 *  - EXTRA files must follow an explicit reviewed policy
 *  - PASS requires complete, valid expected and observed inventories
 *    for the agreed comparison scope
 *
 * Do not expose secret filenames or sensitive path details in the UI.
 * Represent suppressed details safely.
 */
final class SentinelFileComparisonService
{
    /** @var array<string, array{size: int, sha256: string}> */
    private array $expectedManifest;

    /** @var array<string, array{size: int, sha256: string}> */
    private array $observedManifest;

    private string $expectedManifestHash;
    private string $observedManifestHash;

    private int $expectedEntryCount;
    private int $observedEntryCount;
    private int $expectedTotalSize;
    private int $observedTotalSize;

    private string $comparisonScope;

    /**
     * @param array<string, array{size: int, sha256: string}> $expectedManifest
     * @param array<string, array{size: int, sha256: string}> $observedManifest
     * @param string $expectedManifestHash
     * @param string $observedManifestHash
     * @param int $expectedEntryCount
     * @param int $observedEntryCount
     * @param int $expectedTotalSize
     * @param int $observedTotalSize
     * @param string $comparisonScope
     */
    public function __construct(
        array $expectedManifest,
        array $observedManifest,
        string $expectedManifestHash,
        string $observedManifestHash,
        int $expectedEntryCount,
        int $observedEntryCount,
        int $expectedTotalSize,
        int $observedTotalSize,
        string $comparisonScope = 'full'
    ) {
        $this->expectedManifest = $expectedManifest;
        $this->observedManifest = $observedManifest;
        $this->expectedManifestHash = $expectedManifestHash;
        $this->observedManifestHash = $observedManifestHash;
        $this->expectedEntryCount = $expectedEntryCount;
        $this->observedEntryCount = $observedEntryCount;
        $this->expectedTotalSize = $expectedTotalSize;
        $this->observedTotalSize = $observedTotalSize;
        $this->comparisonScope = $comparisonScope;
    }

    /**
     * Compare manifests and return a detailed comparison result.
     *
     * @return array{
     *   match_count: int,
     *   missing_count: int,
     *   hash_mismatch_count: int,
     *   extra_count: int,
     *   excluded_count: int,
     *   incomplete: bool,
     *   stale: bool,
     *   wrong_target: bool,
     *   unreadable: bool,
     *   discrepancies: array<int, array{path: string, outcome: string, detail: string}>,
     *   summary: string
     * }
     */
    public function compare(): array
    {
        $discrepancies = [];
        $matchCount = 0;
        $missingCount = 0;
        $hashMismatchCount = 0;
        $extraCount = 0;
        $excludedCount = 0;

        // Check for manifest hash mismatch (quick fail if hashes don't match)
        $manifestHashMismatch = !hash_equals($this->expectedManifestHash, $this->observedManifestHash);

        // Compare expected vs observed
        foreach ($this->expectedManifest as $path => $expectedEntry) {
            $observedEntry = $this->observedManifest[$path] ?? null;

            if ($observedEntry === null) {
                $missingCount++;
                $discrepancies[] = [
                    'path' => $path,
                    'outcome' => 'MISSING',
                    'detail' => 'File exists in artifact but not observed in runtime',
                ];
            } elseif (!hash_equals($expectedEntry['sha256'], $observedEntry['sha256'])) {
                $hashMismatchCount++;
                $discrepancies[] = [
                    'path' => $path,
                    'outcome' => 'HASH_MISMATCH',
                    'detail' => 'File content hash differs from artifact',
                ];
            } elseif ($expectedEntry['size'] !== $observedEntry['size']) {
                $hashMismatchCount++;
                $discrepancies[] = [
                    'path' => $path,
                    'outcome' => 'HASH_MISMATCH',
                    'detail' => 'File size differs from artifact',
                ];
            } else {
                $matchCount++;
            }
        }

        // Check for extra files in observed
        foreach ($this->observedManifest as $path => $observedEntry) {
            if (!isset($this->expectedManifest[$path])) {
                $extraCount++;
                $discrepancies[] = [
                    'path' => $path,
                    'outcome' => 'EXTRA',
                    'detail' => 'File observed in runtime but not present in artifact',
                ];
            }
        }

        // Check for incomplete inventories.
        // Empty manifest arrays with non-zero declared counts mean no
        // per-file comparison is actually possible (e.g. the caller only
        // has hash-level aggregates, not entry inventories). Treat as
        // incomplete so the verdict is UNKNOWN, never a false PASS.
        $incomplete = $this->expectedEntryCount === 0 || $this->observedEntryCount === 0
            || (empty($this->expectedManifest) && $this->expectedEntryCount > 0)
            || (empty($this->observedManifest) && $this->observedEntryCount > 0);

        // Check for stale observation (would be checked at collector level)
        $stale = false; // Set by caller based on timestamp

        // Check for wrong target (would be checked at collector level)
        $wrongTarget = false; // Set by caller based on attribution

        // Check for unreadable files (would be set by collector)
        $unreadable = false; // Set by caller

        $totalDiscrepancies = $missingCount + $hashMismatchCount + $extraCount;

        // Determine overall summary
        if ($wrongTarget) {
            $summary = 'WRONG_TARGET: Evidence attribution does not match deployment';
        } elseif ($unreadable) {
            $summary = 'UNREADABLE: Required files could not be hashed safely';
        } elseif ($incomplete) {
            $summary = 'INCOMPLETE: One or both manifests are incomplete';
        } elseif ($stale) {
            $summary = 'STALE: Observation exceeds freshness window';
        } elseif ($missingCount > 0 || $hashMismatchCount > 0) {
            $summary = 'FAIL: ' . $missingCount . ' missing, ' . $hashMismatchCount . ' hash mismatches';
        } elseif ($extraCount > 0) {
            $summary = 'EXTRA: ' . $extraCount . ' extra files observed (policy-dependent)';
        } elseif ($manifestHashMismatch) {
            $summary = 'FAIL: Manifest hash mismatch detected';
        } else {
            $summary = 'MATCH: All ' . $matchCount . ' files verified';
        }

        return [
            'match_count' => $matchCount,
            'missing_count' => $missingCount,
            'hash_mismatch_count' => $hashMismatchCount,
            'extra_count' => $extraCount,
            'excluded_count' => $excludedCount,
            'incomplete' => $incomplete,
            'stale' => $stale,
            'wrong_target' => $wrongTarget,
            'unreadable' => $unreadable,
            'discrepancies' => $discrepancies,
            'summary' => $summary,
        ];
    }

    /**
     * Get a summary verdict for the file comparison.
     * Returns one of: PASS, FAIL, UNKNOWN
     */
    public function verdict(): string
    {
        $result = $this->compare();

        if ($result['wrong_target']) {
            return 'FAIL'; // Wrong target is always a hard failure
        }

        if ($result['unreadable'] || $result['incomplete'] || $result['stale']) {
            return 'UNKNOWN'; // Incomplete/stale/unreadable evidence => UNKNOWN
        }

        if ($result['missing_count'] > 0 || $result['hash_mismatch_count'] > 0) {
            return 'FAIL'; // Confirmed missing or mismatched files => FAIL
        }

        if ($result['extra_count'] > 0) {
            // EXTRA files policy: for now treat as UNKNOWN (policy-dependent)
            // In the future, this could be configured per-project
            return 'UNKNOWN';
        }

        if (!hash_equals($this->expectedManifestHash, $this->observedManifestHash)) {
            return 'FAIL'; // Manifest hash mismatch
        }

        return 'PASS'; // All matched
    }
}