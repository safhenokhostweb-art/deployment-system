<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-L Task 4: a single, read-only "are we actually ready to
 * deploy" page — every value here is derived from data that already
 * exists (GitHubAccount/Server/Agent/Project), nothing new is computed
 * or stored. Visible to the same audience as the Dashboard
 * (projects.view — every role from Viewer up), since "is this ready"
 * is exactly the kind of thing a Viewer should be able to check without
 * needing management permissions on any of the underlying resources.
 */
class ReadinessController extends Controller
{
    public function index()
    {
        Gate::authorize('projects.view');

        $githubConnected = GitHubAccount::where('status', GitHubAccount::STATUS_CONNECTED)->exists();

        $servers = Server::with('agents')->get()->map(function (Server $server) {
            $approvedAgent = $server->agents->firstWhere('status', Agent::STATUS_APPROVED);
            $pendingAgent = $server->agents->firstWhere('status', Agent::STATUS_PENDING_APPROVAL);

            return [
                'server' => $server,
                'online' => $server->isOnline(),
                'agent_status' => $approvedAgent ? 'approved' : ($pendingAgent ? 'pending' : null),
            ];
        });

        $projects = Project::with('server')->get()->map(function (Project $project) {
            // "Configured" — the project itself has everything it needs,
            // independent of whether the target server currently happens
            // to be reachable.
            $configured = $project->is_active
                && !empty($project->repository)
                && !empty($project->branch)
                && !empty($project->pipeline);

            // "Ready to actually deploy right now" — configured, plus the
            // target server is active and has an agent currently polling.
            $serverReady = $project->server !== null && $project->server->is_active && $project->server->isOnline();

            return [
                'project' => $project,
                'configured' => $configured,
                'deployment_ready' => $configured && $serverReady,
            ];
        });

        return view('readiness.index', compact('githubConnected', 'servers', 'projects'));
    }
}
