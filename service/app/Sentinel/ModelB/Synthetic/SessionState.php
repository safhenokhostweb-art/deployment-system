<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;

/** Immutable view; constructing a view never registers a session or authorizes admission. */
final class SessionState
{
    public const STATUSES = ['CREATED', 'ACTIVE', 'INVALIDATED', 'EXPIRED', 'CLOSED'];

    private readonly array $data;

    public function __construct(private readonly object $authority, private readonly object $context, array $data)
    {
        $identity = PolicySnapshot::fixtureIdentity($authority, $context);
        if ($identity === null) {
            throw new InvalidArgumentException('AUTHENTICATION_FAILED/FIXTURE_PROVENANCE_INVALID');
        }
        $data = PolicySnapshot::copy($data);
        PolicySnapshot::fields($data, ['session_id', 'client_nonce', 'controller_instance_id', 'credential_id',
            'application_id', 'credential_epoch', 'authorization_epoch', 'policy_version', 'policy_digest',
            'scope_digest', 'issued_at', 'expires_at', 'created_monotonic', 'expiry_monotonic', 'status',
            'next_sequence', 'admitted_count', 'accepted_request_ids', 'outstanding_request', 'invalidation_reason']);
        foreach (['session_id', 'client_nonce', 'controller_instance_id', 'policy_digest', 'scope_digest'] as $field) {
            PolicySnapshot::hex($data[$field]);
        }
        foreach ($identity as $field => $value) {
            if ($data[$field] !== $value) {
                throw new InvalidArgumentException('AUTHENTICATION_FAILED/IDENTITY_MISMATCH');
            }
        }
        PolicySnapshot::integer($data['policy_version'], 1);
        PolicySnapshot::timestamp($data['issued_at']);
        PolicySnapshot::timestamp($data['expires_at']);
        PolicySnapshot::integer($data['created_monotonic']);
        PolicySnapshot::integer($data['expiry_monotonic']);
        $lifetime = $data['expiry_monotonic'] - $data['created_monotonic'];
        if ($lifetime < 1 || $lifetime > 60000
            || (int) $data['expires_at'] - (int) $data['issued_at'] !== $lifetime
            || ! in_array($data['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('SESSION_STATE_INVALID');
        }
        PolicySnapshot::integer($data['admitted_count'], 0, 1024);
        PolicySnapshot::integer($data['next_sequence'], 1, 1025);
        if (! is_array($data['accepted_request_ids']) || ! array_is_list($data['accepted_request_ids'])
            || count($data['accepted_request_ids']) !== $data['admitted_count']
            || $data['next_sequence'] !== $data['admitted_count'] + 1) {
            throw new InvalidArgumentException('SESSION_STATE_INVALID');
        }
        $seen = [];
        foreach ($data['accepted_request_ids'] as $id) {
            PolicySnapshot::hex($id);
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('SESSION_STATE_INVALID');
            }
            $seen[$id] = true;
        }
        if ($data['invalidation_reason'] !== null && ! is_string($data['invalidation_reason'])) {
            throw new InvalidArgumentException('SESSION_STATE_INVALID');
        }
        if ($data['outstanding_request'] !== null && ! is_array($data['outstanding_request'])) {
            throw new InvalidArgumentException('SESSION_STATE_INVALID');
        }
        $this->data = $data;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function matchesContext(object $context, PolicySnapshot $policy): bool
    {
        return $this->context === $context && $policy->belongsTo($this->authority)
            && PolicySnapshot::fixtureIdentity($this->authority, $context) !== null;
    }

    public function expiredAt(int $monotonic): bool
    {
        return $monotonic >= $this->data['expiry_monotonic'];
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
