<?php

namespace App\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (Workspace Inventory): persists WorkspaceInventoryScanner's
 * report onto its owning LocalProjectMapping as the latest-known snapshot
 * — no history table, see the migration's own docblock. Ownership
 * (mapping belongs to the authenticated agent, project_id matches the
 * mapping, project is active), cross-field consistency, and stale/
 * out-of-order protection all live here rather than the controller,
 * matching LocalChangeDetectionService's role for the sibling
 * change-detection report.
 */
class WorkspaceInventoryReportService
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_IGNORED_STALE = 'ignored_stale';

    /**
     * @param array<string, mixed> $validated already passed
     *     LocalAgentWorkspaceInventoryController's declarative rule
     *     validation — this method owns ownership, cross-field
     *     consistency, stale-report semantics, and persistence.
     * @return array{status: string, mapping_id: int}
     */
    public function report(LocalAgent $agent, array $validated): array
    {
        $mapping = LocalProjectMapping::where('id', $validated['mapping_id'])
            ->where('local_agent_id', $agent->id)
            ->first();

        abort_if($mapping === null, 403, 'This workspace mapping does not belong to the authenticated Local Agent.');

        abort_unless((int) $mapping->project_id === (int) $validated['project_id'], 403, 'project_id does not match the reported mapping_id.');

        abort_unless($mapping->project !== null && $mapping->project->is_active, 422, 'This project is not active and cannot accept workspace inventory reports.');

        $this->assertConsistent($validated);

        $newScannedAt = Carbon::parse($validated['scanned_at']);
        $files = $this->normalizeFiles($validated['files']);

        return DB::transaction(function () use ($mapping, $validated, $newScannedAt, $files) {
            // lockForUpdate() makes the read-compare-write below atomic
            // against a concurrent report for the same mapping — required
            // so "reject the older of two racing reports" can't itself
            // race.
            /** @var LocalProjectMapping $locked */
            $locked = LocalProjectMapping::where('id', $mapping->id)->lockForUpdate()->firstOrFail();

            $storedScannedAt = $locked->workspace_inventory_scanned_at;

            if ($storedScannedAt !== null) {
                if ($newScannedAt->lt($storedScannedAt)) {
                    // Delayed network delivery of an older report — never
                    // rolls the dashboard backward. Not a client error: the
                    // agent did nothing wrong, the report is simply
                    // obsolete by the time it arrived.
                    return ['status' => self::STATUS_IGNORED_STALE, 'mapping_id' => $locked->id];
                }

                if ($newScannedAt->eq($storedScannedAt)) {
                    if ($locked->workspace_inventory_snapshot_hash === $validated['snapshot_hash']) {
                        // Identical evidence re-delivered (e.g. a retried
                        // request after a lost response) — idempotently
                        // accepted, no mutation.
                        return ['status' => self::STATUS_ACCEPTED, 'mapping_id' => $locked->id];
                    }

                    abort(422, 'Workspace inventory report has the same scanned_at as the stored snapshot but a different snapshot_hash — inconsistent evidence rejected.');
                }
            }

            $locked->update([
                'workspace_inventory_schema_version' => $validated['schema_version'],
                'workspace_inventory_scanned_at' => $newScannedAt,
                'workspace_inventory_received_at' => now(),
                'workspace_inventory_snapshot_hash' => $validated['snapshot_hash'],
                'workspace_branch' => $validated['branch'] ?? null,
                'workspace_head_sha' => $validated['head_sha'] ?? null,
                'workspace_upstream_ref' => $validated['upstream_ref'] ?? null,
                'workspace_upstream_sha' => $validated['upstream_sha'] ?? null,
                'workspace_comparison_basis' => $validated['comparison_basis'],
                'workspace_ahead_count' => $validated['ahead_count'] ?? null,
                'workspace_behind_count' => $validated['behind_count'] ?? null,
                'workspace_tracked_modified_count' => $validated['tracked_modified_count'],
                'workspace_untracked_count' => $validated['untracked_count'],
                'workspace_staged_count' => $validated['staged_count'],
                'workspace_deleted_count' => $validated['deleted_count'],
                'workspace_renamed_count' => $validated['renamed_count'],
                'workspace_conflicted_count' => $validated['conflicted_count'],
                'workspace_total_changed_count' => $validated['total_changed_count'],
                'workspace_is_clean' => $validated['is_clean'],
                'workspace_is_truncated' => $validated['is_truncated'],
                'workspace_omitted_file_count' => $validated['omitted_file_count'],
                'workspace_scan_error' => $validated['scan_error'] ?? null,
                'workspace_inventory_files' => $files,
            ]);

            return ['status' => self::STATUS_ACCEPTED, 'mapping_id' => $locked->id];
        });
    }

    /**
     * Cross-field invariants WorkspaceInventoryScanner itself guarantees by
     * construction on the agent side (see its own docblocks, especially
     * buildInventory()) — re-verified here so a malformed or tampered
     * report can never be silently trusted. Every check below holds for
     * every real snapshot the scanner can produce, including its own
     * degraded/error and byte-cap-truncated states — this is not stricter
     * than Phase 1's own contract, just enforcing it server-side too.
     *
     * @param array<string, mixed> $validated
     */
    private function assertConsistent(array $validated): void
    {
        $total = $validated['total_changed_count'];
        $filesCount = count($validated['files']);

        abort_if($filesCount > $total, 422, 'files contains more entries than total_changed_count.');
        abort_unless($filesCount + $validated['omitted_file_count'] === $total, 422, 'total_changed_count does not equal count(files) + omitted_file_count.');
        abort_unless($validated['tracked_modified_count'] + $validated['untracked_count'] === $total, 422, 'tracked_modified_count + untracked_count does not equal total_changed_count.');

        foreach (['deleted_count', 'renamed_count', 'conflicted_count', 'staged_count'] as $category) {
            abort_if($validated[$category] > $total, 422, "{$category} exceeds total_changed_count.");
        }

        if ($validated['is_clean']) {
            abort_unless($total === 0 && $validated['conflicted_count'] === 0, 422, 'is_clean is true but total_changed_count or conflicted_count is non-zero.');
        }

        if ($validated['comparison_basis'] === 'unavailable') {
            abort_unless(($validated['ahead_count'] ?? null) === null && ($validated['behind_count'] ?? null) === null, 422, "comparison_basis is 'unavailable' but ahead_count/behind_count are not both null.");
        } else {
            abort_unless(($validated['ahead_count'] ?? null) !== null && ($validated['behind_count'] ?? null) !== null, 422, "comparison_basis is 'upstream_tracking' but ahead_count/behind_count are null.");
        }

        if (!$validated['is_truncated']) {
            abort_unless($validated['omitted_file_count'] === 0, 422, 'is_truncated is false but omitted_file_count is non-zero.');
        }

        if (($validated['scan_error'] ?? null) !== null) {
            abort_if($validated['is_clean'], 422, 'scan_error is set but is_clean is true.');
        }
    }

    /**
     * Rebuilds every file entry field-by-field from already-validated
     * data — never persists the request's raw array directly — so an
     * unlisted key (file contents, a patch, a diff, arbitrary metadata)
     * can never reach storage even though Laravel's validate() would
     * otherwise silently drop rather than reject it.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFiles(array $files): array
    {
        return array_map(static fn (array $file) => [
            'path' => $file['path'],
            'old_path' => $file['old_path'] ?? null,
            'status' => $file['status'],
            'index_status' => $file['index_status'] ?? null,
            'worktree_status' => $file['worktree_status'] ?? null,
            'staged' => $file['staged'],
            'tracked' => $file['tracked'],
            'ownership' => $file['ownership'],
        ], $files);
    }
}
