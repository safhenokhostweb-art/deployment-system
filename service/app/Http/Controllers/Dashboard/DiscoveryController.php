<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Server;
use App\Services\Discovery\DiscoveryJobService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-H Task 8: triggers a Local Discovery scan on a Server's Agent.
 * Gated the same as project creation ('projects.create') — Discovery
 * exists only to feed that wizard, so whoever can create a Project can
 * also ask an Agent to scan a workspace for one.
 */
class DiscoveryController extends Controller
{
    public function __construct(
        private readonly DiscoveryJobService $discoveryJobs,
    ) {
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('projects.create');

        $agent = $server->agents()->where('status', Agent::STATUS_APPROVED)->first();
        abort_if($agent === null, 422, 'This server needs an approved Agent before a workspace scan can run.');

        $validated = $request->validate([
            'workspace_path' => 'required|string|max:1000',
        ]);

        $this->discoveryJobs->queue($agent, $validated['workspace_path'], $request->user()->email);

        return redirect()->route('servers.show', $server)
            ->with('status', __('messages.flash.discovery_scan_queued'));
    }
}
