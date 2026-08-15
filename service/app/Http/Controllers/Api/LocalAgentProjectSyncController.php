<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use Illuminate\Http\Request;

/**
 * The Hub's single source of truth for which projects a Local Agent should
 * manage locally — replaces the agent's own manually-maintained config.php
 * `projects` array. Reuses the exact LocalProjectMapping rows the
 * dashboard's LocalProjectMappingController already writes (Phase 5); no
 * parallel project store, no duplicated SSH/branch config. See
 * deployment-local-agent/agent/lib/ProjectConfigCache.php for how the
 * agent caches this response and falls back when the Hub is unreachable.
 */
class LocalAgentProjectSyncController extends Controller
{
    public function index(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Project sync requires an authenticated Local Agent.');

        $projects = $agent->projectMappings()
            ->with('project')
            // A disabled Project should stop being published to even though
            // the mapping row itself still exists — never surfaced to the
            // agent as something to keep publishing into.
            ->whereHas('project', fn ($query) => $query->where('is_active', true))
            ->get()
            ->map(fn ($mapping) => [
                'project_key' => $mapping->project->project_key,
                'local_path' => $mapping->local_path,
                'remote_name' => $mapping->remote_name,
                'branch' => $mapping->effectiveBranch(),
                'application_root' => $mapping->project->application_root ?? '.',
            ])
            ->values();

        return response()->json([
            'projects' => $projects,
            'config_version' => $agent->projectConfigVersion(),
        ]);
    }
}
