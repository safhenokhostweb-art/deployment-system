<?php

namespace App\Http\Middleware;

use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalAgentRequestNonce;
use App\Services\Security\AgentCredentialService;
use App\Services\Security\RequestSigner;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5: authenticates every inbound Local Agent call, using the exact
 * same three-part scheme as VerifyAgentRequestSignature (bearer token +
 * HMAC signature + timestamp/nonce replay protection, via the same
 * RequestSigner class) but resolving identity only against LocalAgent —
 * deliberately a separate middleware/nonce table rather than a third
 * branch inside VerifyAgentRequestSignature, so this new, less-proven
 * code path can never affect the already-hardened production Agent
 * Protocol.
 */
class VerifyLocalAgentRequestSignature
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;
    private const MAX_CONTENT_UPLOAD_SKEW_SECONDS = 1800;

    public function __construct(
        private readonly RequestSigner $signer,
        private readonly AgentCredentialService $credentials,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $publicId = $request->header('X-Deployment-Local-Agent-Id');
        $timestamp = $request->header('X-Deployment-Timestamp');
        $nonce = $request->header('X-Deployment-Nonce');
        $signature = $request->header('X-Deployment-Signature');

        if (!$token || !$publicId || !$timestamp || !$nonce || !$signature) {
            return $this->reject($request, null, 'missing_auth_headers');
        }

        $isContentUpload = $request->is('api/local-agent/artifacts/*/content');
        $maxSkew = $isContentUpload ? self::MAX_CONTENT_UPLOAD_SKEW_SECONDS : self::MAX_CLOCK_SKEW_SECONDS;

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $maxSkew) {
            return $this->reject($request, $publicId, 'stale_or_invalid_timestamp');
        }

        $agent = LocalAgent::where('public_id', $publicId)->first();

        if (!$agent) {
            return $this->reject($request, $publicId, 'unknown_local_agent');
        }

        if (!$agent->isApproved()) {
            return $this->reject($request, $agent->public_id, 'local_agent_not_approved');
        }

        if (!$this->credentials->verifyToken($token, (string) $agent->agent_token_hash)) {
            return $this->reject($request, $agent->public_id, 'invalid_token');
        }

        $rawBody = $request->getContent();
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > 0 && $rawBody === '') {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $agent->public_id,
                action: 'local_agent.payload_truncated',
                metadata: ['content_length' => $contentLength, 'path' => $request->path()],
                ipAddress: $request->ip(),
            );

            return response()->json([
                'message' => 'Request body was empty despite non-zero Content-Length; check server post_max_size / upload limits.',
            ], 413);
        }
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

        if (!$this->consumeNonce($agent->id, $nonce)) {
            return $this->reject($request, $agent->public_id, 'replayed_nonce');
        }

        $agent->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('deployment_local_agent', $agent);

        return $next($request);
    }

    private function consumeNonce(int $localAgentId, string $nonce): bool
    {
        try {
            LocalAgentRequestNonce::create([
                'local_agent_id' => $localAgentId,
                'nonce' => $nonce,
            ]);

            return true;
        } catch (QueryException) {
            // Unique (local_agent_id, nonce) violation: this nonce was already used.
            return false;
        }
    }

    private function reject(Request $request, ?string $localAgentPublicId, string $reason): Response
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $localAgentPublicId,
            action: 'local_agent.auth_rejected',
            metadata: ['reason' => $reason, 'path' => $request->path()],
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
