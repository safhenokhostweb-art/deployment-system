<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\PolicySnapshot;
use App\Sentinel\ModelB\Synthetic\SessionState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionStateTest extends TestCase
{
    public function test_creation_activation_and_immutable_old_view(): void
    {
        [, , $context, $coordinator] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $created = $coordinator->createSession($context, $id, str_repeat('2', 64));
        self::assertSame('CREATED', $created->data()['status']);
        self::assertSame(1, $created->data()['next_sequence']);
        self::assertSame(0, $created->data()['admitted_count']);
        self::assertSame('SESSION_ACTIVE', $coordinator->activate($id, $context)['decision']);
        self::assertSame('ACTIVE', $coordinator->session($id)->data()['status']);
        self::assertSame('CREATED', $created->data()['status']);
        $copy = $created->data();
        $copy['next_sequence'] = 99;
        self::assertSame(1, $created->data()['next_sequence']);
    }

    public function test_expiry_at_exact_boundary_and_no_reactivation(): void
    {
        [, , $context, $coordinator] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $view = $coordinator->createSession($context, $id, str_repeat('2', 64));
        $coordinator->activate($id, $context);
        self::assertFalse($view->expiredAt(59999));
        self::assertTrue($view->expiredAt(60000));
        $coordinator->advanceClock(59999);
        self::assertSame('ACTIVE', $coordinator->session($id)->data()['status']);
        $coordinator->advanceClock(1);
        self::assertSame('EXPIRED', $coordinator->session($id)->data()['status']);
        self::assertSame('DENIED', $coordinator->activate($id, $context)['decision']);
    }

    #[DataProvider('invalidLifetimes')]
    public function test_invalid_lifetime_is_rejected_without_session_creation(int $lifetime): void
    {
        [, , $context, $coordinator] = PolicySnapshotTest::harness();
        $this->expectException(InvalidArgumentException::class);
        $coordinator->createSession($context, str_repeat('1', 64), str_repeat('2', 64), $lifetime);
    }

    public static function invalidLifetimes(): iterable
    {
        yield [0];
        yield [60001];
        yield [-1];
    }

    public function test_context_is_bound_by_identity_not_matching_strings(): void
    {
        [$authority, $policy, $context, $coordinator] = PolicySnapshotTest::harness();
        $view = $coordinator->createSession($context, str_repeat('1', 64), str_repeat('2', 64));
        self::assertTrue($view->matchesContext($context, $policy));
        self::assertFalse($view->matchesContext(clone $context, $policy));
        $other = PolicySnapshot::newFixtureContext($authority, ['application_id' => 'app_a', 'credential_id' => 'cred_a',
            'credential_epoch' => 1, 'authorization_epoch' => 1]);
        self::assertFalse($view->matchesContext($other, $policy));
        self::assertSame('AUTHENTICATION_FAILED', $coordinator->activate(str_repeat('1', 64), $other)['reason_code']);
    }

    public function test_closed_session_cannot_activate_and_identity_bindings_are_preserved(): void
    {
        [, $policy, $context, $coordinator] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $coordinator->createSession($context, $id, str_repeat('2', 64), 1);
        $before = $coordinator->session($id)->data();
        $coordinator->disconnect($id);
        self::assertSame('DENIED', $coordinator->activate($id, $context)['decision']);
        $after = $coordinator->session($id)->data();
        self::assertSame('CLOSED', $after['status']);
        foreach (['session_id', 'controller_instance_id', 'policy_version', 'policy_digest', 'credential_epoch',
            'authorization_epoch', 'scope_digest', 'application_id', 'credential_id'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertSame($policy->scopeDigest('cred_a'), $after['scope_digest']);
    }

    public function test_deserialized_context_cannot_construct_a_session_view(): void
    {
        [$authority, , $context, $coordinator] = PolicySnapshotTest::harness();
        $view = $coordinator->createSession($context, str_repeat('1', 64), str_repeat('2', 64));
        $fake = unserialize(serialize($context), ['allowed_classes' => [\stdClass::class]]);
        $this->expectException(InvalidArgumentException::class);
        new SessionState($authority, $fake, $view->data());
    }

    public function test_epoch_change_invalidates_session_via_policy_replacement(): void
    {
        [$authority, , $context, $coordinator] = PolicySnapshotTest::harness();
        $id = str_repeat('1', 64);
        $coordinator->createSession($context, $id, str_repeat('2', 64));
        $coordinator->activate($id, $context);
        $data = PolicySnapshotTest::policyData();
        $data['policy_version'] = 2;
        $data['policy_digest'] = str_repeat('9', 64);
        $data['credential_grants']['cred_a']['credential_epoch'] = 2;
        $data['credential_grants']['cred_a']['authorization_epoch'] = 2;
        $coordinator->replacePolicy(PolicySnapshot::fromTrustedFixture($authority, $data));
        self::assertSame('INVALIDATED', $coordinator->session($id)->data()['status']);
        self::assertSame('DENIED', $coordinator->activate($id, $context)['decision']);
        self::assertSame(1, $coordinator->session($id)->data()['credential_epoch']);
    }
}
