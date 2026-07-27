<?php

namespace App\Http\Controllers\Api;

use App\Events\LocalAgentApprovalRequired;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalAgentRequestNonce;
use App\Services\Security\RequestSigner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Phase 5: not behind 'local-agent.signed' — a pending Local Agent has
 * no signing_secret yet. Mirrors AgentRegistrationController exactly:
 * its own narrower signature scheme keyed by the one-time
 * registration_token, on its own header names, with its own replay
 * table — independent of the steady-state auth path on purpose.
 */
class LocalAgentRegistrationController extends Controller
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly RequestSigner $signer,
    ) {
    }

    public function register(Request $request)
    {
        $registrationId = $request->header('X-Deployment-Local-Registration-Id');
        $timestamp = $request->header('X-Deployment-Local-Registration-Timestamp');
        $nonce = $request->header('X-Deployment-Local-Registration-Nonce');
        $signature = $request->header('X-Deployment-Local-Registration-Signature');

        if (!$registrationId || !$timestamp || !$nonce || !$signature) {
            return $this->reject($registrationId, 'missing_registration_headers');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->reject($registrationId, 'stale_or_invalid_timestamp');
        }

        $agent = LocalAgent::where('public_id', $registrationId)
            ->where('status', LocalAgent::STATUS_PENDING_APPROVAL)
            ->first();

        if (!$agent) {
            return $this->reject($registrationId, 'unknown_or_non_pending_registration');
        }

        if (!$agent->isRegistrationTokenValid()) {
            return $this->reject($registrationId, 'expired_or_already_consumed_registration_token');
        }

        $rawBody = $request->getContent();
        $valid = $this->signer->verify(
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            $rawBody,
            $agent->registration_token,
            $signature,
        );

        if (!$valid) {
            return $this->reject($registrationId, 'invalid_registration_signature');
        }

        if (!$this->consumeNonce($agent, $nonce)) {
            return $this->reject($registrationId, 'replayed_registration_nonce');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'hostname' => 'nullable|string|max:255',
            'operating_system' => 'required|string|max:100',
            'capabilities' => 'nullable|array',
        ]);

        $agent->update([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'] ?? null,
            'operating_system' => $validated['operating_system'],
            'capabilities' => $validated['capabilities'] ?? null,
            // Single-use: clearing the token means a replayed registration
            // attempt has nothing left to verify a signature against.
            'registration_token' => null,
            'registration_token_expires_at' => null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $agent->public_id,
            action: 'local_agent.registered',
            subjectType: LocalAgent::class,
            subjectId: $agent->id,
            metadata: ['operating_system' => $validated['operating_system']],
            ipAddress: $request->ip(),
        );

        event(new LocalAgentApprovalRequired($agent));

        return response()->json(['status' => LocalAgent::STATUS_PENDING_APPROVAL]);
    }

    private function consumeNonce(LocalAgent $agent, string $nonce): bool
    {
        try {
            LocalAgentRequestNonce::create([
                'local_agent_id' => $agent->id,
                'nonce' => $nonce,
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function reject(?string $registrationId, string $reason): \Illuminate\Http\JsonResponse
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $registrationId,
            action: 'local_agent.registration_rejected',
            metadata: ['reason' => $reason],
        );

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
