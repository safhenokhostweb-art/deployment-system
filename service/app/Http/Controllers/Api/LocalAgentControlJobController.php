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
                'expected_version' => $job->expected_version,
                'expected_commit_hash' => $job->expected_commit_hash,
                'expected_branch' => $job->expected_branch,
                'repository_mapping' => $job->repository_mapping,
            ],
        ]);
    }

    /**
     * Root-cause fix (Watchdog, section 9): a distinct, parameter-free
     * endpoint (never a query string — the signed-request scheme signs
     * the path only, exactly as Laravel's own $request->path() reports
     * it, so a query string here would silently break signature
     * verification) polled by the independent heartbeat-watchdog.php
     * process instead of next() above. Restricted to `restart` only —
     * see LocalAgentControlJobService::claimNext()'s own docblock for why
     * the watchdog must never be able to claim a fetch_logs/reload_config
     * job it has no idea how to execute.
     */
    public function nextRestartOnly(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Polling for a control job requires an authenticated Local Agent.');

        $job = $this->controlJobs->claimNext($agent, [LocalAgentControlJob::ACTION_RESTART]);

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
            'status' => 'required|string|in:started,succeeded,failed',
            'output' => 'nullable|string|max:100000',
            'error_message' => 'nullable|string|max:2000',
            'source_commit' => 'nullable|string|size:40|regex:/^[0-9a-f]{40}$/',
            'package_tree_hash' => 'nullable|string|size:40|regex:/^[0-9a-f]{40}$/',
            'repository_mapping' => 'nullable|string|max:100|regex:/^[A-Za-z0-9._-]+$/',
        ]);

        if ($validated['status'] === 'started') {
            abort_unless($job->action === LocalAgentControlJob::ACTION_UPGRADE, 422, 'Only an upgrade job may report started.');
            $updated = $this->controlJobs->reportUpgradeStarted(
                $job,
                $validated['output'] ?? 'Upgrade validation passed; activation is starting.',
                $validated['source_commit'] ?? null,
                $validated['package_tree_hash'] ?? null,
                $validated['repository_mapping'] ?? null,
            );
        } elseif ($validated['status'] === 'failed') {
            $updated = $this->controlJobs->reportFailure($job, $validated['error_message'] ?? 'Unknown agent-side control job failure.');
        } else {
            $updated = $this->controlJobs->reportResult($job, $validated['output'] ?? '');
        }

        return response()->json(['status' => $updated->status]);
    }
}
