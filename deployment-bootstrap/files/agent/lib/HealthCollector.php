<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Phase 4-K Task 11: Server Health Foundation. Deliberately best-effort —
 * every metric degrades to null (never a fatal error, never blocks the
 * heartbeat) on a platform or environment where it can't be read. Reads
 * only OS-exposed aggregate numbers (/proc/meminfo, sys_getloadavg(),
 * disk_total_space()) — never a file listing, never anything
 * process/user-specific, nothing that could ever resemble a credential.
 */
final class HealthCollector
{
    /**
     * @return array{cpu_load_percent: ?float, memory_usage_percent: ?float, disk_usage_percent: ?float}
     */
    public function collect(): array
    {
        return [
            'cpu_load_percent' => $this->cpuLoadPercent(),
            'memory_usage_percent' => $this->memoryUsagePercent(),
            'disk_usage_percent' => $this->diskUsagePercent(),
        ];
    }

    private function cpuLoadPercent(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();
        if ($load === false || !isset($load[0])) {
            return null;
        }

        $cores = $this->cpuCoreCount();
        if ($cores < 1) {
            return null;
        }

        return round(min(($load[0] / $cores) * 100, 999), 1);
    }

    private function cpuCoreCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $contents = @file_get_contents('/proc/cpuinfo');
            if ($contents !== false) {
                $count = substr_count($contents, "\nprocessor");

                return max(1, $count);
            }
        }

        return 1;
    }

    private function memoryUsagePercent(): ?float
    {
        if (!is_file('/proc/meminfo')) {
            return null; // not Linux, or not readable — no fallback guess
        }

        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return null;
        }

        if (
            !preg_match('/MemTotal:\s+(\d+)/', $contents, $total)
            || !preg_match('/MemAvailable:\s+(\d+)/', $contents, $available)
        ) {
            return null;
        }

        $totalKb = (float) $total[1];
        if ($totalKb <= 0) {
            return null;
        }

        $usedKb = $totalKb - (float) $available[1];

        return round(($usedKb / $totalKb) * 100, 1);
    }

    private function diskUsagePercent(): ?float
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return round((($total - $free) / $total) * 100, 1);
    }
}
