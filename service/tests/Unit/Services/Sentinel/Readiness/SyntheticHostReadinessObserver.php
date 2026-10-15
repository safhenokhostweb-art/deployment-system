<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness;

use App\Services\Sentinel\Readiness\HostReadinessObserver;
use App\Services\Sentinel\Readiness\SentinelReadinessObservationException;

/**
 * A synthetic HostReadinessObserver that returns canned observations.
 *
 * It touches nothing on the host. It exists so the readiness checkers
 * can be exercised in a unit test without any real filesystem, WMI or
 * PowerShell interaction.
 *
 * Mock-based tests are not proof of native behaviour; they verify the
 * checker's policy logic against observations the test controls.
 */
final class SyntheticHostReadinessObserver implements HostReadinessObserver
{
    private array $filesystem = [];

    private array $freeSpace = [];

    private array $services = [];

    private array $modules = [];

    private array $elevation = ['elevated' => false, 'observedAt' => '1970-01-01T00:00:00+00:00'];

    private array $groups = [];

    /** @var array<string, string> probe name -> observation code that should throw */
    private array $throwing = [];

    private int $callCount = 0;

    public function filesystemObservation(string $path, bool $exists, string $type = 'fixed', ?string $filesystem = 'NTFS', ?string $canonicalPath = null, bool $reparsePointAbove = false): self
    {
        $this->filesystem[$this->normalizeKey($path)] = [
            'exists' => $exists,
            'type' => $type,
            'filesystem' => $filesystem,
            'canonicalPath' => $canonicalPath,
            'reparsePointAbove' => $reparsePointAbove,
            'supportedFilesystem' => $filesystem === 'NTFS',
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    public function freeSpaceObservation(string $path, int $bytesFree, int $bytesTotal): self
    {
        $this->freeSpace[$this->normalizeKey($path)] = [
            'bytesFree' => $bytesFree,
            'bytesTotal' => $bytesTotal,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    public function serviceObservation(string $name, bool $present, ?string $state): self
    {
        $this->services[$this->normalizeKey($name)] = [
            'present' => $present,
            'state' => $state,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    public function moduleObservation(string $name, bool $available): self
    {
        $this->modules[$this->normalizeKey($name)] = [
            'available' => $available,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    public function elevationObservation(bool $elevated): self
    {
        $this->elevation = [
            'elevated' => $elevated,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    public function groupObservation(string $name, bool $member): self
    {
        $this->groups[$this->normalizeKey($name)] = [
            'member' => $member,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];

        return $this;
    }

    /**
     * Make a named probe throw SentinelReadinessObservationException.
     *
     * @param  string  $probe  filesystem|freeSpace|service|module|elevation|group
     */
    public function throwOn(string $probe, string $observationCode): self
    {
        $this->throwing[$probe] = $observationCode;

        return $this;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function observeFilesystem(string $path): array
    {
        $this->callCount++;

        if (isset($this->throwing['filesystem'])) {
            throw new SentinelReadinessObservationException($this->throwing['filesystem']);
        }

        $key = $this->normalizeKey($path);

        if (! isset($this->filesystem[$key])) {
            return [
                'exists' => false,
                'type' => 'unknown',
                'filesystem' => null,
                'canonicalPath' => null,
                'reparsePointAbove' => false,
                'supportedFilesystem' => false,
                'observedAt' => '1970-01-01T00:00:00+00:00',
            ];
        }

        return $this->filesystem[$key];
    }

    public function observeFreeSpace(string $path): array
    {
        $this->callCount++;

        if (isset($this->throwing['freeSpace'])) {
            throw new SentinelReadinessObservationException($this->throwing['freeSpace']);
        }

        $key = $this->normalizeKey($path);

        return $this->freeSpace[$key] ?? [
            'bytesFree' => 0,
            'bytesTotal' => 0,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
    }

    public function observeServiceState(string $serviceShortName): array
    {
        $this->callCount++;

        if (isset($this->throwing['service'])) {
            throw new SentinelReadinessObservationException($this->throwing['service']);
        }

        $key = $this->normalizeKey($serviceShortName);

        return $this->services[$key] ?? [
            'present' => false,
            'state' => null,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
    }

    public function observeModuleAvailable(string $moduleName): array
    {
        $this->callCount++;

        if (isset($this->throwing['module'])) {
            throw new SentinelReadinessObservationException($this->throwing['module']);
        }

        $key = $this->normalizeKey($moduleName);

        return $this->modules[$key] ?? [
            'available' => false,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
    }

    public function observeProcessElevation(): array
    {
        $this->callCount++;

        if (isset($this->throwing['elevation'])) {
            throw new SentinelReadinessObservationException($this->throwing['elevation']);
        }

        return $this->elevation;
    }

    public function observeGroupMembership(string $groupName): array
    {
        $this->callCount++;

        if (isset($this->throwing['group'])) {
            throw new SentinelReadinessObservationException($this->throwing['group']);
        }

        $key = $this->normalizeKey($groupName);

        return $this->groups[$key] ?? [
            'member' => false,
            'observedAt' => '1970-01-01T00:00:00+00:00',
        ];
    }

    private function normalizeKey(string $key): string
    {
        return str_replace(['\\', '/'], '_', strtolower(trim($key)));
    }
}
