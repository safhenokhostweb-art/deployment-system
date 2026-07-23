<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\DiscoveryJob;
use App\Services\Discovery\DiscoveryJobService;
use Illuminate\Http\Request;

/**
 * Sits behind the same 'agent.signed' middleware as AgentJobController —
 * no new protocol, no new auth scheme (docs/PHASE_LOCAL_DISCOVERY_ZERO_CONFIG_AUDIT.md
 * §3). Only reachable via the "New Agent" identity path: a legacy
 * Server-only credential has no Agent row, and Discovery has no meaning
 * without one (mirrors AgentTaskService's Agent-only precedent). A
 * legacy-only caller polling this endpoint (which today's unmodified
 * agent.php never does) just gets a quiet {"job": null}, identical to
 * the shape it already knows how to ignore.
 */
class AgentDiscoveryController extends Controller
{
    public function __construct(
        private readonly DiscoveryJobService $discoveryJobs,
    ) {
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_agent');

        if (!$agent instanceof Agent) {
            return response()->json(['job' => null]);
        }

        $job = $this->discoveryJobs->claimNext($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'workspace_path' => $job->workspace_path,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_agent');

        abort_unless($agent instanceof Agent, 403, 'Discovery reporting requires an authenticated Agent.');

        $job = DiscoveryJob::where('public_id', $publicId)->firstOrFail();

        abort_unless($job->agent_id === $agent->id, 403, 'This discovery job does not belong to the authenticated agent.');

        $validated = $request->validate([
            'status' => 'required|string|in:succeeded,failed',
            'projects' => 'nullable|array',
            'projects.*.name' => 'nullable|string|max:255',
            'projects.*.path' => 'nullable|string|max:1000',
            'projects.*.type' => 'nullable|string|max:50',
            'projects.*.framework_version' => 'nullable|string|max:100',
            'projects.*.git' => 'nullable|array',
            'projects.*.git.remote_url' => 'nullable|string|max:1000',
            'projects.*.git.provider' => 'nullable|string|max:100',
            'projects.*.git.repository' => 'nullable|string|max:255',
            'projects.*.git.branch' => 'nullable|string|max:255',
            'projects.*.git.commit_hash' => 'nullable|string|max:64',
            'error_message' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            $updated = $this->discoveryJobs->reportFailure($job, $validated['error_message'] ?? 'Unknown agent-side discovery failure.');
        } else {
            $updated = $this->discoveryJobs->reportResult($job, $validated['projects'] ?? []);
        }

        return response()->json(['status' => $updated->status]);
    }
}
