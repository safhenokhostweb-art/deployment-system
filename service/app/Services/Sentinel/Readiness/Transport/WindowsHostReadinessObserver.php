<?php

declare(strict_types=1);

namespace App\Services\Sentinel\Readiness\Transport;

use App\Services\Sentinel\Readiness\HostReadinessObserver;
use App\Services\Sentinel\Readiness\SentinelReadinessObservationException;

/**
 * WindowsHostReadinessObserver - the native Windows implementation of
 * the HostReadinessObserver contract.
 *
 * It COLLECTS INFORMATION ONLY. It never authorizes VM startup, never
 * modifies Windows configuration, never creates a file, never starts a
 * service, never requests elevation and never changes an ACL.
 *
 * Architecture:
 *
 *   PowershellObservationTransport   allowlisted process execution
 *   PowershellAllowedScripts         closed registry of script texts
 *   PowershellObservationParser      typed validation of raw output
 *   WindowsHostReadinessObserver     contract implementation
 *
 * PowerShell command execution is deliberately kept out of any object
 * that makes a security policy decision. This class only translates a
 * transport result into the typed observation arrays the contract
 * requires; the existing StorageReadinessChecker and
 * HypervReadinessChecker evaluate them.
 *
 * Failure discipline:
 *
 *   - A timeout, a launch failure, a nonzero exit code, an
 *     over-length stdout, or any field that does not validate yields
 *     SentinelReadinessObservationException with a stable code. The
 *     checkers convert that to UNKNOWN, never to PASS.
 *   - A path that cannot be canonicalized yields canonicalPath=null so
 *     the checker reports STORAGE_CANONICAL_PATH_UNAVAILABLE.
 *   - A reparse point that cannot be inspected yields
 *     reparsePointAbove=false ONLY when the ancestor walk completed
 *     without error; otherwise the whole observation fails closed.
 *
 * Native observations are not equivalent to trusted security
 * attestation. This observer is not a Trusted Controller and is not an
 * independent security boundary.
 */
final class WindowsHostReadinessObserver implements HostReadinessObserver
{
    public function __construct(
        private readonly PowershellObservationTransport $transport,
        private readonly PowershellObservationParser $parser,
    ) {
    }

    /**
     * Observe a filesystem path without creating, modifying or
     * traversing beyond it.
     *
     * @return array{exists: bool, type: string, filesystem: ?string,
     *               canonicalPath: ?string, reparsePointAbove: bool,
     *               supportedFilesystem: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeFilesystem(string $path): array
    {
        $result = $this->transport->execute('filesystem', [
            'SENTINEL_OBSERVE_PATH' => $path,
        ]);

        $this->requireCleanExit($result, 'windows_fs');

        $fields = $this->parser->parseLines($result->stdout());

        $exists = $this->parser->parseFlag($fields, 'exists');

        $type = $this->parser->parseVolumeType($fields, 'type');

        $canonical = $this->parser->parseCanonicalPath($fields, 'canonicalPath');

        $reparse = $this->parser->parseFlag($fields, 'reparsePointAbove');

        $fs = $this->parser->parseFilesystemName($fields, 'filesystem');

        if (! $exists['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_fs_exists_unreadable',
            );
        }

        if (! $type['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_fs_volume_type_unreadable',
            );
        }

        if (! $reparse['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_fs_reparse_unreadable',
            );
        }

        if (! $fs['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_fs_filesystem_unreadable',
            );
        }

        return [
            'exists' => $exists['value'],
            'type' => $type['value'],
            'filesystem' => $fs['value'],
            'canonicalPath' => $canonical['ok'] ? $canonical['canonicalPath'] : null,
            'reparsePointAbove' => $reparse['value'],
            'supportedFilesystem' => $fs['value'] === 'NTFS',
            'observedAt' => date('c'),
        ];
    }

    /**
     * Observe available free space on the volume containing path.
     *
     * @return array{bytesFree: int, bytesTotal: int, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeFreeSpace(string $path): array
    {
        $result = $this->transport->execute('freespace', [
            'SENTINEL_OBSERVE_PATH' => $path,
        ]);

        $this->requireCleanExit($result, 'windows_freespace');

        $fields = $this->parser->parseLines($result->stdout());

        $free = $this->parser->parseNonNegativeInt($fields, 'bytesFree');

        $total = $this->parser->parseNonNegativeInt($fields, 'bytesTotal');

        if (! $free['ok'] || ! $total['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_freespace_unreadable',
            );
        }

        return [
            'bytesFree' => $free['value'],
            'bytesTotal' => $total['value'],
            'observedAt' => date('c'),
        ];
    }

    /**
     * Observe whether a Windows service is present and its current
     * state, without starting, stopping or configuring it.
     *
     * @return array{present: bool, state: ?string, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeServiceState(string $serviceShortName): array
    {
        $result = $this->transport->execute('service', [
            'SENTINEL_OBSERVE_SERVICE' => $serviceShortName,
        ]);

        $this->requireCleanExit($result, 'windows_service');

        $fields = $this->parser->parseLines($result->stdout());

        $present = $this->parser->parseFlag($fields, 'present');

        if (! $present['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_service_present_unreadable',
            );
        }

        if (! $present['value']) {
            return [
                'present' => false,
                'state' => null,
                'observedAt' => date('c'),
            ];
        }

        $state = $this->parser->parseServiceState($fields, 'state');

        return [
            'present' => true,
            'state' => $state['ok'] ? $state['value'] : null,
            'observedAt' => date('c'),
        ];
    }

    /**
     * Observe whether a PowerShell module is importable, without
     * executing arbitrary scripts.
     *
     * @return array{available: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeModuleAvailable(string $moduleName): array
    {
        $result = $this->transport->execute('module', [
            'SENTINEL_OBSERVE_MODULE' => $moduleName,
        ]);

        $this->requireCleanExit($result, 'windows_module');

        $fields = $this->parser->parseLines($result->stdout());

        $available = $this->parser->parseFlag($fields, 'available');

        if (! $available['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_module_unreadable',
            );
        }

        return [
            'available' => $available['value'],
            'observedAt' => date('c'),
        ];
    }

    /**
     * Observe whether the current process is running elevated, without
     * requesting or triggering elevation.
     *
     * @return array{elevated: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeProcessElevation(): array
    {
        $result = $this->transport->execute('elevation');

        $this->requireCleanExit($result, 'windows_elevation');

        $fields = $this->parser->parseLines($result->stdout());

        $elevated = $this->parser->parseFlag($fields, 'elevated');

        if (! $elevated['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_elevation_unreadable',
            );
        }

        return [
            'elevated' => $elevated['value'],
            'observedAt' => date('c'),
        ];
    }

    /**
     * Observe whether the current user is a member of a named local
     * group, without modifying group membership.
     *
     * @return array{member: bool, observedAt: string}
     *
     * @throws SentinelReadinessObservationException
     */
    public function observeGroupMembership(string $groupName): array
    {
        $result = $this->transport->execute('group', [
            'SENTINEL_OBSERVE_GROUP' => $groupName,
        ]);

        $this->requireCleanExit($result, 'windows_group');

        $fields = $this->parser->parseLines($result->stdout());

        $member = $this->parser->parseFlag($fields, 'member');

        if (! $member['ok']) {
            throw new SentinelReadinessObservationException(
                'windows_group_unreadable',
            );
        }

        return [
            'member' => $member['value'],
            'observedAt' => date('c'),
        ];
    }

    /**
     * The single failure gate for every probe.
     *
     * A non-clean exit is always a probe failure: timeout, launch
     * failure, or a nonzero exit code. Hitting the configured stdout
     * cap is likewise a probe failure — a probe that lost its own
     * output cannot be trusted to have measured anything.
     *
     * Native stderr is deliberately NOT included in the exception: the
     * message is a stable code so no account, path or machine
     * identifier can leak to a caller.
     */
    private function requireCleanExit(PowershellExecutionResult $result, string $prefix): void
    {
        if ($result->isCleanExit() && $result->truncatedAt() === null) {
            return;
        }

        $suffix = $result->truncatedAt() !== null
            ? 'stdout_truncated'
            : $result->observationCode();

        throw new SentinelReadinessObservationException(
            $prefix . '_' . $suffix,
        );
    }
}
