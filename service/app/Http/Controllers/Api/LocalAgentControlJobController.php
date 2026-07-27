<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Services\Publish\LocalAgentControlJobService;
use Illuminate\Http\Request;

/**
 * Phase 5.3: sits behind 'local-agent.signed', same signed channel as
 * every other Local Agent endpoint — no new protocol. Mirrors
 * AgentOperationController's next()/report() shape exactly.
 */
class LocalAgentControlJobController extends Controller
{
    public function __construct(
        private readonly LocalAgentControlJobService $controlJobs,
    ) {
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Polling for a control job requires an authenticated Local Agent.');

        $job = $this->controlJobs->claimNext($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'action' => $job->action,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Reporting a control job requires an authenticated Local Agent.');

        $job = LocalAgentControlJob::where('public_id', $publicId)->firstOrFail();

        abort_unless($job->local_agent_id === $agent->id, 403, 'This control job does not belong to the authenticated Local Agent.');

        $validated = $request->validate([
            'status' => 'required|string|in:succeeded,failed',
            'output' => 'nullable|string|max:100000',
            'error_message' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            $updated = $this->controlJobs->reportFailure($job, $validated['error_message'] ?? 'Unknown agent-side control job failure.');
        } else {
            $updated = $this->controlJobs->reportResult($job, $validated['output'] ?? '');
        }

        return response()->json(['status' => $updated->status]);
    }
}
