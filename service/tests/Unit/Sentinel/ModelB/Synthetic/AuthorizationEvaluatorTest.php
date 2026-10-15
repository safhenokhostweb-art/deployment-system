<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\AuthorizationEvaluator;
use App\Sentinel\ModelB\Synthetic\PolicySnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationEvaluatorTest extends TestCase
{
    #[DataProvider('allowedOperations')]
    public function test_allowed_scope_is_a_pure_decision(string $operation, object $target): void
    {
        [, $policy, $context, $owner] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $before = $owner->session($id)->data();
        $payload = PolicySnapshotTest::request($id, changes: ['operation' => $operation, 'target' => $target]);
        $result = (new AuthorizationEvaluator)->evaluate($payload, $owner->session($id), $context, $policy,
            '1700000000000', 0, $owner->concurrency($id));
        self::assertSame(['decision' => 'OBSERVATION_SCOPE_ALLOWED', 'reason_code' => 'NONE', 'diagnostic' => 'NONE'], $result);
        self::assertSame($before, $owner->session($id)->data());
        self::assertSame(0, $owner->concurrency($id)['controller']);
    }

    public static function allowedOperations(): iterable
    {
        yield ['OBSERVE_FILESYSTEM', (object) ['root_id' => 'root_a']];
        yield ['OBSERVE_FREESPACE', (object) ['root_id' => 'root_a']];
        yield ['OBSERVE_SERVICE_STATUS', (object) ['service_id' => 'service_a']];
    }

    #[DataProvider('deniedRequests')]
    public function test_request_scope_denials(array $changes, string $reason, string $diagnostic): void
    {
        [, $policy, $context, $owner] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $result = (new AuthorizationEvaluator)->evaluate(PolicySnapshotTest::request($id, changes: $changes),
            $owner->session($id), $context, $policy, '1700000000000', 0, $owner->concurrency($id));
        self::assertSame('DENIED', $result['decision']);
        self::assertSame($reason, $result['reason_code']);
        self::assertSame($diagnostic, $result['diagnostic']);
        self::assertSame(1, $owner->session($id)->data()['next_sequence']);
    }

    public static function deniedRequests(): iterable
    {
        yield 'root' => [['target' => (object) ['root_id' => 'root_b']], 'AUTHORIZATION_DENIED', 'RESOURCE_SCOPE'];
        yield 'unknown-root' => [['target' => (object) ['root_id' => 'unknown']], 'AUTHORIZATION_DENIED', 'RESOURCE_SCOPE'];
        yield 'service' => [['operation' => 'OBSERVE_SERVICE_STATUS', 'target' => (object) ['service_id' => 'service_b']], 'AUTHORIZATION_DENIED', 'RESOURCE_SCOPE'];
        yield 'timeout-over' => [['timeout_ms' => 4001], 'AUTHORIZATION_DENIED', 'TIMEOUT_LIMIT'];
        yield 'cleanup-budget' => [['timeout_ms' => 1000], 'AUTHORIZATION_DENIED', 'CLEANUP_BUDGET'];
        yield 'version' => [['protocol_version' => 2], 'UNSUPPORTED_PROTOCOL', 'SCHEMA_INVALID'];
        yield 'native-operation' => [['operation' => 'VM_START'], 'MALFORMED_REQUEST', 'SCHEMA_INVALID'];
        yield 'policy' => [['policy_version' => 2], 'INVALID_POLICY', 'POLICY_BINDING_MISMATCH'];
        yield 'session' => [['session_id' => str_repeat('3', 64)], 'AUTHENTICATION_FAILED', 'SESSION_BINDING_MISMATCH'];
    }

    #[DataProvider('changedGrants')]
    public function test_revocation_identity_and_epoch_mismatch(array $changes, string $diagnostic): void
    {
        [$authority, , $context, $owner] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a'] = array_merge($data['credential_grants']['cred_a'], $changes);
        $policy = PolicySnapshot::fromTrustedFixture($authority, $data);
        $result = (new AuthorizationEvaluator)->evaluate(PolicySnapshotTest::request($id), $owner->session($id),
            $context, $policy, '1700000000000', 0, $owner->concurrency($id));
        self::assertSame('AUTHORIZATION_DENIED', $result['reason_code']);
        self::assertSame($diagnostic, $result['diagnostic']);
    }

    public static function changedGrants(): iterable
    {
        yield 'revoked' => [['revoked' => true], 'GRANT_REVOKED_OR_UNKNOWN'];
        yield 'credential-epoch' => [['credential_epoch' => 2], 'IDENTITY_OR_EPOCH_MISMATCH'];
        yield 'authorization-epoch' => [['authorization_epoch' => 2], 'IDENTITY_OR_EPOCH_MISMATCH'];
        yield 'application' => [['application_id' => 'other_app'], 'IDENTITY_OR_EPOCH_MISMATCH'];
    }

    public function test_policy_operation_intersection_is_enforced(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['operation_allowlist'] = ['OBSERVE_FILESYSTEM'];
        [, $policy, $context, $owner] = PolicySnapshotTest::harness($data);
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $result = (new AuthorizationEvaluator)->evaluate(PolicySnapshotTest::request($id, changes: ['operation' => 'OBSERVE_FREESPACE']),
            $owner->session($id), $context, $policy, '1700000000000', 0, $owner->concurrency($id));
        self::assertSame('OPERATION_SCOPE', $result['diagnostic']);
    }

    #[DataProvider('grantTimeBoundaries')]
    public function test_grant_validity_boundary(string $now, bool $allowed): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['valid_until'] = '1700000005000';
        [, $policy, $context, $owner] = PolicySnapshotTest::harness($data);
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $result = (new AuthorizationEvaluator)->evaluate(PolicySnapshotTest::request($id), $owner->session($id),
            $context, $policy, $now, 0, $owner->concurrency($id));
        self::assertSame($allowed ? 'OBSERVATION_SCOPE_ALLOWED' : 'DENIED', $result['decision']);
    }

    public static function grantTimeBoundaries(): iterable
    {
        yield ['1700000000000', true];
        yield ['1700000004999', true];
        yield ['1700000005000', false];
    }

    #[DataProvider('concurrencyLimits')]
    public function test_concurrency_limits_and_revocation(int $credential, int $controller, bool $revoked): void
    {
        [, $policy, $context, $owner] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $counts = array_merge($owner->concurrency($id), ['credential' => $credential, 'controller' => $controller, 'revoked' => $revoked]);
        $result = (new AuthorizationEvaluator)->evaluate(PolicySnapshotTest::request($id), $owner->session($id), $context,
            $policy, '1700000000000', 0, $counts);
        self::assertSame('DENIED', $result['decision']);
    }

    public static function concurrencyLimits(): iterable
    {
        yield [1, 1, false];
        yield [0, 4, false];
        yield [0, 0, true];
        yield [-1, 0, false];
    }

    public function test_context_clone_and_controller_instance_mismatch_are_denied(): void
    {
        [, $policy, $context, $owner] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);
        $evaluator = new AuthorizationEvaluator;
        $payload = PolicySnapshotTest::request($id);
        self::assertSame('AUTHENTICATION_FAILED', $evaluator->evaluate($payload, $owner->session($id), clone $context,
            $policy, '1700000000000', 0, $owner->concurrency($id))['reason_code']);
        $counts = $owner->concurrency($id);
        $counts['controller_instance_id'] = str_repeat('0', 64);
        self::assertSame('CONTROLLER_UNAVAILABLE', $evaluator->evaluate($payload, $owner->session($id), $context,
            $policy, '1700000000000', 0, $counts)['reason_code']);
    }
}
