<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Tiny persisted state for the watchdog — currently just
 * last_restart_at, read by WatchdogCooldown across separate watchdog
 * invocations (each Scheduled Task run is a fresh PHP process, so
 * in-memory state can't survive between ticks). Same atomic
 * write-to-temp-then-rename() pattern as ProjectConfigCache, for the same
 * reason: a crash mid-write must never leave a corrupt file that then
 * silently breaks cooldown enforcement.
 */
final class WatchdogState
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function lastRestartAt(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $value = $decoded['last_restart_at'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function recordRestart(int $timestamp): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create watchdog state directory {$dir}");
        }

        $json = json_encode(['last_restart_at' => $timestamp], JSON_THROW_ON_ERROR);

        $tmpPath = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write temporary watchdog state file {$tmpPath}");
        }

        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Could not atomically replace watchdog state file at {$this->path}");
        }
    }
}
