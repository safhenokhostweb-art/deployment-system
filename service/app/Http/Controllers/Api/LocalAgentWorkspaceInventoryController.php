<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Services\Publish\WorkspaceInventoryReportService;
use Illuminate\Http\Request;

/**
 * Phase 2 (Workspace Inventory): sits behind 'local-agent.signed', same
 * channel and identity resolution as every other Local Agent endpoint —
 * no new authentication path. Reports WorkspaceInventoryScanner's
 * read-only Git facts snapshot for one synchronized project mapping
 * (local-agent/lib/WorkspaceInventoryScanner.php) — never file contents,
 * diffs, or patches. Dashboard/UI is Phase 3; this is ingest + latest-state
 * persistence only (see WorkspaceInventoryReportService and the
 * 2026_08_28_090000 migration).
 */
class LocalAgentWorkspaceInventoryController extends Controller
{
    /** Mirrors GitStatusPorcelainV2Parser's own status vocabulary exactly — see its statusLabel()/parseUnmerged(). */
    private const FILE_STATUSES = ['modified', 'added', 'deleted', 'renamed', 'copied', 'typechange', 'untracked', 'conflicted'];

    /**
     * Generous over the theoretical worst case (2000 files x ~1000-char
     * path x a handful of short fields each) but still a hard, finite
     * ceiling — payload size enforcement at the request boundary, ahead of
     * (and independent from) the per-field limits below.
     */
    private const MAX_BODY_BYTES = 6_000_000;

    public function __construct(
        private readonly WorkspaceInventoryReportService $inventory,
    ) {
    }

    public function report(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Reporting workspace inventory requires an authenticated Local Agent.');

        abort_if(strlen($request->getContent()) > self::MAX_BODY_BYTES, 413, 'Workspace inventory payload exceeds the maximum accepted size.');

        $validated = $request->validate([
            'schema_version' => 'required|integer|in:1',
            // Authoritative identifiers this agent received from
            // GET /api/local-agent/projects, never invented locally — the
            // authenticated agent identity above is what actually decides
            // ownership (see WorkspaceInventoryReportService::report());
            // these are only ever used to look up which mapping row, never
            // trusted as proof of ownership by themselves.
            'project_id' => 'required|integer|min:1',
            'mapping_id' => 'required|integer|min:1',
            'scanned_at' => 'required|date',

            'branch' => 'nullable|string|max:255',
            'head_sha' => 'nullable|string|regex:/^[0-9a-f]{40}$/',
            'upstream_ref' => 'nullable|string|max:255',
            'upstream_sha' => 'nullable|string|regex:/^[0-9a-f]{40}$/',
            'comparison_basis' => 'required|string|in:upstream_tracking,unavailable',
            'ahead_count' => 'nullable|integer|min:0',
            'behind_count' => 'nullable|integer|min:0',

            'tracked_modified_count' => 'required|integer|min:0',
            'untracked_count' => 'required|integer|min:0',
            'staged_count' => 'required|integer|min:0',
            'deleted_count' => 'required|integer|min:0',
            'renamed_count' => 'required|integer|min:0',
            'conflicted_count' => 'required|integer|min:0',
            'total_changed_count' => 'required|integer|min:0',

            'is_clean' => 'required|boolean',
            'is_truncated' => 'required|boolean',
            'omitted_file_count' => 'required|integer|min:0',
            'scan_error' => 'nullable|string|max:2000',
            'snapshot_hash' => 'required|string|regex:/^[0-9a-f]{64}$/',

            // Never file contents/patches/diffs — only the Phase 1
            // inventory fields. An unlisted key on any file object is
            // simply not collected by validate(); WorkspaceInventoryReportService
            // additionally rebuilds every entry field-by-field before
            // persisting, so nothing outside this list can ever reach
            // storage even if it were present in the raw request body.
            // 'present', not 'required': Laravel's required rule treats an
            // empty array as "missing", but a clean/truncated-to-nothing
            // snapshot legitimately has zero file entries — same
            // present|array convention LocalAgentChangeDetectionController
            // already uses for changed_files.
            'files' => 'present|array|max:2000',
            'files.*.path' => 'required|string|max:1000',
            'files.*.old_path' => 'nullable|string|max:1000',
            'files.*.status' => 'required|string|in:' . implode(',', self::FILE_STATUSES),
            'files.*.index_status' => 'nullable|string|regex:/^[.MTADRCU]$/',
            'files.*.worktree_status' => 'nullable|string|regex:/^[.MTADRCU]$/',
            'files.*.staged' => 'required|boolean',
            'files.*.tracked' => 'required|boolean',
            // Phase 2 hard requirement: ownership inference does not exist
            // yet — the only value ever accepted is the literal "unknown"
            // WorkspaceInventoryScanner itself hardcodes on every file.
            'files.*.ownership' => 'required|string|in:unknown',
        ]);

        $result = $this->inventory->report($agent, $validated);

        return response()->json($result);
    }
}
