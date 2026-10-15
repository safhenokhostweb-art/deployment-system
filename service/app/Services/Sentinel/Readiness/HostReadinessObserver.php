<?php

namespace App\Services\Sentinel\Readiness;

use RuntimeException;

/**
 * HostReadinessObserver — the read-only observation boundary for
 * Host Readiness checks.
 *
 * Every real host interaction (filesystem probes, service status,
 * module loading, elevation probes) flows through an observer
 * implementation. Unit tests supply a synthetic observer that
 * returns canned observations without touching the host at all.
 *
 * The interface is deliberately narrow:
 *
 *  - No method here creates, starts, stops or mutates anything.
 *  - No method here accepts caller-controlled "verified" claims.
 *  - No method here returns secrets, credentials or raw exception
 *    text; observers throw SentinelReadinessObservationException
 *    with a stable code when a probe cannot be answered.
 *
 * A real observer implementation may use allowlisted PowerShell
 * commands, WMI, or filesystem APIs, but must:
 *
 *  - enforce a bounded execution timeout,
 *  - capture and sanitize stderr,
 *  - fail closed on any error (throw, never return null),
 *  - never interpolate untrusted shell fragments.
 */
interface HostReadinessObserver
{
    /**
     * Observe a filesystem path without creating, modifying or
     * traversing beyond it.
     *
     * Implementations must NOT create the path, its parents, or any
     * temporary file. A missing root is reported via the returned
     * array with `exists=false`; it is NOT an exception.
     *
     * @param  string  $path  absolute path to probe
     * @return array{exists: bool, type: string, filesystem: ?string,
     *               canonicalPath: ?string, reparsePointAbove: bool,
     *               supportedFilesystem: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the probe
     *                                               itself fails in a
     *                                               way that cannot
     *                                               be represented
     *                                               as a structured
     *                                               observation
     */
    public function observeFilesystem(string $path): array;

    /**
     * Observe available free space on the volume containing path.
     *
     * @param  string  $path  absolute path on the volume to probe
     * @return array{bytesFree: int, bytesTotal: int, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the volume
     *                                               cannot be queried
     */
    public function observeFreeSpace(string $path): array;

    /**
     * Observe whether a Windows service is present and its current
     * state, without starting, stopping or configuring it.
     *
     * @param  string  $serviceShortName  e.g. 'vmms'
     * @return array{present: bool, state: ?string, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the service
     *                                               query itself fails
     */
    public function observeServiceState(string $serviceShortName): array;

    /**
     * Observe whether a PowerShell module is importable, without
     * executing arbitrary scripts.
     *
     * @param  string  $moduleName  e.g. 'Hyper-V'
     * @return array{available: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the module
     *                                               query fails
     */
    public function observeModuleAvailable(string $moduleName): array;

    /**
     * Observe whether the current process is running elevated,
     * without requesting or triggering elevation.
     *
     * @return array{elevated: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the elevation
     *                                               probe fails
     */
    public function observeProcessElevation(): array;

    /**
     * Observe whether the current user is a member of a named local
     * group, without modifying group membership.
     *
     * @param  string  $groupName  e.g. 'Hyper-V Administrators'
     * @return array{member: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException when the
     *                                               membership probe
     *                                               fails
     */
    public function observeGroupMembership(string $groupName): array;
}

/**
 * SentinelReadinessObservationException — thrown by an observer when
 * a probe cannot produce a structured observation.
 *
 * The message is a stable code, never raw OS output, never a path the
 * caller supplied, never an exception chain. Callers treat this as
 * UNKNOWN, never as PASS.
 */
final class SentinelReadinessObservationException extends RuntimeException
{
    private readonly string $observationCode;

    public function __construct(string $observationCode, ?Throwable $previous = null)
    {
        parent::__construct($observationCode, 0, $previous);
        $this->observationCode = $observationCode;
    }

    public function code(): string
    {
        return $this->observationCode;
    }
}