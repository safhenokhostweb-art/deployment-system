<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Deployment\Artifact\ArtifactValidationException;
use App\Services\Releases\ReleaseOrchestrator;
use Illuminate\Http\Request;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): the Local Agent
 * — which does have working GitHub access — uploads a `git archive`
 * artifact here immediately after a successful push, so the production
 * Server Agent never needs to fetch from GitHub at all. Sits behind
 * 'local-agent.signed', same scheme as every other Local Agent endpoint.
 *
 * Two phases (see ArtifactService's own docblock for why): create()
 * records metadata via a normal signed JSON request, uploadContent()
 * carries the raw file bytes as its entire signed request body.
 */
class LocalAgentArtifactController extends Controller
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ReleaseOrchestrator $releases,
    ) {
    }

    public function create(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Uploading an artifact requires an authenticated Local Agent.');

        // Phase: Laravel Release Completeness — artifacts must be built by
        // a Local Agent that embeds the in-artifact release manifest
        // (Local Agent >= min_local_agent_version_for_artifact). An older
        // agent produces artifacts without release metadata; reject the
        // upload fail-closed here instead of letting an unverifiable
        // artifact reach the server-side pre-activation gate. Never assume
        // an installed agent is current: a missing/unknown version is
        // rejected too.
        $minLocalAgent = (string) config('deployment.min_local_agent_version_for_artifact');
        if (!is_string($agent->agent_version) || $agent->agent_version === '' || version_compare($agent->agent_version, $minLocalAgent, '<')) {
            abort(409, "This Local Agent (version " . ($agent->agent_version ?? 'unknown') . ") is too old to produce artifacts with release metadata. Upgrade to at least {$minLocalAgent} before uploading artifacts.");
        }

        $validated = $request->validate([
            'project_key' => 'required|string|max:255',
            'commit_hash' => 'required|string|size:40|regex:/^[0-9a-f]{40}$/',
            'branch' => 'required|string|max:255',
            'checksum_sha256' => 'required|string|size:64|regex:/^[0-9a-f]{64}$/',
            'size_bytes' => 'required|integer|min:1',
            'required_paths' => 'nullable|array|max:20',
            // This expression contains alternation (`|`). Laravel splits
            // pipe-delimited string rules before handing the regex to its
            // validator, so keep this rule as an array just like
            // ProjectController's application_root validation rule.
            'required_paths.*' => ['string', 'max:500', 'regex:/^(?!.*(?:^|\\/)\\.\\.(?:\\/|$))[A-Za-z0-9._\\/-]+$/'],
            'publish_job_id' => 'nullable|string|uuid',
        ]);

        $project = $this->resolveMappedProject($agent, $validated['project_key']);
        $job = null;
        if (isset($validated['publish_job_id'])) {
            $job = PublishJob::where('public_id', $validated['publish_job_id'])->firstOrFail();
            $this->assertAssignedJob($job, $agent, $project, $validated['commit_hash'], $validated['branch']);
        } else {
            $assignedBuildExists = PublishJob::query()
                ->where('project_id', $project->id)
                ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
                ->where('reviewed_head_sha', $validated['commit_hash'])
                ->where('reviewed_branch', $validated['branch'])
                ->whereIn('status', [PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING])
                ->exists();
            abort_if($assignedBuildExists, 422, 'publish_job_id is required for an assigned artifact build.');
        }

        $artifact = $this->artifacts->createPending(
            project: $project,
            commitHash: $validated['commit_hash'],
            branch: $validated['branch'],
            claimedChecksum: $validated['checksum_sha256'],
            claimedSizeBytes: $validated['size_bytes'],
            uploadedBy: $agent->public_id,
            requiredPaths: $validated['required_paths'] ?? [],
            publishJobId: $job?->id,
        );
        if ($job !== null) {
            abort_if($artifact->release_id !== null && $artifact->release_id !== $job->release_id, 409, 'Artifact is already bound to a different Release.');
            $artifact->update(['release_id' => $job->release_id]);
        }

        return response()->json(['status' => 'ok', 'artifact_id' => $artifact->public_id], 201);
    }

    public function uploadContent(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Uploading an artifact requires an authenticated Local Agent.');

        $artifact = Artifact::where('public_id', $publicId)->firstOrFail();
        $this->resolveMappedProject($agent, $artifact->project->project_key);
        abort_unless($artifact->uploaded_by === $agent->public_id, 403, 'This artifact upload was not started by the authenticated Local Agent.');
        if ($artifact->publishJob !== null) {
            $this->assertAssignedJob($artifact->publishJob, $agent, $artifact->project, $artifact->commit_hash, $artifact->branch, allowSucceeded: true);
        }

        try {
            $finalized = $this->artifacts->finalizeUpload(
                artifact: $artifact,
                rawBytes: $request->getContent(),
                retentionCount: (int) config('deployment.artifact_retention_count_per_project'),
            );
        } catch (ArtifactValidationException $e) {
            // `reason` lets ArtifactUploader (Local Agent side) report a
            // specific PublishJob::FAILURE_CODE_ARTIFACT_MISSING_VENDOR/
            // _BUILD instead of a generic "upload failed" when that's
            // what actually happened.
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        $release = $finalized->release
            ?? $finalized->project->releases()->where('commit_hash', $finalized->commit_hash)->first();
        if ($release !== null) {
            $this->releases->syncArtifactState($release);
        }

        return response()->json(['status' => 'ok', 'artifact_id' => $finalized->public_id, 'size_bytes' => $finalized->size_bytes]);
    }

    private function resolveMappedProject(LocalAgent $agent, string $projectKey): Project
    {
        $project = Project::where('project_key', $projectKey)->first();
        abort_unless($project !== null, 404, "Unknown project_key '{$projectKey}'.");

        // Same scoping PublishJob claiming already relies on — an agent
        // may only act on a project it is explicitly mapped to, never an
        // arbitrary one it happens to guess the key of.
        $isMapped = LocalProjectMapping::where('local_agent_id', $agent->id)->where('project_id', $project->id)->exists();
        abort_unless($isMapped, 403, "This Local Agent is not mapped to project '{$projectKey}'.");

        return $project;
    }

    private function assertAssignedJob(PublishJob $job, LocalAgent $agent, Project $project, string $commitHash, string $branch, bool $allowSucceeded = false): void
    {
        abort_unless($job->local_agent_id === $agent->id, 403, 'This artifact PublishJob is assigned to a different Local Agent.');
        abort_unless($job->project_id === $project->id, 422, 'Artifact project does not match its PublishJob.');
        $statuses = [PublishJob::STATUS_RUNNING];
        if ($allowSucceeded) {
            $statuses[] = PublishJob::STATUS_SUCCEEDED;
        }
        abort_unless(in_array($job->status, $statuses, true), 409, 'Artifact PublishJob is not active.');
        if ($job->action === PublishJob::ACTION_BUILD_ARTIFACT) {
            abort_unless($job->release_id !== null, 422, 'Artifact build is not linked to a Release.');
            abort_unless($job->reviewed_head_sha === $commitHash, 422, 'Artifact SHA does not match its PublishJob.');
            abort_unless($job->reviewed_branch === $branch, 422, 'Artifact branch does not match its PublishJob.');
            abort_unless($job->release?->commit_hash === $commitHash && $job->release?->branch === $branch, 422, 'Artifact provenance does not match its Release.');
        } else {
            abort_unless($job->action === PublishJob::ACTION_PUBLISH, 422, 'Artifact upload is not associated with a build-capable PublishJob.');
        }
    }
}
