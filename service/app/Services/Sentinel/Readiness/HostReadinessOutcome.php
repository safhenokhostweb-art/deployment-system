<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HostReadinessOutcome — the only three verdicts a readiness check
 * may ever return.
 *
 *   PASS       — measured evidence meets documented policy
 *   BLOCKED   — measured evidence violates documented policy
 *   UNKNOWN   — evidence is incomplete or uninterpretable
 *
 * Hard rules enforced by every consumer of this enum:
 *
 *  - UNKNOWN must never become PASS. A caller that wants to promote
 *    an UNKNOWN result must obtain a fresh, independently measured
 *    PASS; no post-hoc reinterpretation is permitted.
 *  - Missing evidence must never become PASS. Absence of a check
 *    result is UNKNOWN by definition, never PASS.
 *  - Caller-supplied VERIFIED markers must never authorize a
 *    security-sensitive operation. This enum carries no authorization
 *    semantics whatsoever.
 *  - HOST_PREFLIGHT == PASS must not imply VM_START_AUTHORIZED.
 *    No readiness stage in this package may ever return an
 *    authorization token for VM startup.
 */
final class HostReadinessOutcome
{
    public const PASS = 'PASS';
    public const BLOCKED = 'BLOCKED';
    public const UNKNOWN = 'UNKNOWN';

    public const VALUES = [
        self::PASS,
        self::BLOCKED,
        self::UNKNOWN,
    ];

    /** @return array<string> */
    public static function all(): array
    {
        return self::VALUES;
    }

    public static function isValid(string $outcome): bool
    {
        return in_array($outcome, self::VALUES, true);
    }

    /**
     * Fail-closed aggregation over a set of child outcomes.
     *
     *   any BLOCKED  -> BLOCKED
     *   any UNKNOWN  -> UNKNOWN
     *   else         -> PASS
     *
     * UNKNOWN is never promoted to PASS by aggregation: once any child
     * is UNKNOWN the aggregate is UNKNOWN, even if every other child
     * is PASS. This is the structural guarantee that missing evidence
     * can never be laundered into a pass.
     *
     * @param  array<string, string>  $outcomes
     */
    public static function aggregate(array $outcomes): string
    {
        // An empty collection carries no evidence at all. Absence of
        // a BLOCKED or UNKNOWN outcome is not evidence of a PASS: it
        // is evidence that nothing was measured. Report UNKNOWN so a
        // caller can never launder "we checked nothing" into "all
        // checks passed".
        if ($outcomes === []) {
            return self::UNKNOWN;
        }

        $hasBlocked = false;
        $hasUnknown = false;

        foreach ($outcomes as $outcome) {
            if (! self::isValid($outcome)) {
                $hasUnknown = true;

                continue;
            }

            if ($outcome === self::BLOCKED) {
                $hasBlocked = true;
            } elseif ($outcome !== self::PASS) {
                $hasUnknown = true;
            }
        }

        if ($hasBlocked) {
            return self::BLOCKED;
        }

        if ($hasUnknown) {
            return self::UNKNOWN;
        }

        return self::PASS;
    }
}