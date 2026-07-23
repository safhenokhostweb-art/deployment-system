<?php

namespace App\Http\Controllers\Api;

use App\Events\AgentApprovalRequired;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRequestNonce;
use App\Models\AuditLogEntry;
use App\Services\Security\RequestSigner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Not behind VerifyAgentRequestSignature/'agent.signed' — a pending agent
 * has no signing_secret yet. This endpoint verifies its own, narrower
 * signature scheme keyed by the one-time registration_token instead, using
 * the same RequestSigner algorithm and the same replay-protection table.
 * See docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md §4 for the full design.
 */
class AgentRegistrationController extends Controller
{
    /**
     * Deliberately duplicated from VerifyAgentRequestSignature rather than
     * extracted into a shared constant — this endpoint's verification path
     * is intentionally independent so a future change to steady-state auth
     * can't accidentally weaken (or be weakened by) registration, and vice
     * versa. See the Phase 3-B implementation report for the rationale.
     */
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly RequestSigner $signer,
    ) {
    }

    public function register(Request $request)
    {
        $registrationId = $request->header('X-Deployment-Registration-Id');
        $timestamp = $request->header('X-Deployment-Registration-Timestamp');
        $nonce = $request->header('X-Deployment-Registration-Nonce');
        $signature = $request->header('X-Deployment-Registration-Signature');

        if (!$registrationId || !$timestamp || !$nonce || !$signature) {
            return $this->reject($registrationId, 'missing_registration_headers');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->reject($registrationId, 'stale_or_invalid_timestamp');
        }

        $agent = Agent::where('public_id', $registrationId)
            ->where('status', Agent::STATUS_PENDING_APPROVAL)
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
            'os' => 'required|string|max:100',
            'architecture' => 'required|string|max:100',
            'capabilities' => 'nullable|array',
        ]);

        $agent->update([
            'name' => $validated['name'],
            'os' => $validated['os'],
            'architecture' => $validated['architecture'],
            'capabilities' => $validated['capabilities'] ?? null,
            'ip_address' => $request->ip(),
            // Single-use: clearing the token means a replayed registration
            // attempt has nothing left to verify a signature against.
            'registration_token' => null,
            'registration_token_expires_at' => null,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $agent->public_id,
            action: 'agent.registered',
            subjectType: Agent::class,
            subjectId: $agent->id,
            metadata: ['os' => $validated['os'], 'architecture' => $validated['architecture']],
            ipAddress: $request->ip(),
        );

        // Phase 4-J: pure instrumentation — the only consumer is
        // NotificationEventSubscriber, which only ever creates a
        // Notification row. No existing behavior here changes.
        event(new AgentApprovalRequired($agent));

        return response()->json(['status' => Agent::STATUS_PENDING_APPROVAL]);
    }

    private function consumeNonce(Agent $agent, string $nonce): bool
    {
        try {
            AgentRequestNonce::create([
                'server_id' => $agent->server_id,
                'agent_id' => $agent->id,
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
            action: 'agent.registration_rejected',
            metadata: ['reason' => $reason],
        );

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
