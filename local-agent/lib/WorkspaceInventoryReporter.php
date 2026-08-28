<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 2 (Workspace Inventory): wires the Phase 1 WorkspaceInventoryScanner
 * (read-only — see its own docblock for the exact git commands it's
 * limited to) into the agent's poll loop.
 *
 * Scans only projects the Hub has actually synced with authoritative
 * project_id/mapping_id — never a legacy config.php entry, never a
 * project the Hub hasn't assigned this agent (see poll()'s own skip
 * condition). A project the Hub has detached (Sandogh, currently) simply
 * never appears in the synced $projects array this class is handed, so
 * it is never scanned — no special-case exclusion needed here.
 *
 * Reports at most once per $refreshIntervalSeconds unless the scanned
 * snapshot_hash changed since the last successful report, so an
 * unchanged workspace is never spammed every poll cycle. A scan or
 * report failure for one project is logged and skipped — it never stops
 * another project's cycle, and never propagates out of poll() to crash
 * the caller's own main loop.
 */
final class WorkspaceInventoryReporter
{
    public function __construct(
        private readonly WorkspaceInventoryScanner $scanner,
        private readonly Client $client,
        private readonly Logger $logger,
        private readonly int $refreshIntervalSeconds = 60,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $projects synced project
     *     config, keyed by project_key — exactly agent.php's own $projects.
     * @param array<string, array{hash: string, at: int}> $lastReported
     *     by-ref state, keyed by project_key — caller owns the array's
     *     lifetime for the life of the process, same convention as
     *     agent.php's own $lastReportedChanges for change detection.
     */
    public function poll(array $projects, array &$lastReported): void
    {
        $now = time();

        foreach ($projects as $projectKey => $projectConfig) {
            $projectId = $projectConfig['project_id'] ?? null;
            $mappingId = $projectConfig['mapping_id'] ?? null;
            if (!is_int($projectId) || !is_int($mappingId)) {
                // Legacy config.php entry, or a cache saved before the Hub
                // started issuing these ids — cannot report inventory
                // without an authoritative mapping/project id, and this
                // never invents one locally.
                continue;
            }

            $repositoryPath = $projectConfig['source_workspace_path'] ?? $projectConfig['local_path'] ?? '';

            try {
                $inventory = $this->scanner->scan($repositoryPath);
            } catch (\Throwable $e) {
                // A bad mapping (wrong path, wrong Git top-level, symlink
                // root, production-like path, ...) — WorkspaceInventoryScanner
                // itself refuses to run any git command in that case.
                // Nothing meaningful to report for this cycle; logged,
                // never fatal, retried automatically next cycle.
                $this->logger->warn("Workspace inventory: could not scan '{$projectKey}' at {$repositoryPath}: " . $e->getMessage());
                continue;
            }

            $state = $lastReported[$projectKey] ?? null;
            $hashChanged = $state === null || $state['hash'] !== $inventory['snapshot_hash'];
            $intervalElapsed = $state === null || ($now - $state['at']) >= $this->refreshIntervalSeconds;

            if (!$hashChanged && !$intervalElapsed) {
                continue;
            }

            $payload = $inventory;
            $payload['project_id'] = $projectId;
            $payload['mapping_id'] = $mappingId;
            // WorkspaceInventoryScanner deliberately never timestamps its
            // own result (see its class docblock) — this reporting layer
            // is "when it was scanned" as far as the Hub is concerned,
            // captured immediately after scan() returns.
            $payload['scanned_at'] = gmdate('c');

            try {
                $response = $this->client->request('POST', '/api/local-agent/workspace-inventory', $payload);
            } catch (\Throwable $e) {
                $this->logger->warn("Workspace inventory: reporting '{$projectKey}' failed: " . $e->getMessage());
                continue; // retried next cycle — $lastReported left untouched
            }

            if ($response['status'] !== 200) {
                $this->logger->warn("Workspace inventory: reporting '{$projectKey}' returned HTTP {$response['status']}.");
                continue; // retried next cycle
            }

            $lastReported[$projectKey] = ['hash' => $inventory['snapshot_hash'], 'at' => $now];
            $this->logger->info(
                "Workspace inventory: reported '{$projectKey}' (hub_status=" . ($response['body']['status'] ?? 'unknown')
                . ", changed={$inventory['total_changed_count']}, clean=" . ($inventory['is_clean'] ? 'yes' : 'no')
                . ($inventory['scan_error'] !== null ? ", scan_error={$inventory['scan_error']}" : '') . ')'
            );
        }
    }
}
