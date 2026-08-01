<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\AgentJobService;
use App\Services\Deployment\AgentTaskService;
use Illuminate\Http\Request;

/**
 * All routes here sit behind VerifyAgentRequestSignature. The authenticated
 * Server is available via $request->attributes->get('deployment_agent_server'),
 * and the authenticated Agent (new-domain callers only) via
 * $request->attributes->get('deployment_agent').
 *
 * Phase 3-C.2: `next`/`updateStatus`/`appendLog` now resolve either a
 * legacy Deployment or a new-domain AgentJob behind the *same* endpoints —
 * no new URL an agent needs to learn. The legacy branch of each method is
 * the exact, unmodified call it made before this phase; the new branch is
 * only ever reached when the legacy lookup finds nothing.
 */
class AgentJobController extends Controller
{
    public function __construct(
        private readonly AgentJobService $jobs,
        private readonly AgentTaskService $tasks,
    ) {
    }

    /**
     * Distinct from job polling so version/liveness reporting keeps working
     * even on a cycle where there's nothing to deploy, and so it can be
     * called on its own cadence later without touching job-claim logic.
     *
     * Old Agent (legacy Server auth) keeps today's exact behavior —
     * agent_version only, written to Server. New Agent (Phase 3-B) reports
     * the fuller payload, written to its own Agent row. Which branch runs
     * is decided entirely by what VerifyAgentRequestSignature already
     * resolved; this method changes no auth or claim logic.
     */
    public function heartbeat(Request $request)
    {
        $agent = $request->attributes->get('deployment_agent');

        if ($agent instanceof Agent) {
            $validated = $request->validate([
                'agent_version' => 'required|string|max:50',
                'os' => 'nullable|string|max:100',
                'architecture' => 'nullable|string|max:100',
                'capabilities' => 'nullable|array',
                // Phase 4-K Task 11: Server Health Foundation — all three
                // optional. An agent binary that predates this phase never
                // sends them, and forceFill() below leaves the column at
                // whatever it already was (null, for every such agent).
                'cpu_load_percent' => 'nullable|numeric|min:0|max:1000',
                'memory_usage_percent' => 'nullable|numeric|min:0|max:100',
                'disk_usage_percent' => 'nullable|numeric|min:0|max:100',
            ]);

            $reportedHealth = array_key_exists('cpu_load_percent', $validated)
                || array_key_exists('memory_usage_percent', $validated)
                || array_key_exists('disk_usage_percent', $validated);

            $agent->forceFill([
                'agent_version' => $validated['agent_version'],
                'os' => $validated['os'] ?? $agent->os,
                'architecture' => $validated['architecture'] ?? $agent->architecture,
                'capabilities' => $validated['capabilities'] ?? $agent->capabilities,
                'ip_address' => $request->ip(),
                'cpu_load_percent' => $validated['cpu_load_percent'] ?? $agent->cpu_load_percent,
                'memory_usage_percent' => $validated['memory_usage_percent'] ?? $agent->memory_usage_percent,
                'disk_usage_percent' => $validated['disk_usage_percent'] ?? $agent->disk_usage_percent,
                'health_reported_at' => $reportedHealth ? now() : $agent->health_reported_at,
            ])->save();

            return response()->json(['status' => 'ok']);
        }

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

        if ($claimed !== null) {
            return response()->json($claimed);
        }

        // New-domain claiming is only reachable for Agent-authenticated
        // callers — a DeploymentTarget always needs a real Agent to record
        // as the claimer, and a legacy Server credential has no Agent row
        // to attribute an AgentJob to.
        $agent = $request->attributes->get('deployment_agent');

        if ($agent instanceof Agent) {
            $claimedTask = $this->tasks->claimNextTask($agent);

            if ($claimedTask !== null) {
                return response()->json($claimedTask);
            }
        }

        return response()->json(['job' => null]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $server = $this->authenticatedServer($request);

        if ($deployment = Deployment::where('public_id', $id)->first()) {
            $this->authorizeServerOwnsDeployment($server, $deployment);

            $validated = $request->validate([
                // building/migrating/activating/verifying/rolled_back are
                // the recipe-aware phases (Phase 4) — a legacy pipeline
                // deployment never reports them, going straight
                // running -> succeeded/failed exactly as before.
                'status' => 'required|string|in:running,building,migrating,activating,verifying,succeeded,failed,rolled_back',
                'message' => 'nullable|string|max:5000',
                'failure_code' => 'nullable|string|max:100',
            ]);

            $updated = $this->jobs->updateStatus(
                $deployment,
                $validated['status'],
                $validated['message'] ?? null,
                $validated['failure_code'] ?? null,
            );

            return response()->json(['status' => $updated->status]);
        }

        $agentJob = AgentJob::where('public_id', $id)->firstOrFail();
        $this->authorizeServerOwnsAgentJob($server, $agentJob);

        $validated = $request->validate([
            'status' => 'required|string|in:running,succeeded,failed',
            'message' => 'nullable|string|max:5000',
        ]);

        $updated = $this->tasks->updateStatus($agentJob, $validated['status'], $validated['message'] ?? null);

        return response()->json(['status' => $updated->status]);
    }

    /**
     * A distinct, lightweight heartbeat for the ONE deployment a server is
     * actively executing — renews its lease mid-step (during a long `npm
     * ci` or Next.js build), independent of step boundaries. Mirrors
     * LocalAgentJobController::heartbeatJob() for PublishJob. Deployment
     * only — the dormant new-domain AgentJob/DeploymentTask path has no
     * lease columns and is out of scope here.
     */
    public function heartbeatJob(Request $request, string $id)
    {
        $server = $this->authenticatedServer($request);

        $deployment = Deployment::where('public_id', $id)->firstOrFail();
        $this->authorizeServerOwnsDeployment($server, $deployment);

        $updated = $this->jobs->heartbeat($deployment);

        return response()->json(['status' => $updated->status, 'lease_expires_at' => $updated->lease_expires_at?->toIso8601String()]);
    }

    public function appendLog(Request $request, string $id)
    {
        $server = $this->authenticatedServer($request);

        if ($deployment = Deployment::where('public_id', $id)->first()) {
            $this->authorizeServerOwnsDeployment($server, $deployment);

            $validated = $request->validate([
                'sequence' => 'required|integer|min:1',
                'step_name' => 'required|string|max:255',
                'command' => 'required|string',
                'output' => 'nullable|string',
                'exit_code' => 'nullable|integer',
                'duration_ms' => 'nullable|integer|min:0',
                'timed_out' => 'nullable|boolean',
                // Recipe-aware step tracking (Phase 2) — null for every
                // legacy pipeline step, exactly as before this column
                // existed. See RecipeStepType for the allowed step_type
                // values and DeploymentLogEntry's status column comment
                // for how a null status is interpreted.
                'status' => 'nullable|string|in:queued,running,succeeded,failed,skipped',
                'step_type' => 'nullable|string|max:100',
                'failure_code' => 'nullable|string|max:100',
            ]);

            $entry = $this->jobs->recordLog($deployment, $validated);

            return response()->json(['id' => $entry->id], 201);
        }

        $agentJob = AgentJob::where('public_id', $id)->firstOrFail();
        $this->authorizeServerOwnsAgentJob($server, $agentJob);

        $validated = $request->validate([
            'sequence' => 'required|integer|min:1',
            'step_name' => 'required|string|max:255',
            'command' => 'required|string',
            'output' => 'nullable|string',
            'exit_code' => 'nullable|integer',
            'duration_ms' => 'nullable|integer|min:0',
            'timed_out' => 'nullable|boolean',
        ]);

        $entry = $this->tasks->recordLog($agentJob, $validated);

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

    private function authorizeServerOwnsAgentJob(Server $server, AgentJob $agentJob): void
    {
        $targetServerId = $agentJob->deploymentTask->deploymentTarget->server_id;

        abort_unless($targetServerId === $server->id, 403, 'This job does not belong to the authenticated server.');
    }
}
