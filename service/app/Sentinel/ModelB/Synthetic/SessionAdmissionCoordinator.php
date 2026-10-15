<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;

/** Sole mutable owner in a synchronous fixture model; no native atomicity/cleanup claim. */
final class SessionAdmissionCoordinator
{
    private array $state;

    private bool $mutating = false;

    public function __construct(private readonly object $authority, PolicySnapshot $policy,
        string $instanceId, string $wallTime, int $monotonic = 0)
    {
        if (! $policy->belongsTo($authority)) {
            throw new InvalidArgumentException('AUTHENTICATION_FAILED/FIXTURE_PROVENANCE_INVALID');
        }
        PolicySnapshot::hex($instanceId);
        PolicySnapshot::timestamp($wallTime);
        PolicySnapshot::integer($monotonic);
        if (! $policy->validAt($wallTime)) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        $this->state = ['policy' => $policy, 'instance' => $instanceId, 'wall' => $wallTime, 'mono' => $monotonic,
            'sessions' => [], 'reservations' => [], 'revoked' => [], 'blocked' => null];
    }

    public function createSession(object $context, string $sessionId, string $nonce, int $lifetimeMs = 60000): SessionState
    {
        $result = $this->change('create', [$context, $sessionId, $nonce, $lifetimeMs]);
        if ($result['decision'] === 'DENIED') {
            throw new InvalidArgumentException($result['reason_code'].'/'.$result['diagnostic']);
        }

        return $this->session($sessionId);
    }

    public function activate(string $sessionId, object $context): array
    {
        return $this->change('activate', [$sessionId, $context]);
    }

    public function admit(string $payload, object $context): array
    {
        return $this->change('admit', [$payload, $context]);
    }

    /** These lifecycle events are trusted fixture inputs, not operational callbacks. */
    public function complete(string $sessionId, string $requestId, bool $success, bool $cleanupCertain = true): array
    {
        return $this->change('complete', [$sessionId, $requestId, $success, $cleanupCertain]);
    }

    public function disconnect(string $sessionId): array
    {
        return $this->change('disconnect', [$sessionId]);
    }

    public function responseLost(string $sessionId): array
    {
        return $this->change('lost', [$sessionId]);
    }

    public function replacePolicy(PolicySnapshot $policy): array
    {
        return $this->change('policy', [$policy]);
    }

    public function revokeCredential(string $credentialId): array
    {
        return $this->change('revoke', [$credentialId]);
    }

    public function restart(string $instanceId): array
    {
        return $this->change('restart', [$instanceId]);
    }

    public function advanceClock(int $milliseconds): array
    {
        return $this->change('clock', [$milliseconds]);
    }

    public function reconcileFixtureCleanup(): array
    {
        return $this->change('reconcile', []);
    }

    public function session(string $sessionId): SessionState
    {
        $entry = $this->state['sessions'][$sessionId] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException('SESSION_UNKNOWN');
        }

        return new SessionState($this->authority, $entry['context'], $entry['data']);
    }

    public function policy(): PolicySnapshot
    {
        return $this->state['policy'];
    }

    public function clock(): array
    {
        return ['wall' => $this->state['wall'], 'monotonic' => $this->state['mono']];
    }

    public function concurrency(string $sessionId): array
    {
        return $this->counters($this->state, $sessionId);
    }

    private function counters(array $state, string $sessionId): array
    {
        $credential = $state['sessions'][$sessionId]['data']['credential_id'] ?? null;
        $credentialCount = 0;
        foreach ($state['reservations'] as $reservedCredential) {
            if ($reservedCredential === $credential) {
                $credentialCount++;
            }
        }

        return ['credential' => $credentialCount,
            'controller' => count($state['reservations']), 'revoked' => isset($state['revoked'][$credential ?? '']),
            'blocked' => $state['blocked'] !== null, 'controller_instance_id' => $state['instance']];
    }

    /** No external callbacks, yields, or partially published candidate mutations. */
    private function change(string $action, array $arguments): array
    {
        if ($this->mutating) {
            return AuthorizationEvaluator::denial('CONTROLLER_UNAVAILABLE', 'REENTRANT_MUTATION');
        }
        $this->mutating = true;
        try {
            $candidate = $this->state;
            $result = match ($action) {
                'create' => $this->createCandidate($candidate, ...$arguments),
                'activate' => $this->activateCandidate($candidate, ...$arguments),
                'admit' => $this->admitCandidate($candidate, ...$arguments),
                'complete' => $this->completeCandidate($candidate, ...$arguments),
                'disconnect', 'lost' => $this->closeCandidate($candidate, $arguments[0], $action === 'lost' ? 'RESPONSE_LOST' : 'DISCONNECTED'),
                'policy' => $this->policyCandidate($candidate, ...$arguments),
                'revoke' => $this->revokeCandidate($candidate, ...$arguments),
                'restart' => $this->restartCandidate($candidate, ...$arguments),
                'clock' => $this->clockCandidate($candidate, ...$arguments),
                'reconcile' => $this->reconcileCandidate($candidate),
                default => throw new InvalidArgumentException('STATE_UNCERTAIN'),
            };
            // Validate every candidate view before the single aggregate assignment.
            foreach ($candidate['sessions'] as $entry) {
                new SessionState($this->authority, $entry['context'], $entry['data']);
            }
            $this->state = $candidate;

            return $result;
        } finally {
            $this->mutating = false;
        }
    }

    private static function result(string $decision): array
    {
        return ['decision' => $decision, 'reason_code' => 'NONE', 'diagnostic' => 'NONE'];
    }

    private function createCandidate(array &$state, object $context, string $sessionId, string $nonce, int $lifetime): array
    {
        $identity = PolicySnapshot::fixtureIdentity($this->authority, $context);
        if ($identity === null) {
            return AuthorizationEvaluator::denial('AUTHENTICATION_FAILED', 'FIXTURE_PROVENANCE_INVALID');
        }
        PolicySnapshot::hex($sessionId);
        PolicySnapshot::hex($nonce);
        PolicySnapshot::integer($lifetime, 1, 60000);
        if (isset($state['sessions'][$sessionId]) || $state['blocked'] !== null) {
            return AuthorizationEvaluator::denial('CONTROLLER_UNAVAILABLE', 'SESSION_DUPLICATE_OR_BLOCKED');
        }
        $policy = $state['policy'];
        $grant = $policy->effectiveGrant($identity['credential_id']);
        if ($grant === null || ! $policy->validAt($state['wall'])) {
            return AuthorizationEvaluator::denial('INVALID_POLICY', 'GRANT_OR_POLICY_UNAVAILABLE');
        }
        $data = $policy->data();
        $session = array_merge($identity, ['session_id' => $sessionId, 'client_nonce' => $nonce,
            'controller_instance_id' => $state['instance'], 'policy_version' => $data['policy_version'],
            'policy_digest' => $data['policy_digest'], 'scope_digest' => $policy->scopeDigest($identity['credential_id']),
            'issued_at' => $state['wall'], 'expires_at' => $this->addWall($state['wall'], $lifetime),
            'created_monotonic' => $state['mono'], 'expiry_monotonic' => $state['mono'] + $lifetime,
            'status' => 'CREATED', 'next_sequence' => 1, 'admitted_count' => 0,
            'accepted_request_ids' => [], 'outstanding_request' => null, 'invalidation_reason' => null]);
        $state['sessions'][$sessionId] = ['context' => $context, 'data' => $session];

        return self::result('SESSION_CREATED');
    }

    private function activateCandidate(array &$state, string $id, object $context): array
    {
        $entry = $state['sessions'][$id] ?? null;
        if ($entry === null || $entry['context'] !== $context || PolicySnapshot::fixtureIdentity($this->authority, $context) === null) {
            return AuthorizationEvaluator::denial('AUTHENTICATION_FAILED', 'TRANSPORT_CONTEXT_MISMATCH');
        }
        $session = $entry['data'];
        if ($session['status'] !== 'CREATED' || $state['blocked'] !== null) {
            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'SESSION_NOT_CREATED');
        }
        if ($state['mono'] >= $session['expiry_monotonic']) {
            $this->terminal($state, $id, 'EXPIRED', 'SESSION_EXPIRED');

            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'SESSION_EXPIRED');
        }
        $grant = $state['policy']->effectiveGrant($session['credential_id']);
        $valid = $grant !== null && ! $grant['revoked'] && ! isset($state['revoked'][$session['credential_id']])
            && $state['policy']->validAt($state['wall'])
            && strcmp($state['wall'], $grant['valid_from']) >= 0 && strcmp($state['wall'], $grant['valid_until']) < 0;
        foreach (['application_id', 'credential_id', 'credential_epoch', 'authorization_epoch'] as $field) {
            $valid = $valid && $grant[$field] === $session[$field];
        }
        $rootScope = $grant !== null && $grant['allowed_root_ids'] !== []
            && array_intersect($grant['allowed_operations'], ['OBSERVE_FILESYSTEM', 'OBSERVE_FREESPACE']) !== [];
        $serviceScope = $grant !== null && $grant['allowed_service_ids'] !== []
            && in_array('OBSERVE_SERVICE_STATUS', $grant['allowed_operations'], true);
        if (! $valid || (! $rootScope && ! $serviceScope)) {
            $this->terminal($state, $id, 'INVALIDATED', 'ACTIVATION_DENIED');

            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'ACTIVATION_DENIED');
        }
        $state['sessions'][$id]['data']['status'] = 'ACTIVE';

        return self::result('SESSION_ACTIVE');
    }

    private function admitCandidate(array &$state, string $payload, object $context): array
    {
        try {
            $request = (new ProtocolValidator)->validate($payload);
        } catch (InvalidArgumentException $exception) {
            return AuthorizationEvaluator::denial($exception->getMessage() === 'UNSUPPORTED_PROTOCOL' ? 'UNSUPPORTED_PROTOCOL' : 'MALFORMED_REQUEST', 'SCHEMA_INVALID');
        }
        if ($request->message_type !== 'OBSERVE') {
            return AuthorizationEvaluator::denial('MALFORMED_REQUEST', 'OBSERVE_REQUIRED');
        }
        $id = $request->session_id;
        $entry = $state['sessions'][$id] ?? null;
        if ($entry === null || $entry['context'] !== $context || PolicySnapshot::fixtureIdentity($this->authority, $context) === null) {
            return AuthorizationEvaluator::denial('AUTHENTICATION_FAILED', 'TRANSPORT_CONTEXT_MISMATCH');
        }
        $session = $entry['data'];
        if ($state['blocked'] !== null) {
            return AuthorizationEvaluator::denial($state['blocked'], 'ADMISSION_BLOCKED');
        }
        if ($session['status'] !== 'ACTIVE') {
            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'SESSION_INACTIVE');
        }
        if ($state['mono'] >= $session['expiry_monotonic']) {
            $this->terminal($state, $id, 'EXPIRED', 'SESSION_EXPIRED');

            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'SESSION_EXPIRED');
        }
        $policyData = $state['policy']->data();
        $grant = $state['policy']->effectiveGrant($session['credential_id']);
        if (! $state['policy']->validAt($state['wall']) || $request->policy_version !== $session['policy_version']
            || $request->policy_digest !== $session['policy_digest'] || $policyData['policy_version'] !== $session['policy_version']
            || $policyData['policy_digest'] !== $session['policy_digest']) {
            $this->terminal($state, $id, 'INVALIDATED', 'POLICY_BINDING_MISMATCH');

            return AuthorizationEvaluator::denial('INVALID_POLICY', 'POLICY_BINDING_MISMATCH');
        }
        if ($grant === null || $grant['revoked'] || isset($state['revoked'][$session['credential_id']])
            || $grant['credential_epoch'] !== $session['credential_epoch'] || $grant['authorization_epoch'] !== $session['authorization_epoch']) {
            $this->terminal($state, $id, 'INVALIDATED', 'CREDENTIAL_OR_EPOCH_INVALID');

            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'CREDENTIAL_OR_EPOCH_INVALID');
        }
        if ($request->sequence !== $session['next_sequence'] || in_array($request->request_id, $session['accepted_request_ids'], true)) {
            $this->terminal($state, $id, 'CLOSED', 'REPLAY_DETECTED');

            return AuthorizationEvaluator::denial('REPLAY_DETECTED', 'SEQUENCE_OR_REQUEST_ID');
        }
        if ($session['admitted_count'] >= 1024) {
            $this->terminal($state, $id, 'CLOSED', 'REQUEST_LIMIT');

            return AuthorizationEvaluator::denial('AUTHORIZATION_DENIED', 'REQUEST_LIMIT');
        }
        $decision = (new AuthorizationEvaluator)->evaluate($payload,
            new SessionState($this->authority, $context, $session), $context, $state['policy'],
            $state['wall'], $state['mono'], $this->counters($state, $id));
        if ($decision['decision'] !== 'OBSERVATION_SCOPE_ALLOWED') {
            if ($decision['diagnostic'] === 'GRANT_EXPIRED_OR_NOT_YET_VALID') {
                $this->terminal($state, $id, 'INVALIDATED', 'GRANT_EXPIRED_OR_NOT_YET_VALID');
            }
            return $decision;
        }
        $root = $request->operation !== 'OBSERVE_SERVICE_STATUS';
        $target = $root ? $request->target->root_id : $request->target->service_id;
        $resource = $policyData[$root ? 'root_registry' : 'service_registry'][$target];
        $next = $session;
        $next['accepted_request_ids'][] = $request->request_id;
        $next['next_sequence']++;
        $next['admitted_count']++;
        $next['outstanding_request'] = ['request_id' => $request->request_id, 'sequence' => $request->sequence,
            'request_digest' => hash('sha256', $payload), 'operation' => $request->operation,
            'target_kind' => $root ? 'root_id' : 'service_id', 'target_id' => $target,
            'policy_version' => $session['policy_version'], 'policy_digest' => $session['policy_digest'],
            'resource_generation' => $resource['resource_generation'], 'resource_identity_digest' => $resource['identity_digest'],
            'timeout_ms' => $request->timeout_ms, 'admitted_at_monotonic' => $state['mono'],
            'deadline_monotonic' => $state['mono'] + $request->timeout_ms, 'outcome_state' => 'PENDING'];
        $state['sessions'][$id]['data'] = $next;
        $state['reservations'][$id] = $session['credential_id'];

        return self::result('OBSERVATION_SCOPE_ALLOWED');
    }

    private function completeCandidate(array &$state, string $id, string $requestId, bool $success, bool $cleanupCertain): array
    {
        $outstanding = $state['sessions'][$id]['data']['outstanding_request'] ?? null;
        if ($outstanding === null || $outstanding['request_id'] !== $requestId || ! isset($state['reservations'][$id])) {
            if ($state['blocked'] !== 'CLEANUP_UNCERTAIN') {
                $state['blocked'] = 'CONTROLLER_UNAVAILABLE';
            }

            return AuthorizationEvaluator::denial($state['blocked'], 'COMPLETION_STATE_UNCERTAIN');
        }
        $this->invalidateExpiredGrant($state, $id);
        if (! $cleanupCertain) {
            $this->terminal($state, $id, 'CLOSED', 'CLEANUP_UNCERTAIN');

            return AuthorizationEvaluator::denial('CLEANUP_UNCERTAIN', 'CLEANUP_UNCERTAIN');
        }
        $wasActive = $state['sessions'][$id]['data']['status'] === 'ACTIVE';
        $state['sessions'][$id]['data']['outstanding_request'] = null;
        unset($state['reservations'][$id]);
        if ($state['sessions'][$id]['data']['admitted_count'] === 1024) {
            $this->terminal($state, $id, 'CLOSED', 'REQUEST_LIMIT');
        }

        return self::result(! $wasActive ? 'SYNTHETIC_RESULT_DISCARDED'
            : ($success ? 'SYNTHETIC_COMPLETED' : 'SYNTHETIC_OBSERVATION_FAILED'));
    }

    /** Only coordinator-owned fixture time and immutable policy determine expiry. */
    private function invalidateExpiredGrant(array &$state, string $id): void
    {
        $grant = $state['policy']->effectiveGrant($state['sessions'][$id]['data']['credential_id']);
        if ($grant !== null && strcmp($state['wall'], $grant['valid_until']) >= 0) {
            $this->terminal($state, $id, 'INVALIDATED', 'GRANT_EXPIRED_OR_NOT_YET_VALID');
        }
    }

    private function terminal(array &$state, string $id, string $status, string $reason): void
    {
        if (in_array($state['sessions'][$id]['data']['status'], ['CREATED', 'ACTIVE'], true)) {
            $state['sessions'][$id]['data']['status'] = $status;
            $state['sessions'][$id]['data']['invalidation_reason'] = $reason;
        }
        if ($state['sessions'][$id]['data']['outstanding_request'] !== null) {
            $state['sessions'][$id]['data']['outstanding_request']['outcome_state'] = 'CLEANUP_UNCERTAIN';
            $state['blocked'] = 'CLEANUP_UNCERTAIN';
        }
    }

    private function closeCandidate(array &$state, string $id, string $reason): array
    {
        if (! isset($state['sessions'][$id])) {
            return AuthorizationEvaluator::denial('CONTROLLER_UNAVAILABLE', 'SESSION_UNKNOWN');
        }
        $this->terminal($state, $id, 'CLOSED', $reason);

        return $state['blocked'] === 'CLEANUP_UNCERTAIN'
            ? AuthorizationEvaluator::denial('CLEANUP_UNCERTAIN', $reason) : self::result('SESSION_CLOSED');
    }

    private function policyCandidate(array &$state, PolicySnapshot $policy): array
    {
        $old = $state['policy']->data();
        $new = $policy->data();
        if (! $policy->belongsTo($this->authority) || ! $policy->validAt($state['wall'])
            || $new['policy_version'] < $old['policy_version']
            || ($new['policy_version'] === $old['policy_version'] && $new['policy_digest'] !== $old['policy_digest'])) {
            return AuthorizationEvaluator::denial('INVALID_POLICY', 'POLICY_DOWNGRADE_OR_CONFLICT');
        }
        if ($new['policy_version'] === $old['policy_version']) {
            return self::result('POLICY_UNCHANGED');
        }
        foreach (array_keys($state['sessions']) as $id) {
            $this->terminal($state, $id, 'INVALIDATED', 'POLICY_REPLACED');
        }
        $state['policy'] = $policy;

        return self::result('POLICY_REPLACED');
    }

    private function revokeCandidate(array &$state, string $credential): array
    {
        PolicySnapshot::identifier($credential);
        $state['revoked'][$credential] = true;
        foreach ($state['sessions'] as $id => $entry) {
            if ($entry['data']['credential_id'] === $credential) {
                $this->terminal($state, $id, 'INVALIDATED', 'CREDENTIAL_REVOKED');
            }
        }

        return self::result('CREDENTIAL_REVOKED');
    }

    private function restartCandidate(array &$state, string $instance): array
    {
        PolicySnapshot::hex($instance);
        if ($instance === $state['instance']) {
            return AuthorizationEvaluator::denial('CONTROLLER_UNAVAILABLE', 'INSTANCE_NOT_CHANGED');
        }
        foreach (array_keys($state['sessions']) as $id) {
            $this->terminal($state, $id, 'INVALIDATED', 'CONTROLLER_RESTARTED');
        }
        $state['instance'] = $instance;
        $state['blocked'] = $state['reservations'] === [] ? 'CONTROLLER_UNAVAILABLE' : 'CLEANUP_UNCERTAIN';

        return self::result('CONTROLLER_RESTARTED');
    }

    private function clockCandidate(array &$state, int $milliseconds): array
    {
        PolicySnapshot::integer($milliseconds);
        PolicySnapshot::integer($state['mono'] + $milliseconds);
        $state['wall'] = $this->addWall($state['wall'], $milliseconds);
        $state['mono'] += $milliseconds;
        foreach ($state['sessions'] as $id => $entry) {
            $outstanding = $entry['data']['outstanding_request'];
            if ($outstanding !== null) {
                $this->invalidateExpiredGrant($state, $id);
            }
            if ($outstanding !== null && $state['mono'] >= $outstanding['deadline_monotonic']) {
                $this->terminal($state, $id, 'CLOSED', 'WORKER_TIMEOUT');
            }
            if ($state['mono'] >= $entry['data']['expiry_monotonic']) {
                $this->terminal($state, $id, 'EXPIRED', 'SESSION_EXPIRED');
            }
            if (! $state['policy']->validAt($state['wall'])) {
                $this->terminal($state, $id, 'INVALIDATED', 'POLICY_EXPIRED');
            }
        }

        return self::result('CLOCK_ADVANCED');
    }

    private function reconcileCandidate(array &$state): array
    {
        if ($state['reservations'] !== []) {
            return AuthorizationEvaluator::denial('CLEANUP_UNCERTAIN', 'RESERVATIONS_REMAIN');
        }
        $state['blocked'] = null;

        return self::result('FIXTURE_RECONCILED');
    }

    private function addWall(string $wall, int $milliseconds): string
    {
        // Fixed 13-digit timestamps fit the explicitly approved x64 PHP integer range.
        $next = (int) $wall + $milliseconds;
        if ($next > 9999999999999) {
            throw new InvalidArgumentException('CLOCK_OVERFLOW');
        }

        return str_pad((string) $next, 13, '0', STR_PAD_LEFT);
    }

    private function __clone() {}

    public function __serialize(): array
    {
        throw new InvalidArgumentException('FIXTURE_SERIALIZATION_FORBIDDEN');
    }

    public function __unserialize(array $data): void
    {
        throw new InvalidArgumentException('FIXTURE_SERIALIZATION_FORBIDDEN');
    }
}
