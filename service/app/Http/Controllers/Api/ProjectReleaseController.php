<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Releases\ReleaseMetadataValidator;
use App\Services\Releases\ReleaseOrchestrator;
use App\Services\Security\GitHubWebhookVerifier;
use Illuminate\Http\Request;

final class ProjectReleaseController extends Controller
{
    public function __construct(
        private readonly GitHubWebhookVerifier $verifier,
        private readonly ReleaseMetadataValidator $validator,
        private readonly ReleaseOrchestrator $releases,
    ) {}

    public function store(Request $request, string $projectKey)
    {
        $project = Project::query()->where('project_key', $projectKey)->where('is_active', true)->firstOrFail();
        $raw = $request->getContent();
        abort_unless($this->verifier->verify($raw, $request->header('X-Deployment-Signature-256'), $project->github_webhook_secret), 401, 'Invalid release signature.');
        $decoded = json_decode($raw, true);
        abort_unless(is_array($decoded), 422, 'Release metadata must be a JSON object.');
        $metadata = $this->validator->validate($project, $decoded);
        $release = $this->releases->recordMetadata($project, $metadata);

        return response()->json(['status' => $release->status, 'release_id' => $release->public_id], 202);
    }
}
