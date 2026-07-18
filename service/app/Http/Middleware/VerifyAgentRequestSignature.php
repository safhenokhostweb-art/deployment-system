<?php

namespace App\Http\Middleware;

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
 *   1. Bearer token identifies the server.
 *   2. HMAC signature over method+path+timestamp+nonce+body proves the
 *      request came from a holder of that server's signing_secret and was
 *      not altered in transit.
 *   3. Timestamp window + one-time nonce prevent replay of a captured
 *      request.
 *
 * On success, the authenticated Server model is bound into the request as
 * 'deployment_agent_server' for downstream controllers.
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
        $serverPublicId = $request->header('X-Deployment-Server-Id');
        $timestamp = $request->header('X-Deployment-Timestamp');
        $nonce = $request->header('X-Deployment-Nonce');
        $signature = $request->header('X-Deployment-Signature');

        if (!$token || !$serverPublicId || !$timestamp || !$nonce || !$signature) {
            return $this->reject($request, null, 'missing_auth_headers');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->reject($request, $serverPublicId, 'stale_or_invalid_timestamp');
        }

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

        if (!$this->consumeNonce($server, $nonce)) {
            return $this->reject($request, $serverPublicId, 'replayed_nonce');
        }

        $server->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('deployment_agent_server', $server);

        return $next($request);
    }

    private function consumeNonce(Server $server, string $nonce): bool
    {
        try {
            AgentRequestNonce::create([
                'server_id' => $server->id,
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
