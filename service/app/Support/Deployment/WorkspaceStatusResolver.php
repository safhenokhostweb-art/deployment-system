<?php

namespace App\Support\Deployment;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;

/**
 * Phase 2 (Workspace Inventory): derives a single, human-facing status
 * from a LocalProjectMapping's latest-reported WorkspaceInventoryScanner
 * snapshot plus its owning Local Agent's online state. Computed live on
 * every call, never persisted — agent online/offline state changes
 * independently of when inventory was last reported, so a stored value
 * would go stale in a way this class exists specifically to avoid.
 *
 * Deliberately conservative, matching every constraint the scanner and
 * report pipeline already enforce:
 *  - never infers CLEAN/readiness from an absent or stale report (see
 *    UNMAPPED/AGENT_OFFLINE/STALE below — all checked before anything
 *    that could look like "clean");
 *  - never fabricates ahead/behind when comparison_basis is
 *    'unavailable' — DIVERGED/MIXED/LOCAL_COMMITS_AHEAD/BEHIND_REMOTE
 *    are only ever reachable when the Agent itself reported a known
 *    upstream comparison;
 *  - never touches file-level `ownership` (still hardcoded "unknown" at
 *    the scanner) — this class only ever describes observable Git
 *    state, never "ready to commit"/"ready to publish".
 *
 * Priority order (first match wins) is a deliberate design choice, not
 * an accident of code order: identity/connectivity problems (no
 * mapping, agent offline, no/stale data) are reported before anything
 * about the repository's own content, and CONFLICTED (an unresolved
 * merge, or the scan itself failing) outranks every other repository
 * state since it represents something actively broken.
 */
final class WorkspaceStatusResolver
{
    public const UNMAPPED = 'unmapped';
    public const AGENT_OFFLINE = 'agent_offline';
    public const STALE = 'stale';
    public const CONFLICTED = 'conflicted';
    public const DIVERGED = 'diverged';
    public const MIXED = 'mixed';
    public const DIRTY = 'dirty';
    public const LOCAL_COMMITS_AHEAD = 'local_commits_ahead';
    public const BEHIND_REMOTE = 'behind_remote';
    public const CLEAN = 'clean';

    /**
     * How long a received inventory report stays "fresh" before falling
     * back to STALE — several multiples of the ~60s reporting cadence
     * (WorkspaceInventoryReporter's default interval on the agent side)
     * to absorb ordinary poll/network jitter, not a tight SLA.
     */
    private const FRESHNESS_SECONDS = 300;

    public static function resolve(?LocalProjectMapping $mapping): string
    {
        if ($mapping === null) {
            return self::UNMAPPED;
        }

        $agent = $mapping->localAgent;
        if (!($agent instanceof LocalAgent) || !$agent->isOnline()) {
            return self::AGENT_OFFLINE;
        }

        if (!$mapping->hasReportedWorkspaceInventory()) {
            return self::STALE;
        }

        $receivedAt = $mapping->workspace_inventory_received_at;
        if ($receivedAt === null || $receivedAt->lt(now()->subSeconds(self::FRESHNESS_SECONDS))) {
            return self::STALE;
        }

        if ($mapping->workspace_scan_error !== null || (int) $mapping->workspace_conflicted_count > 0) {
            return self::CONFLICTED;
        }

        $ahead = $mapping->workspace_ahead_count;
        $behind = $mapping->workspace_behind_count;
        $hasKnownComparison = $mapping->workspace_comparison_basis === 'upstream_tracking' && $ahead !== null && $behind !== null;

        $isDiverged = $hasKnownComparison && $ahead > 0 && $behind > 0;
        $isDirty = $mapping->workspace_total_changed_count !== null && (int) $mapping->workspace_total_changed_count > 0;

        if ($isDiverged && $isDirty) {
            return self::MIXED;
        }
        if ($isDiverged) {
            return self::DIVERGED;
        }
        if ($isDirty) {
            return self::DIRTY;
        }
        if ($hasKnownComparison && $ahead > 0 && $behind === 0) {
            return self::LOCAL_COMMITS_AHEAD;
        }
        if ($hasKnownComparison && $behind > 0 && $ahead === 0) {
            return self::BEHIND_REMOTE;
        }

        return self::CLEAN;
    }
}
