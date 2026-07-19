<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\AgentRequestNonce;
use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Services\Security\AgentCredentialService;
use App\Services\Security\RequestSigner;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates and authorizes every inbound agent call:
 *   1. Bearer token identifies the caller.
 *   2. HMAC signature over method+path+timestamp+nonce+body proves the
 *      request came from a holder of the matching signing_secret and was
 *      not altered in transit.
 *   3. Timestamp window + one-time nonce prevent replay of a captured
 *      request.
 *
 * Resolution order (docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md §3.2 /
 * PHASE_3B_IMPLEMENTATION_REPORT.md): the header value is looked up
 * against Agent first ("New Agent" path); if no Agent row matches at all,
 * this falls through to exactly today's Server lookup, byte-for-byte
 * unchanged ("Old Agent" path) — so shipping Agent support requires zero
 * changes to any already-deployed agent. If an Agent row *does* match but
 * isn't approved (pending/disabled), authentication is rejected outright
 * — it never falls through to Server, even if a Server with the same
 * public_id happens to exist, so disabling an Agent can never be
 * bypassed by an old credential.
 *
 * On success, 'deployment_agent_server' holds the Server either way (an
 * Agent-authenticated request resolves it via $agent->server), so every
 * existing downstream controller keeps working unmodified regardless of
 * which path authenticated the call. 'deployment_agent' holds the Agent
 * model when the New Agent path was used, or null for Old Agent/legacy.
 */
class VerifyAgentRequestSignature
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly RequestSigner $signer,
        private readonly AgentCredentialService $credentials,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $publicId = $request->header('X-Deployment-Server-Id');
        $timestamp = $request->header('X-Deployment-Timestamp');
        $nonce = $request->header('X-Deployment-Nonce');
        $signature = $request->header('X-Deployment-Signature');

        if (!$token || !$publicId || !$timestamp || !$nonce || !$signature) {
            return $this->reject($request, null, 'missing_auth_headers');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->reject($request, $publicId, 'stale_or_invalid_timestamp');
        }

        $agent = Agent::where('public_id', $publicId)->first();

        if ($agent) {
            return $this->handleAgent($request, $next, $agent, $token, $timestamp, $nonce, $signature);
        }

        return $this->handleLegacyServer($request, $next, $publicId, $token, $timestamp, $nonce, $signature);
    }

    private function handleAgent(Request $request, Closure $next, Agent $agent, string $token, string $timestamp, string $nonce, string $signature): Response
    {
        if (!$agent->isApproved()) {
            return $this->reject($request, $agent->public_id, 'agent_not_approved');
        }

        if (!$this->credentials->verifyToken($token, (string) $agent->agent_token_hash)) {
            return $this->reject($request, $agent->public_id, 'invalid_token');
        }

        $rawBody = $request->getContent();
        $valid = $this->signer->verify(
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            $rawBody,
            (string) $agent->signing_secret,
            $signature,
        );

        if (!$valid) {
            return $this->reject($request, $agent->public_id, 'invalid_signature');
        }

        if (!$this->consumeNonce($agent->server_id, $agent->id, $nonce)) {
            return $this->reject($request, $agent->public_id, 'replayed_nonce');
        }

        $agent->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('deployment_agent', $agent);
        $request->attributes->set('deployment_agent_server', $agent->server);

        return $next($request);
    }

    private function handleLegacyServer(Request $request, Closure $next, string $serverPublicId, string $token, string $timestamp, string $nonce, string $signature): Response
    {
        $server = Server::where('public_id', $serverPublicId)->where('is_active', true)->first();

        if (!$server) {
            return $this->reject($request, $serverPublicId, 'unknown_or_inactive_server');
        }

        if (!$this->credentials->verifyToken($token, $server->agent_token_hash)) {
            return $this->reject($request, $serverPublicId, 'invalid_token');
        }

        $rawBody = $request->getContent();
        $valid = $this->signer->verify(
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            $rawBody,
            $server->signing_secret,
            $signature,
        );

        if (!$valid) {
            return $this->reject($request, $serverPublicId, 'invalid_signature');
        }

        if (!$this->consumeNonce($server->id, null, $nonce)) {
            return $this->reject($request, $serverPublicId, 'replayed_nonce');
        }

        $server->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('deployment_agent', null);
        $request->attributes->set('deployment_agent_server', $server);

        return $next($request);
    }

    private function consumeNonce(int $serverId, ?int $agentId, string $nonce): bool
    {
        try {
            AgentRequestNonce::create([
                'server_id' => $serverId,
                'agent_id' => $agentId,
                'nonce' => $nonce,
            ]);

            return true;
        } catch (QueryException) {
            // Unique (server_id, nonce) violation: this nonce was already used.
            return false;
        }
    }

    private function reject(Request $request, ?string $serverPublicId, string $reason): Response
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $serverPublicId,
            action: 'agent.auth_rejected',
            metadata: ['reason' => $reason, 'path' => $request->path()],
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
