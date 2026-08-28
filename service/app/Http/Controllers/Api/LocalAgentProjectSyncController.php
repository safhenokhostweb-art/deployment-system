<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Support\Deployment\ArtifactDependencyPolicy;
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
                // Phase 2 (Workspace Inventory): authoritative, Hub-issued
                // numeric identifiers the agent round-trips on its inventory
                // report so the Hub never has to trust a client-supplied id
                // — see LocalAgentWorkspaceInventoryController, which
                // re-verifies both belong to the authenticated agent on
                // every report regardless.
                'project_id' => $mapping->project_id,
                'mapping_id' => $mapping->id,
                'source_workspace_path' => $mapping->sourceWorkspacePath(),
                'repository_url' => 'https://github.com/' . $mapping->project->repository . '.git',
                'remote_name' => $mapping->remote_name,
                'branch' => $mapping->effectiveBranch(),
                'application_root' => $mapping->project->application_root ?? '.',
                'requires_complete_artifact' => (bool) $mapping->project->requires_complete_artifact,
                'dependency_pinning_required' => ArtifactDependencyPolicy::required($mapping->project->project_key),
                'dependency_allowlist' => ArtifactDependencyPolicy::allowlist($mapping->project->project_key),
            ])
            ->values();

        return response()->json([
            'projects' => $projects,
            'config_version' => $agent->projectConfigVersion(),
        ]);
    }
}
