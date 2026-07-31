<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Decides whether THIS poll cycle should spend a real HTTP round trip on
 * GET /api/local-agent/projects, instead of re-downloading and
 * re-validating the full project list on every single cycle (as the
 * first version of project-config sync did). Pure logic, no Client/
 * network/filesystem dependency, so it's directly unit-testable.
 *
 * Sync happens when any of the following is true:
 *   - this is the agent's first cycle (startup — always syncs), or
 *   - the configured TTL has elapsed since the last sync attempt, or
 *   - the Hub's heartbeat response reports a different config_version
 *     than what this agent last synchronized to, or
 *   - an explicit reload was requested (a Hub-issued `reload_config`
 *     Control Job — the only inbound channel to a developer's machine,
 *     see LocalAgentControlJob::ACTION_RELOAD_CONFIG).
 */
final class ProjectConfigSyncScheduler
{
    public function __construct(
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * @param int|null $lastSyncedAt unix timestamp of the last sync attempt (successful or not), or null before the first one this run
     * @param string|null $knownConfigVersion the config_version this agent last successfully synchronized to, or null before the first successful sync
     * @param string|null $heartbeatConfigVersion the config_version the Hub just reported on this cycle's heartbeat, or null if the heartbeat failed or omitted it
     */
    public function shouldSync(
        ?int $lastSyncedAt,
        ?string $knownConfigVersion,
        ?string $heartbeatConfigVersion,
        bool $reloadRequested,
        int $now,
    ): bool {
        if ($reloadRequested) {
            return true;
        }

        if ($lastSyncedAt === null) {
            return true;
        }

        if ($knownConfigVersion !== null && $heartbeatConfigVersion !== null && $heartbeatConfigVersion !== $knownConfigVersion) {
            return true;
        }

        return ($now - $lastSyncedAt) >= $this->ttlSeconds;
    }
}
