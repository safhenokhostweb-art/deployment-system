<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Services\Publish\LocalChangeDetectionService;
use Illuminate\Http\Request;

/**
 * Phase 5.2: sits behind 'local-agent.signed', same channel as every
 * other Local Agent endpoint — no new protocol. The agent's watcher
 * calls this every poll cycle it detects a (filtered) non-empty,
 * changed file list for a mapped project; see agent.php's
 * pollAndDetectChanges().
 */
class LocalAgentChangeDetectionController extends Controller
{
    public function __construct(
        private readonly LocalChangeDetectionService $changeDetection,
    ) {
    }

    public function report(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Reporting a change detection requires an authenticated Local Agent.');

        $validated = $request->validate([
            'project_key' => 'required|string|max:255',
            'changed_files' => 'present|array',
            'changed_files.*' => 'string|max:1000',
        ]);

        $project = Project::where('project_key', $validated['project_key'])->first();

        abort_unless($project instanceof Project, 422, "Unknown project_key '{$validated['project_key']}'.");

        $job = $this->changeDetection->report($agent, $project, $validated['changed_files']);

        return response()->json(['status' => $job->status]);
    }
}
