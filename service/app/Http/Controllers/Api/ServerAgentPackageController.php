<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ServerAgentControlJob;
use App\Models\ServerAgentPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Sits behind 'agent.signed', same signed channel as every other Server
 * Agent endpoint. A Server Agent may only ever download a package it has
 * an active (queued/running/awaiting_heartbeat) ServerAgentControlJob
 * pointing at — never an arbitrary package just because it can guess/
 * enumerate a public_id (mirrors AgentArtifactController::download()'s
 * own "may only download what it was actually assigned" invariant).
 */
class ServerAgentPackageController extends Controller
{
    public function download(Request $request, string $publicId)
    {
        $agent = $request->attributes->get('deployment_agent');
        abort_unless($agent instanceof Agent, 403, 'Downloading a Server Agent package requires an authenticated Server Agent.');

        $package = ServerAgentPackage::where('public_id', $publicId)->firstOrFail();

        $assignedToThisAgent = ServerAgentControlJob::query()
            ->where('agent_id', $agent->id)
            ->where('server_agent_package_id', $package->id)
            ->whereIn('status', [
                ServerAgentControlJob::STATUS_QUEUED,
                ServerAgentControlJob::STATUS_RUNNING,
                ServerAgentControlJob::STATUS_AWAITING_HEARTBEAT,
            ])
            ->exists();
        abort_unless($assignedToThisAgent, 403, 'This Server Agent package is not associated with any active upgrade job for the authenticated Server Agent.');

        abort_unless($package->isAvailable(), 409, "Server Agent package is not available (status: {$package->status}).");
        abort_unless(Storage::disk('server-agent-packages')->exists($package->disk_path), 404, 'Server Agent package file is missing from storage.');

        return Storage::disk('server-agent-packages')->download($package->disk_path, "{$package->commit_hash}.zip", [
            'X-Server-Agent-Package-Checksum-Sha256' => $package->checksum_sha256,
            'X-Server-Agent-Package-Tree-Hash' => $package->tree_hash,
            'X-Server-Agent-Package-Size-Bytes' => (string) $package->size_bytes,
        ]);
    }
}
