<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HostReadinessStage — the four declared phases of the Sentinel
 * readiness chain. Only HOST_PREFLIGHT is operationally implemented
 * in this phase. The other stages exist as identifiers so callers
 * can reference them without triggering any behaviour.
 *
 * Stages are deliberately NOT a state machine: nothing transitions
 * automatically. A caller that observes VM_PREBOOT, RUNTIME_VERIFICATION
 * or EVIDENCE_ACCEPTANCE receives a constant string and no side effect.
 *
 * HOST_PREFLIGHT is informational only. It must never imply
 * VM_START_AUTHORIZED and must never return an authorization token.
 */
final class HostReadinessStage
{
    public const HOST_PREFLIGHT = 'HOST_PREFLIGHT';
    public const VM_PREBOOT = 'VM_PREBOOT';
    public const RUNTIME_VERIFICATION = 'RUNTIME_VERIFICATION';
    public const EVIDENCE_ACCEPTANCE = 'EVIDENCE_ACCEPTANCE';

    public const ALL = [
        self::HOST_PREFLIGHT,
        self::VM_PREBOOT,
        self::RUNTIME_VERIFICATION,
        self::EVIDENCE_ACCEPTANCE,
    ];

    /** @return array<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::ALL, true);
    }
}