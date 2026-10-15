<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;

/** Pure synthetic decision. ALLOWED is not a token or a dispatch capability. */
final class AuthorizationEvaluator
{
    public function evaluate(string $payload, SessionState $session, object $context, PolicySnapshot $policy,
        string $wallTime, int $monotonic, array $concurrency): array
    {
        try {
            PolicySnapshot::timestamp($wallTime);
            PolicySnapshot::integer($monotonic);
            PolicySnapshot::fields($concurrency, ['credential', 'controller', 'revoked', 'blocked', 'controller_instance_id']);
            PolicySnapshot::hex($concurrency['controller_instance_id']);
            if (! is_bool($concurrency['revoked']) || ! is_bool($concurrency['blocked'])) {
                throw new InvalidArgumentException('CLOCK_OR_STATE_INVALID');
            }
        } catch (InvalidArgumentException) {
            return self::denial('CONTROLLER_UNAVAILABLE', 'CLOCK_OR_STATE_INVALID');
        }
        try {
            $request = (new ProtocolValidator)->validate($payload);
        } catch (InvalidArgumentException $exception) {
            return self::denial($exception->getMessage() === 'UNSUPPORTED_PROTOCOL' ? 'UNSUPPORTED_PROTOCOL' : 'MALFORMED_REQUEST', 'SCHEMA_INVALID');
        }
        if ($request->message_type !== 'OBSERVE') {
            return self::denial('MALFORMED_REQUEST', 'OBSERVE_REQUIRED');
        }
        $state = $session->data();
        $identity = $policy->contextIdentity($context);
        if (! $session->matchesContext($context, $policy) || $identity === null) {
            return self::denial('AUTHENTICATION_FAILED', 'TRANSPORT_CONTEXT_MISMATCH');
        }
        if ($state['session_id'] !== $request->session_id) {
            return self::denial('AUTHENTICATION_FAILED', 'SESSION_BINDING_MISMATCH');
        }
        if ($state['status'] !== 'ACTIVE' || $session->expiredAt($monotonic)) {
            return self::denial('AUTHORIZATION_DENIED', 'SESSION_INACTIVE_OR_EXPIRED');
        }
        $data = $policy->data();
        if (! $policy->validAt($wallTime)) {
            return self::denial('INVALID_POLICY', 'POLICY_EXPIRED');
        }
        if ($state['policy_version'] !== $data['policy_version'] || $state['policy_digest'] !== $data['policy_digest']
            || $request->policy_version !== $data['policy_version'] || $request->policy_digest !== $data['policy_digest']) {
            return self::denial('INVALID_POLICY', 'POLICY_BINDING_MISMATCH');
        }
        $grant = $policy->effectiveGrant($state['credential_id']);
        if ($grant === null || $grant['revoked'] || ($concurrency['revoked'] ?? true)) {
            return self::denial('AUTHORIZATION_DENIED', 'GRANT_REVOKED_OR_UNKNOWN');
        }
        foreach (['application_id', 'credential_id', 'credential_epoch', 'authorization_epoch'] as $field) {
            if ($grant[$field] !== $state[$field] || $grant[$field] !== $identity[$field]) {
                return self::denial('AUTHORIZATION_DENIED', 'IDENTITY_OR_EPOCH_MISMATCH');
            }
        }
        if ($state['scope_digest'] !== $policy->scopeDigest($state['credential_id'])) {
            return self::denial('AUTHORIZATION_DENIED', 'SCOPE_DIGEST_MISMATCH');
        }
        if (strcmp($wallTime, $grant['valid_from']) < 0 || strcmp($wallTime, $grant['valid_until']) >= 0) {
            return self::denial('AUTHORIZATION_DENIED', 'GRANT_EXPIRED_OR_NOT_YET_VALID');
        }
        if (! in_array($request->operation, $grant['allowed_operations'], true)) {
            return self::denial('AUTHORIZATION_DENIED', 'OPERATION_SCOPE');
        }
        $root = $request->operation !== 'OBSERVE_SERVICE_STATUS';
        $targetId = $root ? $request->target->root_id : $request->target->service_id;
        if (! isset($data[$root ? 'root_registry' : 'service_registry'][$targetId])
            || ! in_array($targetId, $grant[$root ? 'allowed_root_ids' : 'allowed_service_ids'], true)) {
            return self::denial('AUTHORIZATION_DENIED', 'RESOURCE_SCOPE');
        }
        if ($request->timeout_ms > $grant['max_timeout_ms']) {
            return self::denial('AUTHORIZATION_DENIED', 'TIMEOUT_LIMIT');
        }
        if ($request->timeout_ms <= $data['cleanup_budget_ms']) {
            return self::denial('AUTHORIZATION_DENIED', 'CLEANUP_BUDGET');
        }
        foreach (['credential', 'controller'] as $counter) {
            if (! isset($concurrency[$counter]) || ! is_int($concurrency[$counter]) || $concurrency[$counter] < 0) {
                return self::denial('CONTROLLER_UNAVAILABLE', 'CONCURRENCY_STATE_INVALID');
            }
        }
        if (($concurrency['blocked'] ?? true) || ($concurrency['controller_instance_id'] ?? null) !== $state['controller_instance_id']) {
            return self::denial('CONTROLLER_UNAVAILABLE', 'INSTANCE_OR_STATE_INVALID');
        }
        if ($state['outstanding_request'] !== null || $concurrency['credential'] >= $grant['max_concurrent_requests']
            || $concurrency['controller'] >= $data['resource_limits']['workers_per_controller']) {
            return self::denial('AUTHORIZATION_DENIED', 'CONCURRENCY_LIMIT');
        }

        return ['decision' => 'OBSERVATION_SCOPE_ALLOWED', 'reason_code' => 'NONE', 'diagnostic' => 'NONE'];
    }

    public static function denial(string $reason, string $diagnostic): array
    {
        return ['decision' => 'DENIED', 'reason_code' => $reason, 'diagnostic' => $diagnostic];
    }
}
