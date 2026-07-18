<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\AgentJobService;
use Illuminate\Http\Request;

/**
 * All routes here sit behind VerifyAgentRequestSignature. The authenticated
 * Server is available via $request->attributes->get('deployment_agent_server').
 */
class AgentJobController extends Controller
{
    public function __construct(
        private readonly AgentJobService $jobs,
    ) {
    }

    /**
     * Distinct from job polling so version/liveness reporting keeps working
     * even on a cycle where there's nothing to deploy, and so it can be
     * called on its own cadence later without touching job-claim logic.
     */
    public function heartbeat(Request $request)
    {
        $server = $this->authenticatedServer($request);

        $validated = $request->validate([
            'agent_version' => 'required|string|max:50',
        ]);

        $server->forceFill(['agent_version' => $validated['agent_version']])->save();

        return response()->json(['status' => 'ok']);
    }

    public function next(Request $request)
    {
        $server = $this->authenticatedServer($request);

        $claimed = $this->jobs->claimNextJob($server);

        if ($claimed === null) {
            return response()->json(['job' => null]);
        }

        return response()->json($claimed);
    }

    public function updateStatus(Request $request, Deployment $deployment)
    {
        $server = $this->authenticatedServer($request);
        $this->authorizeServerOwnsDeployment($server, $deployment);

        $validated = $request->validate([
            'status' => 'required|string|in:running,succeeded,failed',
            'message' => 'nullable|string|max:5000',
        ]);

        $updated = $this->jobs->updateStatus($deployment, $validated['status'], $validated['message'] ?? null);

        return response()->json(['status' => $updated->status]);
    }

    public function appendLog(Request $request, Deployment $deployment)
    {
        $server = $this->authenticatedServer($request);
        $this->authorizeServerOwnsDeployment($server, $deployment);

        $validated = $request->validate([
            'sequence' => 'required|integer|min:1',
            'step_name' => 'required|string|max:255',
            'command' => 'required|string',
            'output' => 'nullable|string',
            'exit_code' => 'nullable|integer',
            'duration_ms' => 'nullable|integer|min:0',
            'timed_out' => 'nullable|boolean',
        ]);

        $entry = $this->jobs->recordLog($deployment, $validated);

        return response()->json(['id' => $entry->id], 201);
    }

    private function authenticatedServer(Request $request): Server
    {
        return $request->attributes->get('deployment_agent_server');
    }

    private function authorizeServerOwnsDeployment(Server $server, Deployment $deployment): void
    {
        abort_unless($deployment->server_id === $server->id, 403, 'This deployment does not belong to the authenticated server.');
    }
}
