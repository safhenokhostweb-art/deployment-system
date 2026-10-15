<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness;

use App\Services\Sentinel\Readiness\HostReadinessObserver;
use App\Services\Sentinel\Readiness\SentinelReadinessObservationException;

/**
 * MalformedObserver — a HostReadinessObserver that returns deliberately
 * malformed payloads (missing keys, wrong scalar types, negative
 * values). It exists only to prove the checkers fail closed when an
 * observer hands them garbage instead of a well-formed observation.
 *
 * It touches nothing on the host.
 */
final class MalformedObserver implements HostReadinessObserver
{
    public array $filesystemPayload = [];

    public array $freeSpacePayload = [];

    public array $servicePayload = [];

    public bool $throwOnFilesystem = false;

    public bool $throwOnFreeSpace = false;

    public bool $throwOnService = false;

    public bool $throwOnModule = false;

    public bool $throwOnElevation = false;

    public bool $throwOnGroup = false;

    public function observeFilesystem(string $path): array
    {
        if ($this->throwOnFilesystem) {
            throw new SentinelReadinessObservationException('fs_probe_failed');
        }

        return $this->filesystemPayload;
    }

    public function observeFreeSpace(string $path): array
    {
        if ($this->throwOnFreeSpace) {
            throw new SentinelReadinessObservationException('volume_query_failed');
        }

        return $this->freeSpacePayload;
    }

    public function observeServiceState(string $serviceShortName): array
    {
        if ($this->throwOnService) {
            throw new SentinelReadinessObservationException('service_query_failed');
        }

        return $this->servicePayload;
    }

    public function observeModuleAvailable(string $moduleName): array
    {
        if ($this->throwOnModule) {
            throw new SentinelReadinessObservationException('module_query_failed');
        }

        return ['available' => false, 'observedAt' => '1970-01-01T00:00:00+00:00'];
    }

    public function observeProcessElevation(): array
    {
        if ($this->throwOnElevation) {
            throw new SentinelReadinessObservationException('elevation_probe_failed');
        }

        return ['elevated' => false, 'observedAt' => '1970-01-01T00:00:00+00:00'];
    }

    public function observeGroupMembership(string $groupName): array
    {
        if ($this->throwOnGroup) {
            throw new SentinelReadinessObservationException('group_probe_failed');
        }

        return ['member' => false, 'observedAt' => '1970-01-01T00:00:00+00:00'];
    }
}
