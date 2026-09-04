<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Models\ServerAgentPackageBuildJob;
use App\Services\Publish\ServerAgentPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sits behind 'local-agent.signed', same signed channel as every other
 * Local Agent endpoint — no new protocol. The Local Agent is the only
 * already-trusted, git-capable machine in this system; this is the Hub
 * asking it to materialize one exact-commit Server Agent package (see
 * ServerAgentPackageBuildJob's own docblock).
 */
class ServerAgentPackageBuildJobController extends Controller
{
    public function __construct(
        private readonly ServerAgentPackageService $packages,
    ) {
    }

    public function next(Request $request)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Polling for a Server Agent package build job requires an authenticated Local Agent.');

        $job = $this->packages->claimNextBuildJob($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'commit_hash' => $job->commit_hash,
                'branch' => $job->branch,
                'agent_version' => $job->agent_version,
                'repository_mapping' => $job->repository_mapping,
            ],
        ]);
    }

    public function report(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_local_agent');
        abort_unless($agent instanceof LocalAgent, 403, 'Reporting a Server Agent package build job requires an authenticated Local Agent.');

        $job = ServerAgentPackageBuildJob::where('public_id', $publicId)->firstOrFail();
        abort_unless($job->local_agent_id === $agent->id, 403, 'This build job does not belong to the authenticated Local Agent.');

        $validated = $request->validate([
            'status' => 'required|string|in:succeeded,failed',
            'error_message' => 'nullable|string|max:2000',
            'commit_hash' => 'required_if:status,succeeded|nullable|string|size:40|regex:/^[0-9a-f]{40}$/',
            'repository_mapping' => 'required_if:status,succeeded|nullable|string|max:100',
            'agent_version' => 'required_if:status,succeeded|nullable|string|max:50',
            'tree_hash' => 'required_if:status,succeeded|nullable|string|size:40|regex:/^[0-9a-f]{40}$/',
            'checksum_sha256' => 'required_if:status,succeeded|nullable|string|size:64|regex:/^[0-9a-f]{64}$/',
            'size_bytes' => 'required_if:status,succeeded|nullable|integer|min:1|max:52428800',
            'content_base64' => 'required_if:status,succeeded|nullable|string',
        ]);

        if ($validated['status'] === 'failed') {
            $updated = $this->packages->reportBuildFailure($job, $validated['error_message'] ?? 'Unknown Local Agent Server Agent package build failure.');

            return response()->json(['status' => $updated->status]);
        }

        $content = base64_decode($validated['content_base64'], true);
        abort_if($content === false, 422, 'The uploaded Server Agent package content is not valid base64.');
        abort_unless(strlen($content) === $validated['size_bytes'], 422, 'The uploaded Server Agent package size does not match the declared size.');
        abort_unless(hash_equals($validated['checksum_sha256'], hash('sha256', $content)), 422, 'The uploaded Server Agent package checksum does not match its own declared content.');

        $diskPath = 'server-agent/' . $validated['repository_mapping'] . '/' . $validated['commit_hash'] . '-' . Str::random(8) . '.zip';
        Storage::disk('server-agent-packages')->put($diskPath, $content);

        $package = $this->packages->recordPackageUploaded($job, [
            'commit_hash' => $validated['commit_hash'],
            'repository_mapping' => $validated['repository_mapping'],
            'agent_version' => $validated['agent_version'],
            'tree_hash' => $validated['tree_hash'],
            'checksum_sha256' => $validated['checksum_sha256'],
            'size_bytes' => $validated['size_bytes'],
            'disk_path' => $diskPath,
        ]);

        return response()->json(['status' => 'succeeded', 'package_id' => $package->public_id]);
    }
}
