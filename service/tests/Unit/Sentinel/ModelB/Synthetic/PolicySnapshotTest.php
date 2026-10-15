<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\CanonicalJsonCodec;
use App\Sentinel\ModelB\Synthetic\PolicySnapshot;
use App\Sentinel\ModelB\Synthetic\SessionAdmissionCoordinator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PolicySnapshotTest extends TestCase
{
    public static function policyData(): array
    {
        return ['policy_schema_version' => 1, 'policy_version' => 1, 'policy_digest' => str_repeat('a', 64),
            'valid_from' => '1700000000000', 'valid_until' => '1700000100000',
            'operation_allowlist' => PolicySnapshot::OPERATIONS,
            'root_registry' => ['root_a' => ['resource_generation' => 1, 'identity_digest' => str_repeat('b', 64)],
                'root_b' => ['resource_generation' => 2, 'identity_digest' => str_repeat('c', 64)]],
            'service_registry' => ['service_a' => ['resource_generation' => 1, 'identity_digest' => str_repeat('d', 64)],
                'service_b' => ['resource_generation' => 1, 'identity_digest' => str_repeat('e', 64)]],
            'credential_grants' => ['cred_a' => ['allowed_operations' => PolicySnapshot::OPERATIONS,
                'allowed_root_ids' => ['root_a'], 'allowed_service_ids' => ['service_a'],
                'application_id' => 'app_a', 'authorization_epoch' => 1, 'credential_epoch' => 1,
                'credential_id' => 'cred_a', 'max_concurrent_requests' => 2, 'max_timeout_ms' => 5000,
                'revoked' => false, 'valid_from' => '1700000000000', 'valid_until' => '1700000100000']],
            'worker_timeout_ms' => 4000, 'cleanup_budget_ms' => 1000, 'receipt_ttl_ms' => 10000,
            'resource_limits' => ['workers_per_credential' => 1, 'workers_per_controller' => 4,
                'worker_process_count' => 1, 'worker_output_bytes' => 16384,
                'worker_memory_bytes' => 134217728, 'worker_cpu_ms' => 1000,
                'worker_network_allowed' => false, 'worker_children_allowed' => false]];
    }

    public static function harness(?array $data = null): array
    {
        $authority = PolicySnapshot::newFixtureAuthority();
        $policy = PolicySnapshot::fromTrustedFixture($authority, $data ?? self::policyData());
        $context = PolicySnapshot::newFixtureContext($authority, ['application_id' => 'app_a', 'credential_id' => 'cred_a',
            'credential_epoch' => 1, 'authorization_epoch' => 1]);
        $coordinator = new SessionAdmissionCoordinator($authority, $policy, str_repeat('f', 64), '1700000000000');

        return [$authority, $policy, $context, $coordinator];
    }

    public static function request(string $sessionId, int $sequence = 1, ?string $requestId = null, array $changes = []): string
    {
        $message = (object) array_merge(['message_type' => 'OBSERVE', 'protocol_version' => 1,
            'request_id' => $requestId ?? str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT),
            'session_id' => $sessionId, 'sequence' => $sequence, 'policy_version' => 1,
            'policy_digest' => str_repeat('a', 64), 'operation' => 'OBSERVE_FILESYSTEM',
            'target' => (object) ['root_id' => 'root_a'], 'timeout_ms' => 4000], $changes);

        return (new CanonicalJsonCodec)->encode($message);
    }

    public function test_effective_grant_has_exactly_twelve_fields_and_independent_canonical_digest(): void
    {
        [, $policy] = self::harness();
        $grant = $policy->effectiveGrant('cred_a');
        self::assertSame(PolicySnapshot::grantFields(), array_keys($grant));
        self::assertCount(12, $grant);
        self::assertSame(4000, $grant['max_timeout_ms']);
        self::assertSame(1, $grant['max_concurrent_requests']);
        $canonical = '{"allowed_operations":["OBSERVE_FILESYSTEM","OBSERVE_FREESPACE","OBSERVE_SERVICE_STATUS"],'
            .'"allowed_root_ids":["root_a"],"allowed_service_ids":["service_a"],"application_id":"app_a",'
            .'"authorization_epoch":1,"credential_epoch":1,"credential_id":"cred_a","max_concurrent_requests":1,'
            .'"max_timeout_ms":4000,"revoked":false,"valid_from":"1700000000000","valid_until":"1700000100000"}';
        self::assertSame(hash('sha256', $canonical), $policy->scopeDigest('cred_a'));
        self::assertNull($policy->scopeDigest('unknown'));
    }

    public function test_digest_is_independent_of_map_insertion_order_and_policy_version(): void
    {
        [$authority, $policy] = self::harness();
        $data = array_reverse(self::policyData(), true);
        $data['credential_grants']['cred_a'] = array_reverse($data['credential_grants']['cred_a'], true);
        $data['root_registry'] = array_reverse($data['root_registry'], true);
        $data['policy_version'] = 2;
        $data['policy_digest'] = str_repeat('9', 64);
        $other = PolicySnapshot::fromTrustedFixture($authority, $data);
        self::assertSame($policy->scopeDigest('cred_a'), $other->scopeDigest('cred_a'));
    }

    public function test_effective_intersection_and_revocation_are_explicit(): void
    {
        $data = self::policyData();
        $data['operation_allowlist'] = ['OBSERVE_FILESYSTEM'];
        $data['credential_grants']['cred_a']['revoked'] = true;
        [, $policy] = self::harness($data);
        self::assertSame(['OBSERVE_FILESYSTEM'], $policy->effectiveGrant('cred_a')['allowed_operations']);
        self::assertTrue($policy->effectiveGrant('cred_a')['revoked']);
    }

    public function test_input_references_and_returned_arrays_cannot_mutate_snapshot(): void
    {
        $authority = PolicySnapshot::newFixtureAuthority();
        $data = self::policyData();
        $version = 1;
        $data['policy_version'] = &$version;
        $root = 'root_a';
        $data['credential_grants']['cred_a']['allowed_root_ids'][0] = &$root;
        $policy = PolicySnapshot::fromTrustedFixture($authority, $data);
        $version = 99;
        $root = 'root_b';
        $returned = $policy->data();
        $returned['credential_grants']['cred_a']['revoked'] = true;
        self::assertSame(1, $policy->data()['policy_version']);
        self::assertSame(['root_a'], $policy->effectiveGrant('cred_a')['allowed_root_ids']);
        self::assertFalse($policy->effectiveGrant('cred_a')['revoked']);
    }

    #[DataProvider('untrustedAuthorities')]
    public function test_forged_cloned_and_deserialized_authorities_are_not_trusted(object $authority): void
    {
        $this->expectException(InvalidArgumentException::class);
        PolicySnapshot::fromTrustedFixture($authority, self::policyData());
    }

    public static function untrustedAuthorities(): iterable
    {
        $authority = PolicySnapshot::newFixtureAuthority();
        yield 'arbitrary' => [new stdClass];
        yield 'trust-flag' => [(object) ['trusted' => true]];
        yield 'clone' => [clone $authority];
        yield 'deserialize' => [unserialize(serialize($authority), ['allowed_classes' => [stdClass::class]])];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_closed_policy_is_rejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        PolicySnapshot::fromTrustedFixture(PolicySnapshot::newFixtureAuthority(), $data);
    }

    public static function invalidPolicies(): iterable
    {
        $vectors = [
            [['policy_version'], 0], [['policy_version'], '1'], [['policy_version'], true],
            [['policy_version'], 2147483648], [['policy_digest'], str_repeat('A', 64)],
            [['policy_digest'], str_repeat('a', 63)], [['policy_schema_version'], 2],
            [['valid_from'], 1700000000000], [['valid_until'], '1700000000000'],
            [['operation_allowlist'], ['OBSERVE_FILESYSTEM', 'OBSERVE_FILESYSTEM']],
            [['operation_allowlist'], ['OBSERVE_FREESPACE', 'OBSERVE_FILESYSTEM']],
            [['operation_allowlist'], ['VM_START']], [['worker_timeout_ms'], 5001],
            [['cleanup_budget_ms'], 4000], [['receipt_ttl_ms'], 10001],
            [['resource_limits', 'workers_per_credential'], 2],
            [['resource_limits', 'workers_per_controller'], 5],
            [['resource_limits', 'worker_network_allowed'], true],
            [['resource_limits', 'worker_children_allowed'], 0],
            [['root_registry', 'root_a', 'resource_generation'], 0],
            [['root_registry', 'root_a', 'identity_digest'], 'bad'],
            [['root_registry', '/tmp/root'], ['resource_generation' => 1, 'identity_digest' => str_repeat('a', 64)]],
            [['credential_grants', 'cred_a', 'allowed_root_ids'], ['root_a', 'root_a']],
            [['credential_grants', 'cred_a', 'allowed_root_ids'], ['unknown']],
            [['credential_grants', 'cred_a', 'allowed_service_ids'], ['root_a']],
            [['credential_grants', 'cred_a', 'credential_id'], 'cred_other'],
            [['credential_grants', 'cred_a', 'credential_epoch'], 0],
            [['credential_grants', 'cred_a', 'authorization_epoch'], '1'],
            [['credential_grants', 'cred_a', 'max_concurrent_requests'], 0],
            [['credential_grants', 'cred_a', 'max_timeout_ms'], 1.5],
            [['credential_grants', 'cred_a', 'revoked'], 1],
            [['credential_grants', 'cred_a', 'thirteenth_field'], 'forbidden'],
            [['unexpected'], true],
        ];
        foreach ($vectors as $index => [$path, $value]) {
            $data = self::policyData();
            $cursor = &$data;
            foreach ($path as $part) {
                $cursor = &$cursor[$part];
            }
            $cursor = $value;
            unset($cursor);
            yield 'invalid-'.$index => [$data];
        }
        foreach (array_keys(self::policyData()) as $field) {
            $data = self::policyData();
            unset($data[$field]);
            yield 'missing-'.$field => [$data];
        }
        foreach (PolicySnapshot::grantFields() as $field) {
            $data = self::policyData();
            unset($data['credential_grants']['cred_a'][$field]);
            yield 'grant-missing-'.$field => [$data];
        }
    }

    public function test_policy_validity_is_half_open(): void
    {
        [, $policy] = self::harness();
        self::assertFalse($policy->validAt('1699999999999'));
        self::assertTrue($policy->validAt('1700000000000'));
        self::assertTrue($policy->validAt('1700000099999'));
        self::assertFalse($policy->validAt('1700000100000'));
    }

    public function test_policy_serialization_is_forbidden(): void
    {
        [, $policy] = self::harness();
        $this->expectException(InvalidArgumentException::class);
        serialize($policy);
    }
}
