<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly): the Server Agent
 * downloads the artifact the Local Agent already uploaded FROM THE HUB
 * — a connection it already needs for polling — instead of ever
 * fetching from GitHub. Sits behind 'agent.signed', same scheme as
 * every other Server Agent endpoint.
 */
class AgentArtifactController extends Controller
{
    public function download(Request $request, string $publicId)
    {
        $server = $request->attributes->get('deployment_agent_server');
        abort_unless($server instanceof Server, 403, 'Downloading an artifact requires an authenticated server.');

        $artifact = Artifact::where('public_id', $publicId)->firstOrFail();

        // A server may only ever download an artifact tied to one of its
        // own Deployments — never an arbitrary project's artifact just
        // because it can guess/enumerate a public_id.
        $belongsToThisServer = Deployment::where('artifact_id', $artifact->id)->where('server_id', $server->id)->exists();
        abort_unless($belongsToThisServer, 403, 'This artifact is not associated with any deployment on the authenticated server.');

        abort_unless($artifact->isAvailable(), 409, "Artifact is not available (status: {$artifact->status}).");
        abort_unless(Storage::disk('artifacts')->exists($artifact->disk_path), 404, 'Artifact file is missing from storage.');

        return Storage::disk('artifacts')->download($artifact->disk_path, "{$artifact->commit_hash}.zip", [
            'X-Artifact-Checksum-Sha256' => $artifact->checksum_sha256,
            'X-Artifact-Size-Bytes' => (string) $artifact->size_bytes,
        ]);
    }
}
