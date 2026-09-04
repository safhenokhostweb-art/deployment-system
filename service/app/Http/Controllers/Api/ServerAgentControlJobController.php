<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ServerAgentControlJob;
use App\Services\Publish\ServerAgentControlJobService;
use Illuminate\Http\Request;

/**
 * Sits behind 'agent.signed', same signed channel as every other Server
 * Agent endpoint — no new protocol, no free-form command ever crosses
 * this contract (see ServerAgentControlJobService's own docblock). Only
 * the narrow upgrade action exists today.
 */
class ServerAgentControlJobController extends Controller
{
    public function __construct(
        private readonly ServerAgentControlJobService $controlJobs,
    ) {
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_agent');
        abort_unless($agent instanceof Agent, 403, 'Polling for a control job requires an authenticated Server Agent.');

        $job = $this->controlJobs->claimNext($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'action' => $job->action,
                'target_version' => $job->target_version,
                'target_commit_sha' => $job->target_commit_sha,
                'target_branch' => $job->target_branch,
                'repository_mapping' => $job->repository_mapping,
                'package_id' => $job->serverAgentPackage?->public_id,
                'expected_package_hash' => $job->expected_package_hash,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_agent');
        abort_unless($agent instanceof Agent, 403, 'Reporting a control job requires an authenticated Server Agent.');

        $job = ServerAgentControlJob::where('public_id', $publicId)->firstOrFail();
        abort_unless($job->agent_id === $agent->id, 403, 'This control job does not belong to the authenticated Server Agent.');

        $validated = $request->validate([
            'status' => 'required|string|in:started,failed',
            'output' => 'nullable|string|max:100000',
            'error_message' => 'nullable|string|max:2000',
            'source_commit' => 'nullable|string|size:40|regex:/^[0-9a-f]{40}$/',
            'package_hash' => 'nullable|string|size:64|regex:/^[0-9a-f]{64}$/',
        ]);

        if ($validated['status'] === 'started') {
            $updated = $this->controlJobs->reportUpgradeStarted(
                $job,
                $validated['output'] ?? 'Upgrade validation passed; activation is starting.',
                $validated['source_commit'] ?? null,
                $validated['package_hash'] ?? null,
            );
        } else {
            $updated = $this->controlJobs->reportFailure($job, $validated['error_message'] ?? 'Unknown Server Agent control job failure.');
        }

        return response()->json(['status' => $updated->status]);
    }
}
