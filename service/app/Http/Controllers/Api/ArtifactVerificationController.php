<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\Server;
use App\Services\Deployment\Artifact\ArtifactPhysicalIntegrityException;
use App\Services\Deployment\Artifact\ArtifactService;
use Illuminate\Http\Request;

/**
 * Read-only artifact verification for Server Agents.
 *
 * Allows an authenticated Server Agent to verify the existence, status,
 * and physical integrity (on-disk size and SHA-256 checksum) of an artifact
 * without requiring an associated Deployment record. No artifact bytes are
 * returned and no internal storage paths (disk_path, tokens, keys) are
 * disclosed.
 *
 * Sits behind 'agent.signed', same scheme as every other Server Agent endpoint.
 */
class ArtifactVerificationController extends Controller
{
    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {
    }

    public function verify(Request $request, string $publicId)
    {
        $server = $request->attributes->get('deployment_agent_server');
        abort_unless($server instanceof Server, 403, 'Verifying an artifact requires an authenticated server.');

        $artifact = Artifact::where('public_id', $publicId)->first();
        if ($artifact === null) {
            return response()->json([
                'public_id' => $publicId,
                'status' => 'not_found',
                'verified' => false,
                'reason' => 'artifact_not_found',
            ], 404);
        }

        try {
            $this->artifacts->assertPhysicalIntegrity($artifact);
        } catch (ArtifactPhysicalIntegrityException $e) {
            return response()->json([
                'public_id' => $artifact->public_id,
                'status' => $artifact->status,
                'verified' => false,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 409);
        }

        $descriptor = $this->artifacts->downloadDescriptor($artifact);

        return response()->json([
            'public_id' => $descriptor['public_id'],
            'status' => $artifact->status,
            'semantic_status' => $artifact->semantic_status,
            'commit_hash' => $descriptor['commit_hash'],
            'branch' => $artifact->branch,
            'checksum_sha256' => $descriptor['checksum_sha256'],
            'size_bytes' => $descriptor['size_bytes'],
            'file_manifest_hash' => $descriptor['file_manifest_hash'] ?? null,
            'file_manifest_entry_count' => $descriptor['file_manifest_entry_count'] ?? null,
            'file_manifest_total_size' => $descriptor['file_manifest_total_size'] ?? null,
            'required_paths' => $descriptor['required_paths'] ?? [],
            'expires_at' => $artifact->expires_at?->toIso8601String(),
            'verified' => true,
        ]);
    }
}
