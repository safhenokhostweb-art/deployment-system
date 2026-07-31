<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * The fallback half of project-config sync — pure logic with no network
 * dependency (unlike the Hub request itself), so it is directly
 * unit-testable. Invoked only after a live Hub sync attempt has already
 * failed; resolves which configuration source to fall back to, in the
 * required priority order:
 *   1. synchronized configuration (handled by the caller before this is
 *      ever invoked — never re-implemented here)
 *   2. this machine's last good local cache
 *   3. the manually-authored config.php `projects` array (legacy,
 *      compatibility only)
 */
final class ProjectConfigResolver
{
    public function __construct(
        private readonly ProjectConfigCache $cache,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, array{local_path: string, remote_name: string, branch: string}> $legacyProjects
     * @return array<string, array{local_path: string, remote_name: string, branch: string}>
     */
    public function resolve(array $legacyProjects): array
    {
        try {
            $cached = $this->cache->load();
        } catch (InvalidProjectCacheException $e) {
            $this->logger->error('Invalid cache: ' . $e->getMessage());
            $cached = null;
        }

        if ($cached !== null) {
            $this->logger->info('Cache loaded from ' . $this->cache->path() . ' (' . count($cached) . ' project(s)).');
            $this->logger->warn('Hub unavailable — using cached project configuration.');

            return $cached;
        }

        if ($legacyProjects !== []) {
            // Deliberately logged at ERROR (not WARN) even though the
            // agent keeps running — an operator must never mistake this
            // for synchronization "just running a bit stale". No Hub
            // communication and no cache both being unavailable at once
            // is a state worth surfacing loudly, every single time it
            // happens, not something to soften into routine noise.
            $this->logger->error(
                'USING LEGACY CONFIGURATION — SYNCHRONIZATION IS NOT ACTIVE. '
                . 'Reading the manually-authored "projects" array from config.php (' . count($legacyProjects) . ' project(s)) '
                . 'because no Hub sync has ever succeeded and no valid local cache exists yet. '
                . 'This is a TEMPORARY compatibility fallback, not normal operation: create Local Project Mappings for '
                . 'this agent in the Hub (Local Agents -> Project mappings) as soon as it is reachable to restore synchronization.'
            );

            return $legacyProjects;
        }

        $this->logger->error('No project configuration available: the Hub is unreachable, no valid local cache exists, and no legacy "projects" array is configured in config.php.');

        return [];
    }
}
