<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Restart-loop protection (section 9): pure logic, no filesystem/network
 * dependency, so it's directly unit-testable. A Hub-issued `restart`
 * Control Job the main agent itself never claimed (because it's hung)
 * would otherwise let the watchdog kill-and-relaunch it every single
 * poll cycle if the same underlying problem recurs immediately after
 * each restart — this enforces a minimum quiet period between two
 * watchdog-initiated restarts.
 */
final class WatchdogCooldown
{
    public function __construct(
        private readonly int $cooldownSeconds,
    ) {
    }

    public function isActive(?int $lastRestartAt, int $now): bool
    {
        if ($lastRestartAt === null) {
            return false;
        }

        return ($now - $lastRestartAt) < $this->cooldownSeconds;
    }

    public function remainingSeconds(?int $lastRestartAt, int $now): int
    {
        if ($lastRestartAt === null) {
            return 0;
        }

        return max(0, $this->cooldownSeconds - ($now - $lastRestartAt));
    }
}
