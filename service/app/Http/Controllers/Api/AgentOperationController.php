<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\OperationJob;
use App\Services\Operations\OperationJobService;
use App\Support\Operations\OperationRegistry;
use Illuminate\Http\Request;

/**
 * Sits behind the same 'agent.signed' middleware as AgentJobController/
 * AgentDiscoveryController — no new protocol, no new auth scheme (Task 5).
 * Only reachable via the "New Agent" identity path, mirroring every other
 * new-domain capability added since Phase 3-C.2 — a legacy Server-only
 * credential polling here just gets the same quiet {"job": null} shape it
 * already knows how to ignore.
 */
class AgentOperationController extends Controller
{
    public function __construct(
        private readonly OperationJobService $operations,
    ) {
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_agent');

        if (!$agent instanceof Agent) {
            return response()->json(['job' => null]);
        }

        $job = $this->operations->claimNext($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                // Only ever a registry key — never a raw command (Task 4/12).
                'operation_type' => $job->operation_type,
                'project_key' => $job->project?->project_key,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_agent');

        abort_unless($agent instanceof Agent, 403, 'Operation reporting requires an authenticated Agent.');

        $job = OperationJob::where('public_id', $publicId)->firstOrFail();

        abort_unless($job->agent_id === $agent->id, 403, 'This operation job does not belong to the authenticated agent.');

        $validated = $request->validate([
            'status' => 'required|string|in:succeeded,failed',
            'output' => 'nullable|string|max:100000',
            'error_message' => 'nullable|string|max:2000',
        ]);

        // Defense in depth, mirroring OperationJobService::queue()'s own
        // check — an agent report can never resurrect a job whose type
        // somehow isn't in the registry (it shouldn't exist at all, since
        // queue() already validated it, but this is the same "validate
        // again independently" posture as the Agent's own execution guard).
        abort_unless(OperationRegistry::isAllowed($job->operation_type), 422, 'Unknown operation type.');

        if ($validated['status'] === 'failed') {
            $updated = $this->operations->reportFailure($job, $validated['error_message'] ?? 'Unknown agent-side operation failure.', $validated['output'] ?? null);
        } else {
            $updated = $this->operations->reportResult($job, $validated['output'] ?? '');
        }

        return response()->json(['status' => $updated->status]);
    }
}
