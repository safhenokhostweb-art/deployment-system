<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;
use stdClass;
use WeakMap;

/** Closed, immutable fixture policy. This does not verify operational policy provenance. */
final class PolicySnapshot
{
    public const OPERATIONS = ['OBSERVE_FILESYSTEM', 'OBSERVE_FREESPACE', 'OBSERVE_SERVICE_STATUS'];

    private static ?WeakMap $authorities = null;

    private static ?WeakMap $contexts = null;

    private function __construct(private readonly object $authority, private readonly array $data) {}

    /** Explicit trust root for a closed synthetic harness, never an authentication API. */
    public static function newFixtureAuthority(): object
    {
        $authority = new stdClass;
        self::$authorities ??= new WeakMap;
        self::$authorities[$authority] = true;

        return $authority;
    }

    public static function isFixtureAuthority(object $authority): bool
    {
        return self::$authorities !== null && isset(self::$authorities[$authority]);
    }

    public static function newFixtureContext(object $authority, array $identity): object
    {
        self::requireAuthority($authority);
        $identity = self::copy($identity);
        self::fields($identity, ['application_id', 'credential_id', 'credential_epoch', 'authorization_epoch']);
        self::identifier($identity['application_id']);
        self::identifier($identity['credential_id']);
        self::integer($identity['credential_epoch'], 1);
        self::integer($identity['authorization_epoch'], 1);
        $context = new stdClass;
        self::$contexts ??= new WeakMap;
        self::$contexts[$context] = ['authority' => $authority, 'identity' => $identity];

        return $context;
    }

    public static function fixtureIdentity(object $authority, object $context): ?array
    {
        $entry = self::$contexts === null ? null : (self::$contexts[$context] ?? null);

        return self::isFixtureAuthority($authority) && $entry !== null && $entry['authority'] === $authority
            ? $entry['identity'] : null;
    }

    public static function fromTrustedFixture(object $authority, array $input): self
    {
        self::requireAuthority($authority);
        $data = self::copy($input);
        self::fields($data, ['policy_schema_version', 'policy_version', 'policy_digest', 'valid_from', 'valid_until',
            'operation_allowlist', 'root_registry', 'service_registry', 'credential_grants',
            'worker_timeout_ms', 'cleanup_budget_ms', 'receipt_ttl_ms', 'resource_limits']);
        if ($data['policy_schema_version'] !== 1) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        self::integer($data['policy_version'], 1);
        self::hex($data['policy_digest']);
        self::interval($data['valid_from'], $data['valid_until']);
        self::allowlist($data['operation_allowlist'], true);
        foreach (['root_registry', 'service_registry'] as $registry) {
            if (! is_array($data[$registry])) {
                throw new InvalidArgumentException('INVALID_POLICY');
            }
            foreach ($data[$registry] as $id => $record) {
                self::identifier($id);
                self::fields($record, ['resource_generation', 'identity_digest']);
                self::integer($record['resource_generation'], 1);
                self::hex($record['identity_digest']);
            }
        }
        self::integer($data['worker_timeout_ms'], 1, 5000);
        self::integer($data['cleanup_budget_ms'], 100, 1000);
        if ($data['cleanup_budget_ms'] >= $data['worker_timeout_ms']) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        self::integer($data['receipt_ttl_ms'], 1, 10000);
        $limits = $data['resource_limits'];
        self::fields($limits, ['workers_per_credential', 'workers_per_controller', 'worker_process_count',
            'worker_output_bytes', 'worker_memory_bytes', 'worker_cpu_ms',
            'worker_network_allowed', 'worker_children_allowed']);
        self::integer($limits['workers_per_credential'], 1, 1);
        self::integer($limits['workers_per_controller'], 1, 4);
        self::integer($limits['worker_process_count'], 1, 1);
        self::integer($limits['worker_output_bytes'], 1, 16384);
        self::integer($limits['worker_memory_bytes'], 1, 134217728);
        self::integer($limits['worker_cpu_ms'], 1, 1000);
        if ($limits['worker_network_allowed'] !== false || $limits['worker_children_allowed'] !== false
            || ! is_array($data['credential_grants'])) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        foreach ($data['credential_grants'] as $id => $grant) {
            self::identifier($id);
            self::fields($grant, self::grantFields());
            if ($grant['credential_id'] !== $id) {
                throw new InvalidArgumentException('INVALID_POLICY');
            }
            self::identifier($grant['credential_id']);
            self::identifier($grant['application_id']);
            self::integer($grant['credential_epoch'], 1);
            self::integer($grant['authorization_epoch'], 1);
            self::integer($grant['max_timeout_ms'], 1, 5000);
            self::integer($grant['max_concurrent_requests'], 1);
            self::interval($grant['valid_from'], $grant['valid_until']);
            if (! is_bool($grant['revoked'])) {
                throw new InvalidArgumentException('INVALID_POLICY');
            }
            self::allowlist($grant['allowed_operations'], true);
            foreach (['allowed_root_ids' => 'root_registry', 'allowed_service_ids' => 'service_registry'] as $field => $registry) {
                self::allowlist($grant[$field]);
                foreach ($grant[$field] as $resource) {
                    if (! array_key_exists($resource, $data[$registry])) {
                        throw new InvalidArgumentException('INVALID_POLICY');
                    }
                }
            }
        }

        $snapshot = new self($authority, $data);
        try {
            foreach (array_keys($data['credential_grants']) as $id) {
                $snapshot->scopeDigest($id);
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('INVALID_POLICY/SCOPE_RECORD_INVALID', previous: $exception);
        }

        return $snapshot;
    }

    public static function grantFields(): array
    {
        return ['allowed_operations', 'allowed_root_ids', 'allowed_service_ids', 'application_id',
            'authorization_epoch', 'credential_epoch', 'credential_id', 'max_concurrent_requests',
            'max_timeout_ms', 'revoked', 'valid_from', 'valid_until'];
    }

    public function data(): array
    {
        return $this->data;
    }

    public function belongsTo(object $authority): bool
    {
        return $this->authority === $authority && self::isFixtureAuthority($authority);
    }

    public function contextIdentity(object $context): ?array
    {
        return self::fixtureIdentity($this->authority, $context);
    }

    public function validAt(string $now): bool
    {
        self::timestamp($now);

        return strcmp($now, $this->data['valid_from']) >= 0 && strcmp($now, $this->data['valid_until']) < 0;
    }

    public function effectiveGrant(string $credentialId): ?array
    {
        $grant = $this->data['credential_grants'][$credentialId] ?? null;
        if ($grant === null) {
            return null;
        }
        $grant['allowed_operations'] = array_values(array_intersect($grant['allowed_operations'], $this->data['operation_allowlist']));
        foreach (['allowed_root_ids' => 'root_registry', 'allowed_service_ids' => 'service_registry'] as $field => $registry) {
            $grant[$field] = array_values(array_intersect($grant[$field], array_keys($this->data[$registry])));
        }
        foreach (['allowed_operations', 'allowed_root_ids', 'allowed_service_ids'] as $field) {
            sort($grant[$field], SORT_STRING);
        }
        $grant['max_timeout_ms'] = min($grant['max_timeout_ms'], $this->data['worker_timeout_ms'], 5000);
        $grant['max_concurrent_requests'] = min($grant['max_concurrent_requests'],
            $this->data['resource_limits']['workers_per_credential'], $this->data['resource_limits']['workers_per_controller']);

        return $grant;
    }

    public function scopeDigest(string $credentialId): ?string
    {
        $grant = $this->effectiveGrant($credentialId);

        return $grant === null ? null : hash('sha256', (new CanonicalJsonCodec)->encode((object) $grant));
    }

    public static function fields(mixed $record, array $expected): void
    {
        if (! is_array($record)) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        $actual = array_keys($record);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    public static function integer(mixed $value, int $minimum = 0, int $maximum = 2147483647): void
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    public static function identifier(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    public static function hex(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    public static function timestamp(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9]{13}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    public static function copy(mixed $value, int $depth = 0): mixed
    {
        $remainingNodes = 16384;

        return self::copyBounded($value, $depth, $remainingNodes);
    }

    private static function copyBounded(mixed $value, int $depth, int &$remainingNodes): mixed
    {
        if (--$remainingNodes < 0 || $depth > 6 || (is_array($value) && count($value) > $remainingNodes)) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copyBounded($child, $depth + 1, $remainingNodes);
            }

            return $copy;
        }
        if (is_string($value)) {
            if (strlen($value) > 16384 || preg_match('/[\x00-\x1f\x80-\xff"\\\\]/', $value) === 1) {
                throw new InvalidArgumentException('INVALID_POLICY');
            }

            return $value;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        throw new InvalidArgumentException('INVALID_POLICY');
    }

    private static function interval(mixed $from, mixed $until): void
    {
        self::timestamp($from);
        self::timestamp($until);
        if (strcmp($from, $until) >= 0) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
    }

    private static function allowlist(mixed $list, bool $operations = false): void
    {
        if (! is_array($list) || ! array_is_list($list)) {
            throw new InvalidArgumentException('INVALID_POLICY');
        }
        $previous = null;
        foreach ($list as $id) {
            if ($operations) {
                if (! is_string($id) || ! in_array($id, self::OPERATIONS, true)) {
                    throw new InvalidArgumentException('INVALID_POLICY');
                }
            } else {
                self::identifier($id);
            }
            if ($previous !== null && strcmp($previous, $id) >= 0) {
                throw new InvalidArgumentException('INVALID_POLICY');
            }
            $previous = $id;
        }
    }

    private static function requireAuthority(object $authority): void
    {
        if (! self::isFixtureAuthority($authority)) {
            throw new InvalidArgumentException('AUTHENTICATION_FAILED/FIXTURE_PROVENANCE_INVALID');
        }
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
