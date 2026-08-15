<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Deployment\Artifact\ArtifactValidationException;
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
    ) {
    }

    public function create(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Uploading an artifact requires an authenticated Local Agent.');

        $validated = $request->validate([
            'project_key' => 'required|string|max:255',
            'commit_hash' => 'required|string|size:40|regex:/^[0-9a-f]{40}$/',
            'branch' => 'required|string|max:255',
            'checksum_sha256' => 'required|string|size:64|regex:/^[0-9a-f]{64}$/',
            'size_bytes' => 'required|integer|min:1',
            'required_paths' => 'nullable|array|max:20',
            'required_paths.*' => 'string|max:500|regex:/^(?!.*(?:^|\\/)\\.\\.(?:\\/|$))[A-Za-z0-9._\\/-]+$/',
        ]);

        $project = $this->resolveMappedProject($agent, $validated['project_key']);

        $artifact = $this->artifacts->createPending(
            project: $project,
            commitHash: $validated['commit_hash'],
            branch: $validated['branch'],
            claimedChecksum: $validated['checksum_sha256'],
            claimedSizeBytes: $validated['size_bytes'],
            uploadedBy: $agent->public_id,
            requiredPaths: $validated['required_paths'] ?? [],
        );

        return response()->json(['status' => 'ok', 'artifact_id' => $artifact->public_id], 201);
    }

    public function uploadContent(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Uploading an artifact requires an authenticated Local Agent.');

        $artifact = Artifact::where('public_id', $publicId)->firstOrFail();
        $this->resolveMappedProject($agent, $artifact->project->project_key);
        abort_unless($artifact->uploaded_by === $agent->public_id, 403, 'This artifact upload was not started by the authenticated Local Agent.');

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
}
