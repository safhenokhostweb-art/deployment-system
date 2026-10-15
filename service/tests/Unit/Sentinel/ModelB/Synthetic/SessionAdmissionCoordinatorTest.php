<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\PolicySnapshot;
use App\Sentinel\ModelB\Synthetic\SessionAdmissionCoordinator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SessionAdmissionCoordinatorTest extends TestCase
{
    private static function active(?array $data = null): array
    {
        [$authority, $policy, $context, $owner] = PolicySnapshotTest::harness($data);
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        $owner->activate($id, $context);

        return [$authority, $policy, $context, $owner, $id];
    }

    public function test_atomic_admission_commits_all_fields_and_preserves_requested_timeout(): void
    {
        [, , $context, $owner, $id] = self::active();
        $before = $owner->session($id);
        $payload = PolicySnapshotTest::request($id, changes: ['timeout_ms' => 1500]);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit($payload, $context)['decision']);
        $data = $owner->session($id)->data();
        self::assertSame(2, $data['next_sequence']);
        self::assertSame(1, $data['admitted_count']);
        self::assertSame([str_pad('1', 64, '0', STR_PAD_LEFT)], $data['accepted_request_ids']);
        self::assertSame(1500, $data['outstanding_request']['timeout_ms']);
        self::assertSame(1500, $data['outstanding_request']['deadline_monotonic']);
        self::assertSame(hash('sha256', $payload), $data['outstanding_request']['request_digest']);
        self::assertSame(1, $owner->concurrency($id)['credential']);
        self::assertSame(1, $owner->concurrency($id)['controller']);
        self::assertSame(0, $before->data()['admitted_count']);
    }

    #[DataProvider('replays')]
    public function test_sequence_and_request_id_replay_never_readmit(int $sequence, bool $duplicateId): void
    {
        [, , $context, $owner, $id] = self::active();
        $firstId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $firstId), $context);
        $owner->complete($id, $firstId, true);
        $result = $owner->admit(PolicySnapshotTest::request($id, $sequence, $duplicateId ? $firstId : str_repeat('4', 64)), $context);
        self::assertSame('REPLAY_DETECTED', $result['reason_code']);
        self::assertSame(1, $owner->session($id)->data()['admitted_count']);
        self::assertSame('CLOSED', $owner->session($id)->data()['status']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
    }

    public static function replays(): iterable
    {
        yield 'duplicate-sequence' => [1, false];
        yield 'gap' => [3, false];
        yield 'duplicate-id' => [2, true];
    }

    public function test_first_sequence_must_be_one(): void
    {
        [, , $context, $owner, $id] = self::active();
        self::assertSame('REPLAY_DETECTED', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        self::assertSame([], $owner->session($id)->data()['accepted_request_ids']);
        self::assertSame(1, $owner->session($id)->data()['next_sequence']);
    }

    public function test_rejected_request_does_not_consume_id_sequence_or_reservation(): void
    {
        [, , $context, $owner, $id] = self::active();
        $before = $owner->session($id)->data();
        self::assertSame('TIMEOUT_LIMIT', $owner->admit(PolicySnapshotTest::request($id, changes: ['timeout_ms' => 4001]), $context)['diagnostic']);
        self::assertSame($before, $owner->session($id)->data());
        self::assertSame(0, $owner->concurrency($id)['controller']);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id), $context)['decision']);
    }

    public function test_candidate_exception_rolls_back_and_does_not_leave_guard_locked(): void
    {
        [, , $context, $owner, $id] = self::active();
        $before = $owner->session($id)->data();
        try {
            $owner->createSession($context, str_repeat('4', 64), str_repeat('5', 64), 0);
            self::fail('Invalid candidate was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $owner->session($id)->data());
        }
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id), $context)['decision']);
    }

    public function test_completion_and_observation_failure_preserve_consumption(): void
    {
        [, , $context, $owner, $id] = self::active();
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        self::assertSame('SYNTHETIC_OBSERVATION_FAILED', $owner->complete($id, $requestId, false)['decision']);
        self::assertSame(2, $owner->session($id)->data()['next_sequence']);
        self::assertSame([$requestId], $owner->session($id)->data()['accepted_request_ids']);
        self::assertNull($owner->session($id)->data()['outstanding_request']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
        self::assertSame('REPLAY_DETECTED', $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context)['reason_code']);
    }

    #[DataProvider('uncertainEvents')]
    public function test_uncertain_outcome_retains_reservation_and_blocks_admission(string $event): void
    {
        [, , $context, $owner, $id] = self::active();
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        match ($event) {
            'lost' => $owner->responseLost($id),
            'disconnect' => $owner->disconnect($id),
            'cleanup' => $owner->complete($id, $requestId, true, false),
            'timeout' => $owner->advanceClock(4000),
        };
        self::assertSame('CLOSED', $owner->session($id)->data()['status']);
        self::assertSame('CLEANUP_UNCERTAIN', $owner->session($id)->data()['outstanding_request']['outcome_state']);
        self::assertSame(1, $owner->concurrency($id)['controller']);
        self::assertTrue($owner->concurrency($id)['blocked']);
        self::assertSame('CLEANUP_UNCERTAIN', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        self::assertSame('DENIED', $owner->reconcileFixtureCleanup()['decision']);
        self::assertSame(1, $owner->session($id)->data()['admitted_count']);
    }

    public static function uncertainEvents(): iterable
    {
        foreach (['lost', 'disconnect', 'cleanup', 'timeout'] as $event) {
            yield $event => [$event];
        }
    }

    public function test_timeout_boundary_and_explicit_fixture_reconciliation(): void
    {
        [, , $context, $owner, $id] = self::active();
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        $owner->advanceClock(3999);
        self::assertSame('ACTIVE', $owner->session($id)->data()['status']);
        $owner->advanceClock(1);
        self::assertSame('WORKER_TIMEOUT', $owner->session($id)->data()['invalidation_reason']);
        self::assertSame('SYNTHETIC_RESULT_DISCARDED', $owner->complete($id, $requestId, true, true)['decision']);
        self::assertTrue($owner->concurrency($id)['blocked']);
        self::assertSame('FIXTURE_RECONCILED', $owner->reconcileFixtureCleanup()['decision']);
        self::assertFalse($owner->concurrency($id)['blocked']);
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
    }

    public function test_policy_change_invalidates_even_when_scope_digest_is_unchanged(): void
    {
        [$authority, $policy, $context, $owner, $id] = self::active();
        $data = PolicySnapshotTest::policyData();
        $data['policy_version'] = 2;
        $data['policy_digest'] = str_repeat('9', 64);
        $replacement = PolicySnapshot::fromTrustedFixture($authority, $data);
        self::assertSame($policy->scopeDigest('cred_a'), $replacement->scopeDigest('cred_a'));
        self::assertSame('POLICY_REPLACED', $owner->replacePolicy($replacement)['decision']);
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
        self::assertSame('DENIED', $owner->admit(PolicySnapshotTest::request($id), $context)['decision']);
        self::assertSame(1, $owner->session($id)->data()['policy_version']);
    }

    public function test_policy_downgrade_digest_conflict_and_other_authority_are_rejected(): void
    {
        [$authority, $policy, , $owner, $id] = self::active();
        $data = PolicySnapshotTest::policyData();
        $data['policy_digest'] = str_repeat('9', 64);
        self::assertSame('INVALID_POLICY', $owner->replacePolicy(PolicySnapshot::fromTrustedFixture($authority, $data))['reason_code']);
        self::assertSame('INVALID_POLICY', $owner->replacePolicy(PolicySnapshot::fromTrustedFixture(PolicySnapshot::newFixtureAuthority(), PolicySnapshotTest::policyData()))['reason_code']);
        self::assertSame($policy, $owner->policy());
        $data['policy_version'] = 2;
        $owner->replacePolicy(PolicySnapshot::fromTrustedFixture($authority, $data));
        self::assertSame('INVALID_POLICY', $owner->replacePolicy($policy)['reason_code']);
        self::assertSame(2, $owner->policy()->data()['policy_version']);
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
    }

    public function test_policy_expiration_prevents_admission(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['valid_until'] = '1700000005000';
        [, , $context, $owner, $id] = self::active($data);
        $owner->advanceClock(5000);
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
        self::assertSame('DENIED', $owner->admit(PolicySnapshotTest::request($id), $context)['decision']);
    }

    public function test_revocation_and_restart_preserve_uncertain_reservations(): void
    {
        [, , $context, $owner, $id] = self::active();
        $owner->admit(PolicySnapshotTest::request($id), $context);
        $owner->revokeCredential('cred_a');
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
        self::assertTrue($owner->concurrency($id)['revoked']);
        $owner->restart(str_repeat('8', 64));
        self::assertSame(1, $owner->concurrency($id)['controller']);
        self::assertSame('CLEANUP_UNCERTAIN', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        self::assertSame(str_repeat('f', 64), $owner->session($id)->data()['controller_instance_id']);
    }

    public function test_restart_without_work_requires_reconciliation_and_new_session(): void
    {
        [, , $context, $owner, $id] = self::active();
        $owner->restart(str_repeat('8', 64));
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
        $owner->reconcileFixtureCleanup();
        $newId = str_repeat('4', 64);
        $owner->createSession($context, $newId, str_repeat('5', 64));
        $owner->activate($newId, $context);
        self::assertSame(str_repeat('8', 64), $owner->session($newId)->data()['controller_instance_id']);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($newId), $context)['decision']);
    }

    public function test_concurrent_session_candidates_cannot_double_reserve_credential(): void
    {
        [, , $context, $owner, $id] = self::active();
        $otherId = str_repeat('4', 64);
        $owner->createSession($context, $otherId, str_repeat('5', 64));
        $owner->activate($otherId, $context);
        // Both requests are prepared from the same initial read-only state.
        $first = PolicySnapshotTest::request($id);
        $second = PolicySnapshotTest::request($otherId);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit($first, $context)['decision']);
        self::assertSame('CONCURRENCY_LIMIT', $owner->admit($second, $context)['diagnostic']);
        self::assertSame(1, $owner->concurrency($otherId)['controller']);
        self::assertSame(0, $owner->session($otherId)->data()['admitted_count']);
        $owner->complete($id, str_pad('1', 64, '0', STR_PAD_LEFT), true);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit($second, $context)['decision']);
        self::assertSame(1, $owner->concurrency($otherId)['controller']);
    }

    public function test_outstanding_request_rejects_next_sequence_without_consumption(): void
    {
        [, , $context, $owner, $id] = self::active();
        $owner->admit(PolicySnapshotTest::request($id), $context);
        $before = $owner->session($id)->data();
        self::assertSame('CONCURRENCY_LIMIT', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['diagnostic']);
        self::assertSame($before, $owner->session($id)->data());
        self::assertSame(1, $owner->concurrency($id)['controller']);
    }

    public function test_reentrant_mutation_is_denied_without_state_change(): void
    {
        [, , $context, $owner, $id] = self::active();
        $before = $owner->session($id)->data();
        // Inject the guard state directly; no callback is introduced into production.
        $guard = new ReflectionProperty(SessionAdmissionCoordinator::class, 'mutating');
        $guard->setValue($owner, true);
        self::assertSame('REENTRANT_MUTATION', $owner->admit(PolicySnapshotTest::request($id), $context)['diagnostic']);
        self::assertSame($before, $owner->session($id)->data());
        self::assertSame(0, $owner->concurrency($id)['controller']);
        $guard->setValue($owner, false);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id), $context)['decision']);
    }

    public function test_request_limit_is_exactly_1024_and_history_is_retained(): void
    {
        [, , $context, $owner, $id] = self::active();
        for ($sequence = 1; $sequence <= 1024; $sequence++) {
            $requestId = str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
            self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id, $sequence, $requestId), $context)['decision']);
            self::assertSame('SYNTHETIC_COMPLETED', $owner->complete($id, $requestId, true)['decision']);
        }
        self::assertSame(1024, $owner->session($id)->data()['admitted_count']);
        self::assertCount(1024, $owner->session($id)->data()['accepted_request_ids']);
        self::assertSame(1025, $owner->session($id)->data()['next_sequence']);
        self::assertSame('CLOSED', $owner->session($id)->data()['status']);
        self::assertSame('DENIED', $owner->admit(PolicySnapshotTest::request($id, 1025), $context)['decision']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
    }

    public function test_mismatched_completion_does_not_release_reservation(): void
    {
        [, , $context, $owner, $id] = self::active();
        $owner->admit(PolicySnapshotTest::request($id), $context);
        self::assertSame('CONTROLLER_UNAVAILABLE', $owner->complete($id, str_repeat('7', 64), true)['reason_code']);
        self::assertSame(1, $owner->concurrency($id)['controller']);
        self::assertTrue($owner->concurrency($id)['blocked']);
    }

    public function test_grant_expiration_invalidates_without_sequence_consumption(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['valid_until'] = '1700000005000';
        [, , $context, $owner, $id] = self::active($data);
        $owner->advanceClock(5000);
        self::assertSame('GRANT_EXPIRED_OR_NOT_YET_VALID', $owner->admit(PolicySnapshotTest::request($id), $context)['diagnostic']);
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
        self::assertSame(0, $owner->session($id)->data()['admitted_count']);
    }

    #[DataProvider('outstandingGrantExpiryBoundaries')]
    public function test_outstanding_completion_respects_grant_expiry(int $elapsed, bool $expired): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['valid_until'] = '1700000002000';
        [, $policy, $context, $owner, $id] = self::active($data);
        $requestId = str_repeat('3', 64);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context)['decision']);
        $owner->advanceClock($elapsed);
        $session = $owner->session($id)->data();
        self::assertTrue($policy->validAt($owner->clock()['wall']));
        self::assertLessThan($session['expiry_monotonic'], $owner->clock()['monotonic']);
        self::assertSame($expired ? 'INVALIDATED' : 'ACTIVE', $session['status']);
        self::assertSame(4000, $session['outstanding_request']['timeout_ms']);
        self::assertSame(1, $owner->concurrency($id)['controller']);
        if ($expired) {
            self::assertSame('GRANT_EXPIRED_OR_NOT_YET_VALID', $session['invalidation_reason']);
            self::assertSame('CLEANUP_UNCERTAIN', $session['outstanding_request']['outcome_state']);
            self::assertSame('DENIED', $owner->reconcileFixtureCleanup()['decision']);
        }
        self::assertSame($expired ? 'SYNTHETIC_RESULT_DISCARDED' : 'SYNTHETIC_COMPLETED',
            $owner->complete($id, $requestId, true, true)['decision']);
        $after = $owner->session($id)->data();
        self::assertSame(1, $after['admitted_count']);
        self::assertSame(2, $after['next_sequence']);
        self::assertSame([$requestId], $after['accepted_request_ids']);
        self::assertNull($after['outstanding_request']);
        self::assertSame(0, $owner->concurrency($id)['credential']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
        if ($expired) {
            self::assertSame('INVALIDATED', $after['status']);
            self::assertTrue($owner->concurrency($id)['blocked']);
            self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
            self::assertSame('CLEANUP_UNCERTAIN', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        }
    }

    public static function outstandingGrantExpiryBoundaries(): iterable
    {
        yield 'one-ms-before' => [1999, false];
        yield 'exact-expiry' => [2000, true];
        yield 'one-ms-after' => [2001, true];
    }

    public function test_expired_grant_with_uncertain_cleanup_keeps_reservation_and_consumption(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['valid_until'] = '1700000002000';
        [, , $context, $owner, $id] = self::active($data);
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        $owner->advanceClock(2000);
        $before = $owner->session($id)->data();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertSame('CLEANUP_UNCERTAIN', $owner->complete($id, $requestId, true, false)['reason_code']);
            self::assertSame($before, $owner->session($id)->data());
            self::assertSame(1, $owner->concurrency($id)['credential']);
            self::assertSame(1, $owner->concurrency($id)['controller']);
            self::assertSame('DENIED', $owner->reconcileFixtureCleanup()['decision']);
        }
        self::assertSame(2, $before['next_sequence']);
        self::assertSame([$requestId], $before['accepted_request_ids']);
        self::assertSame('INVALIDATED', $before['status']);
        self::assertSame('CLEANUP_UNCERTAIN', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
    }

    public function test_completion_checks_already_advanced_trusted_fixture_clock(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['valid_until'] = '1700000002000';
        [, , $context, $owner, $id] = self::active($data);
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        // Trusted harness advances only the owned clock, isolating the completion gate.
        // No timestamp parameter or mutable grant is added to the production API.
        $property = new ReflectionProperty(SessionAdmissionCoordinator::class, 'state');
        $state = $property->getValue($owner);
        $state['wall'] = '1700000002000';
        $state['mono'] = 2000;
        $property->setValue($owner, $state);
        self::assertSame('ACTIVE', $owner->session($id)->data()['status']);
        self::assertSame('SYNTHETIC_RESULT_DISCARDED', $owner->complete($id, $requestId, true, true)['decision']);
        $after = $owner->session($id)->data();
        self::assertSame('INVALIDATED', $after['status']);
        self::assertSame('GRANT_EXPIRED_OR_NOT_YET_VALID', $after['invalidation_reason']);
        self::assertSame([$requestId], $after['accepted_request_ids']);
        self::assertSame(2, $after['next_sequence']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
        self::assertTrue($owner->concurrency($id)['blocked']);
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
    }

    public function test_lost_response_and_invalid_or_duplicate_completion_preserve_cleanup_latch(): void
    {
        [, , $context, $owner, $id] = self::active();
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        $owner->responseLost($id);
        $before = $owner->session($id)->data();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertSame('CLEANUP_UNCERTAIN', $owner->complete($id, str_repeat('7', 64), true)['reason_code']);
            self::assertSame($before, $owner->session($id)->data());
            self::assertSame(1, $owner->concurrency($id)['controller']);
            self::assertSame(1, $owner->concurrency($id)['credential']);
            self::assertSame('CLEANUP_UNCERTAIN', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['reason_code']);
        }
        self::assertSame('CLEANUP_UNCERTAIN', $owner->complete($id, $requestId, true, false)['reason_code']);
        self::assertSame(1, $owner->concurrency($id)['controller']);
        self::assertSame('SYNTHETIC_RESULT_DISCARDED', $owner->complete($id, $requestId, true, true)['decision']);
        self::assertSame('CLEANUP_UNCERTAIN', $owner->complete($id, $requestId, true, true)['reason_code']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
        self::assertTrue($owner->concurrency($id)['blocked']);
        self::assertSame('CLOSED', $owner->session($id)->data()['status']);
        self::assertSame(2, $owner->session($id)->data()['next_sequence']);
        self::assertSame([$requestId], $owner->session($id)->data()['accepted_request_ids']);
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
    }

    #[DataProvider('invalidCompletionAfterTerminalEvents')]
    public function test_repeated_mismatched_completion_preserves_event_blocker_and_reservation(
        string $event, string $status, string $terminalReason, string $blocker,
    ): void {
        $data = PolicySnapshotTest::policyData();
        if ($event === 'grant-expiry') {
            $data['credential_grants']['cred_a']['valid_until'] = '1700000002000';
        }
        [, , $context, $owner, $id] = self::active($data);
        $requestId = str_repeat('3', 64);
        self::assertSame('OBSERVATION_SCOPE_ALLOWED', $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context)['decision']);
        match ($event) {
            'timeout' => $owner->advanceClock(4000),
            'disconnect' => $owner->disconnect($id),
            'grant-expiry' => $owner->advanceClock(2000),
        };
        $before = $owner->session($id)->data();
        $reserved = $owner->concurrency($id);
        self::assertSame($status, $before['status']);
        self::assertSame($terminalReason, $before['invalidation_reason']);
        self::assertSame('CLEANUP_UNCERTAIN', $before['outstanding_request']['outcome_state']);
        self::assertSame($requestId, $before['outstanding_request']['request_id']);
        self::assertSame(1, $reserved['credential']);
        self::assertSame(1, $reserved['controller']);
        self::assertTrue($reserved['blocked']);
        self::assertSame(1, $before['admitted_count']);
        self::assertSame(2, $before['next_sequence']);
        self::assertSame([$requestId], $before['accepted_request_ids']);
        $next = PolicySnapshotTest::request($id, 2, str_repeat('4', 64));
        self::assertSame($blocker, $owner->admit($next, $context)['reason_code']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = $owner->complete($id, str_repeat('7', 64), true, true);
            self::assertSame('DENIED', $completion['decision']);
            self::assertSame($blocker, $completion['reason_code']);
            self::assertSame('COMPLETION_STATE_UNCERTAIN', $completion['diagnostic']);
            self::assertSame($before, $owner->session($id)->data());
            self::assertSame($reserved, $owner->concurrency($id));
            self::assertSame($blocker, $owner->admit($next, $context)['reason_code']);
            self::assertSame(1, $owner->session($id)->data()['admitted_count']);
            self::assertSame(2, $owner->session($id)->data()['next_sequence']);
            self::assertSame([$requestId], $owner->session($id)->data()['accepted_request_ids']);
        }
        self::assertSame('DENIED', $owner->activate($id, $context)['decision']);
        self::assertSame($before, $owner->session($id)->data());
        self::assertSame($reserved, $owner->concurrency($id));
    }

    public static function invalidCompletionAfterTerminalEvents(): iterable
    {
        yield 'request-timeout' => ['timeout', 'CLOSED', 'WORKER_TIMEOUT', 'CLEANUP_UNCERTAIN'];
        yield 'disconnect' => ['disconnect', 'CLOSED', 'DISCONNECTED', 'CLEANUP_UNCERTAIN'];
        yield 'credential-grant-expiry' => ['grant-expiry', 'INVALIDATED', 'GRANT_EXPIRED_OR_NOT_YET_VALID', 'CLEANUP_UNCERTAIN'];
    }

    public function test_revoked_grant_cannot_activate_session(): void
    {
        $data = PolicySnapshotTest::policyData();
        $data['credential_grants']['cred_a']['revoked'] = true;
        [, , $context, $owner] = PolicySnapshotTest::harness($data);
        $id = str_repeat('1', 64);
        $owner->createSession($context, $id, str_repeat('2', 64));
        self::assertSame('AUTHORIZATION_DENIED', $owner->activate($id, $context)['reason_code']);
        self::assertSame('INVALIDATED', $owner->session($id)->data()['status']);
    }

    public function test_duplicate_completion_never_double_releases_or_readmits(): void
    {
        [, , $context, $owner, $id] = self::active();
        $requestId = str_repeat('3', 64);
        $owner->admit(PolicySnapshotTest::request($id, 1, $requestId), $context);
        $owner->complete($id, $requestId, true);
        self::assertSame('CONTROLLER_UNAVAILABLE', $owner->complete($id, $requestId, true)['reason_code']);
        self::assertSame(0, $owner->concurrency($id)['controller']);
        self::assertSame('DENIED', $owner->admit(PolicySnapshotTest::request($id, 2), $context)['decision']);
    }
}
