<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use App\Services\Publish\LocalAgentControlJobService;
use Illuminate\Http\Request;

/**
 * Phase 5: sits behind 'local-agent.signed' — the Local Agent equivalent
 * of AgentJobController's heartbeat()/next() plus AgentOperationController's
 * next()/report() shape. One `/publish-jobs/{id}/report` endpoint handles
 * both phases of a publish (status_check result, then the final
 * publish/rollback result) since the job's own `action`/`status` already
 * disambiguate which phase a given report belongs to.
 */
class LocalAgentJobController extends Controller
{
    public function __construct(
        private readonly PublishJobService $publishJobs,
        private readonly LocalAgentControlJobService $controlJobs,
    ) {
    }

    public function heartbeat(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Heartbeat requires an authenticated Local Agent.');

        $validated = $request->validate([
            'hostname' => 'nullable|string|max:255',
            'operating_system' => 'nullable|string|max:100',
            'capabilities' => 'nullable|array',
            'agent_version' => 'nullable|string|max:50',
            'project_states' => 'nullable|array|max:100',
            'project_states.*.project_key' => 'required|string|max:255',
            'project_states.*.local_head_sha' => 'nullable|string|regex:/^[0-9a-f]{40}$/',
            'project_states.*.branch' => 'nullable|string|max:255',
            'project_states.*.working_tree_dirty' => 'required|boolean',
            'project_states.*.remote_tracking_sha' => 'nullable|string|regex:/^[0-9a-f]{40}$/',
        ]);

        $agent->forceFill([
            'hostname' => $validated['hostname'] ?? $agent->hostname,
            'operating_system' => $validated['operating_system'] ?? $agent->operating_system,
            'capabilities' => $validated['capabilities'] ?? $agent->capabilities,
            'agent_version' => $validated['agent_version'] ?? $agent->agent_version,
        ])->save();

        // An upgrade is not complete when files are merely activated. The
        // first authenticated heartbeat from the restarted process is the
        // authoritative version proof and the only success transition.
        $this->controlJobs->verifyUpgradeHeartbeat($agent, $validated['agent_version'] ?? null);

        foreach ($validated['project_states'] ?? [] as $state) {
            $mapping = LocalProjectMapping::query()
                ->where('local_agent_id', $agent->id)
                ->whereHas('project', fn ($query) => $query->where('project_key', $state['project_key']))
                ->first();
            abort_if($mapping === null, 422, 'Heartbeat reported a project that is not mapped to this Local Agent.');

            $mapping->update([
                'local_head_sha' => $state['local_head_sha'] ?? null,
                'reported_branch' => $state['branch'] ?? null,
                'working_tree_dirty' => $state['working_tree_dirty'],
                'remote_tracking_sha' => $state['remote_tracking_sha'] ?? null,
                'reported_at' => now(),
            ]);
        }

        // Phase 5.3: check-and-notify only — the agent decides for itself
        // whether to log this, nothing here ever triggers a download.
        // project_config_version piggybacks on this already-every-cycle
        // call so the agent can detect a changed project mapping without
        // a dedicated poll of its own — see ProjectConfigSyncScheduler on
        // the agent side.
        return response()->json([
            'status' => 'ok',
            'latest_version' => (string) config('deployment.latest_local_agent_version'),
            'project_config_version' => $agent->projectConfigVersion(),
        ]);
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Polling for a publish job requires an authenticated Local Agent.');

        $job = $this->publishJobs->claimNext($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'action' => $job->action,
                // Never a raw path — the agent resolves project_key against
                // its own local config.php, same pattern as
                // AgentOperationController::next()'s project_key.
                'project_key' => $job->project->project_key,
                'commit_message' => $job->action === PublishJob::ACTION_PUBLISH ? $job->commit_message : null,
                'no_source_change' => $job->action === PublishJob::ACTION_PUBLISH ? $job->no_source_change : false,
                'expected_head_sha' => $job->action === PublishJob::ACTION_PUBLISH && $job->no_source_change ? $job->reviewed_head_sha : null,
                'expected_branch' => $job->action === PublishJob::ACTION_PUBLISH && $job->no_source_change ? $job->reviewed_branch : null,
                'artifact_commit_hash' => $job->action === PublishJob::ACTION_BUILD_ARTIFACT ? $job->reviewed_head_sha : null,
                'artifact_branch' => $job->action === PublishJob::ACTION_BUILD_ARTIFACT ? $job->reviewed_branch : null,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'Reporting a publish job requires an authenticated Local Agent.');

        $job = PublishJob::where('public_id', $publicId)->firstOrFail();

        abort_unless($job->local_agent_id === $agent->id, 403, 'This publish job does not belong to the authenticated Local Agent.');

        // A status_check job reports its result via `changed_files`
        // (success — nothing else to report, there is no exit status for
        // "git status" itself) rather than the `status` field every other
        // report shape uses. If `git status` itself blew up, the agent
        // sends the same `status: failed` shape as any other job instead
        // — checked first so that failure path is never swallowed here.
        if ($job->action === PublishJob::ACTION_STATUS_CHECK && !$request->has('status')) {
            $validated = $request->validate([
                'changed_files' => 'present|array',
                'changed_files.*' => 'string|max:1000',
                'blocked_files' => 'nullable|array',
                'blocked_files.*' => 'string|max:1000',
                'head_sha' => 'required|string|regex:/^[0-9a-f]{40}$/',
                'branch' => 'required|string|max:255',
                'working_tree_clean' => 'required|boolean',
            ]);

            $updated = $this->publishJobs->reportStatusCheck($job, $validated['changed_files'], $validated['blocked_files'] ?? [], $validated['head_sha'], $validated['branch'], $validated['working_tree_clean']);

            return response()->json(['status' => $updated->status]);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:succeeded,failed',
            'commit_hash' => 'nullable|string|max:64',
            'output' => 'nullable|string|max:100000',
            'error_message' => 'nullable|string|max:2000',
            // Root-cause fix: lets the agent tell the Hub its own command
            // exceeded a configured timeout (see GitManager/CommitManager/
            // PushManager on the agent side), or that the full local
            // build+artifact pipeline (LocalBuildRunner/ArtifactBuilder/
            // ArtifactUploader) failed at a specific, identifiable stage —
            // distinct, self-reported outcomes from a plain failure, and
            // from the Hub-inferred `abandoned` deployments:recover-stale
            // produces.
            'failure_code' => 'nullable|string|in:' . implode(',', PublishJob::AGENT_REPORTABLE_FAILURE_CODES),
        ]);

        if ($validated['status'] === 'failed') {
            $updated = $this->publishJobs->reportFailure(
                $job,
                $validated['error_message'] ?? 'Unknown agent-side publish failure.',
                $validated['output'] ?? null,
                $validated['failure_code'] ?? null,
            );
        } else {
            $updated = $this->publishJobs->reportResult($job, $validated['commit_hash'] ?? null, $validated['output'] ?? '');
        }

        return response()->json(['status' => $updated->status]);
    }

    /**
     * The Job-heartbeat channel (distinct from heartbeat() above, which
     * is the Local Agent's general online signal) — renews a running
     * PublishJob's lease (and, for publish/rollback, its Project's
     * publish lock) every ~20-30s while the agent's git command is still
     * in progress. See PublishJobService::heartbeat().
     */
    public function heartbeatJob(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');

        abort_unless($agent instanceof LocalAgent, 403, 'A job heartbeat requires an authenticated Local Agent.');

        $job = PublishJob::where('public_id', $publicId)->firstOrFail();

        $updated = $this->publishJobs->heartbeat($job, $agent);

        return response()->json([
            'status' => $updated->status,
            'lease_expires_at' => $updated->lease_expires_at?->toIso8601String(),
        ]);
    }
}
