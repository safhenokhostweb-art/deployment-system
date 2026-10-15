<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\Mysql\MysqlCredentialBroker;
use App\Services\Sentinel\LocalProof\Mysql\MysqlCredentialPolicy;
use App\Services\Sentinel\LocalProof\Mysql\MysqlEndpoint;
use App\Services\Sentinel\LocalProof\Mysql\MysqlFailureCategory;
use App\Services\Sentinel\LocalProof\Mysql\MysqlLifecycle;
use App\Services\Sentinel\LocalProof\Mysql\MysqlLifecycleState;
use App\Services\Sentinel\LocalProof\Mysql\MysqlRunOwnership;
use App\Services\Sentinel\LocalProof\Mysql\MysqlSecretLease;
use App\Services\Sentinel\LocalProof\Mysql\MysqlSecurityEvidence;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Synthetic in-memory descriptions only; no observer, secrets, DB, VM or processes. */
final class MysqlSecurityContractsTest extends TestCase
{
    private SentinelProofRunConfig $run;
    private MysqlRunOwnership $owner;
    private const SESSION = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    protected function setUp(): void
    {
        $this->run = $this->newRun();
        $this->owner = $this->ownership($this->run);
    }

    private function newRun(): SentinelProofRunConfig
    {
        return SentinelProofRunConfig::create(dirname(__DIR__, 5), str_repeat('a', 40),
            'fixture-branch', 18572, 18573, 'fixture-project');
    }

    private function ownership(SentinelProofRunConfig $run): MysqlRunOwnership
    {
        return MysqlRunOwnership::forRun($run, MysqlEndpoint::fromArray($run,
            ['host' => '127.0.0.1', 'port' => 19001, 'transport' => 'tcp']), str_repeat('b', 64));
    }

    private function rejected(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Unsafe contract input was accepted'); }
        catch (InvalidArgumentException $error) {
            self::assertSame($code, $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    private function lease(string $purpose = 'APPLICATION', string $session = self::SESSION,
        ?MysqlRunOwnership $owner = null, string $reference = ''): MysqlSecretLease
    {
        return MysqlSecretLease::fromMetadata($owner ?? $this->owner, $reference ?: str_repeat('c', 64),
            $purpose, 1000, 1200, $session);
    }

    public function test_valid_endpoint_is_explicit_and_does_not_allocate_anything(): void
    {
        $e = $this->owner->endpoint;
        self::assertSame('127.0.0.1', $e->host);
        self::assertSame(19001, $e->port);
        self::assertSame('tcp', $e->transport);
        self::assertSame($this->run->runId, $e->runId);
        self::assertFalse(file_exists($this->run->root));
        foreach ([1024, 65535] as $port) {
            self::assertSame($port, MysqlEndpoint::fromArray($this->run,
                ['host' => '127.0.0.1', 'port' => $port, 'transport' => 'tcp'])->port);
        }
    }

    public static function unsafeEndpoints(): iterable
    {
        foreach (['localhost', '127.0.0.2', '127.1', '::1', '[::1]', '0.0.0.0',
            '192.168.1.1', 'production.example', '127.0.0.1 ', "127.0.0.1\0"] as $host) {
            yield 'host ' . bin2hex($host) => [['host' => $host]];
        }
        foreach ([0, -1, 1023, 65536, 3306, 18572, 18573, 8000, 8080, '19001', 19001.0, null] as $i => $port) {
            yield 'port ' . $i => [['port' => $port]];
        }
        foreach (['socket', 'pipe', 'TCP', '', null] as $i => $transport) { yield 'transport ' . $i => [['transport' => $transport]]; }
        foreach ([['dsn' => 'mysql:host=127.0.0.1;port=19001'], ['DSN' => 'fixture-secret'],
            ['DB_URL' => 'mysql://fixture-secret@127.0.0.1:19001/schema'], ['DB_URL' => null],
            ['db_url' => 'fixture-secret'], ['fallback' => 'sqlite'], ['password' => 'fixture-secret']] as $i => $override) {
            yield 'override ' . $i => [$override];
        }
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_endpoint_rejection_has_no_fallback_or_sensitive_exception(array $override): void
    {
        $this->rejected(fn () => MysqlEndpoint::fromArray($this->run,
            array_replace(['host' => '127.0.0.1', 'port' => 19001, 'transport' => 'tcp'], $override)),
            'proof_mysql_endpoint_rejected');
        self::assertFalse(file_exists($this->run->root));
    }

    public function test_missing_endpoint_fields_and_scalar_reference_changes_are_rejected_or_detached(): void
    {
        foreach (['host', 'port', 'transport'] as $field) {
            $input = ['host' => '127.0.0.1', 'port' => 19001, 'transport' => 'tcp']; unset($input[$field]);
            $this->rejected(fn () => MysqlEndpoint::fromArray($this->run, $input), 'proof_mysql_endpoint_rejected');
        }
        $port = 19001;
        $e = MysqlEndpoint::fromArray($this->run, ['host' => '127.0.0.1', 'port' => &$port, 'transport' => 'tcp']);
        $port = 3306;
        self::assertSame(19001, $e->port);
    }

    public function test_ownership_derives_descriptions_without_claiming_native_inspection(): void
    {
        self::assertSame($this->run->root, $this->owner->runRoot);
        foreach (['databaseDirectory' => 'database/data', 'configurationDirectory' => 'database/config',
            'temporaryDirectory' => 'database/tmp', 'expectedExecutable' => 'database/engine/bin/mysqld.exe'] as $property => $relative) {
            self::assertSame($this->run->path($relative), $this->owner->{$property});
            $this->owner->assertPathDescription($this->owner->{$property});
        }
        $this->owner->assertSchema('sentinel_' . str_replace('-', '', $this->run->runId));
        $this->owner->assertRun($this->run->runId);
        self::assertSame($this->run->runId, $this->owner->expectedProcessIdentity()['run_id']);
        self::assertSame(19001, $this->owner->expectedListenerIdentity()['port']);
        self::assertFalse(file_exists($this->run->root));
    }

    public function test_cross_run_owner_schema_and_unsafe_path_descriptions_are_rejected(): void
    {
        $other = $this->ownership($this->newRun());
        $this->rejected(fn () => MysqlRunOwnership::forRun($this->run, $other->endpoint, str_repeat('b', 64)), 'proof_mysql_owner_rejected');
        $this->rejected(fn () => MysqlRunOwnership::forRun($this->run, $this->owner->endpoint, 'fixture-secret'), 'proof_mysql_owner_rejected');
        $this->rejected(fn () => $this->owner->assertRun($other->runId), 'proof_mysql_owner_rejected');
        foreach (['mysql', 'production', 'sentinel_', strtoupper($this->owner->schemaName), $other->schemaName,
            $this->owner->schemaName . '; DROP DATABASE mysql', '../schema', ''] as $schema) {
            $this->rejected(fn () => $this->owner->assertSchema($schema), 'proof_mysql_schema_rejected');
        }
        $path = $this->owner->databaseDirectory;
        foreach ([$other->databaseDirectory, 'C:/laragon/data/mysql-8', '//server/share/data', '\\\\server\\share\\data',
            $this->run->root . '/database/../data', $this->run->root . '/database/./data',
            $this->run->root . '/database//data', $path . '.', $path . ' ', $path . ':stream',
            str_replace('/', '\\', $path), $path . "\0", $this->run->root . '/hub/database.sqlite'] as $candidate) {
            $this->rejected(fn () => $this->owner->assertPathDescription($candidate), 'proof_mysql_path_rejected');
        }
    }

    public function test_caller_path_observations_are_never_authority_even_with_all_safe_flags(): void
    {
        $path = $this->owner->databaseDirectory;
        foreach ([[$path, false, true], [$path, true, true], [$path, false, false],
            ['C:/outside/private', false, true]] as [$canonical, $reparse, $inspected]) {
            for ($replay = 0; $replay < 2; $replay++) {
                $this->rejected(fn () => $this->owner->assertInspectedPath($path, $canonical, $reparse, $inspected),
                    'proof_mysql_path_observation_untrusted');
            }
        }
    }

    public function test_evidence_reference_mutation_does_not_change_storage_or_export(): void
    {
        $value = 'NOT_CHECKED'; $input = ['endpoint_validation' => &$value];
        $evidence = MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(), $input);
        $before = $evidence->toArray();
        $value = 'fixture-secret'; $input['path_containment'] = 'VERIFIED';
        self::assertSame($before, $evidence->toArray());
        self::assertSame('NOT_CHECKED', $evidence->toArray()['endpoint_validation']);
        self::assertStringNotContainsString('fixture-secret', json_encode($evidence, JSON_THROW_ON_ERROR));
        $export = $evidence->toArray(); $export['endpoint_validation'] = 'fixture-secret';
        self::assertSame($before, $evidence->toArray());
    }

    public function test_nested_references_objects_resources_and_unfiltered_fields_are_rejected(): void
    {
        $value = 'NOT_CHECKED'; $nested = ['state' => &$value];
        $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(),
            ['endpoint_validation' => &$nested]), 'proof_mysql_evidence_rejected');
        $value = 'fixture-secret';
        $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(),
            ['endpoint_validation' => $nested]), 'proof_mysql_evidence_rejected');
        $resource = fopen('php://memory', 'r+');
        try {
            foreach ([new \stdClass(), $resource, ['password' => 'fixture-secret'], true, null] as $input) {
                $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(),
                    ['endpoint_validation' => $input]), 'proof_mysql_evidence_rejected');
            }
        } finally { fclose($resource); }
        foreach (['password', 'token', 'authentication_sql', 'exception_message', 'dsn', 'run_root',
            'command_line', 'failure_category', 'lifecycle_state', 'observer', 'session_id', 'receipt', 'fallback'] as $field) {
            $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(),
                [$field => 'fixture-secret']), 'proof_mysql_evidence_rejected');
        }
    }

    public function test_export_independently_revalidates_internal_data(): void
    {
        $e = MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight());
        // Test export validation even if privileged in-process code corrupts private storage.
        $map = (new ReflectionProperty(MysqlSecurityEvidence::class, 'contractStates'))->getValue();
        $saved = $map[$e]; $corrupt = $saved;
        $corrupt['checks']['endpoint_validation'] = 'fixture-secret'; $map[$e] = $corrupt;
        try { $this->rejected(fn () => $e->toArray(), 'proof_mysql_evidence_rejected'); }
        finally { $map[$e] = $saved; }
        self::assertSame('NOT_CHECKED', $e->toArray()['endpoint_validation']);
    }

    public function test_export_rejects_nested_corruption_of_lifecycle_failure_fields(): void
    {
        $life = MysqlLifecycle::preflight()->fail(MysqlFailureCategory::STARTUP_FAILED);
        $e = MysqlSecurityEvidence::forLifecycle($life);
        $map = (new ReflectionProperty(MysqlLifecycle::class, 'contractStates'))->getValue();
        $saved = $map[$life];
        foreach (['state', 'originalFailure', 'cleanupFailure'] as $field) {
            $corrupt = $saved; $corrupt[$field] = (object) ['value' => 'fixture-secret']; $map[$life] = $corrupt;
            try { $this->rejected(fn () => $e->toArray(), 'proof_mysql_evidence_rejected'); }
            finally { $map[$life] = $saved; }
        }
        foreach (['state', 'originalFailure', 'cleanupFailure'] as $field) {
            $corrupt = $saved; unset($corrupt[$field]); $map[$life] = $corrupt;
            try { $this->rejected(fn () => $e->toArray(), 'proof_mysql_evidence_rejected'); }
            finally { $map[$life] = $saved; }
        }
        $corrupt = $saved; $corrupt['originalFailure'] = null; $map[$life] = $corrupt;
        try { $this->rejected(fn () => $e->toArray(), 'proof_mysql_evidence_rejected'); }
        finally { $map[$life] = $saved; }
        self::assertSame('STARTUP_FAILED', $e->toArray()['failure_category']);
    }

    public function test_all_positive_security_markers_and_forged_observer_objects_are_rejected(): void
    {
        foreach (['endpoint_validation', 'path_containment', 'process_ownership', 'listener_ownership',
            'os_containment', 'credential_bootstrap', 'cleanup_status'] as $field) {
            foreach (['VERIFIED', 'PASSED', 'SUCCEEDED', true, (object) ['verified' => true]] as $value) {
                $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight(), [$field => $value]),
                    'proof_mysql_evidence_rejected');
            }
        }
    }

    public function test_security_gated_transitions_and_replay_remain_unavailable_without_observer(): void
    {
        $life = MysqlLifecycle::preflight();
        $evidence = MysqlSecurityEvidence::forLifecycle($life);
        $stale = MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight());
        foreach ([null, $evidence, $stale] as $claim) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->rejected(fn () => $life->transition(MysqlLifecycleState::INITIALIZING, $claim),
                    'proof_mysql_trusted_observer_unavailable');
            }
        }
        foreach ([false, true] as $flag) {
            $this->rejected(fn () => $evidence->assertGatesFor($life, $flag, $flag), 'proof_mysql_trusted_observer_unavailable');
        }
        self::assertSame(MysqlLifecycleState::PREFLIGHT, $life->state);
    }

    public function test_invalid_transitions_and_original_cleanup_failure_preservation(): void
    {
        $initial = MysqlLifecycle::preflight();
        foreach (MysqlLifecycleState::cases() as $next) {
            if ($next !== MysqlLifecycleState::INITIALIZING) {
                $this->rejected(fn () => $initial->transition($next), 'proof_mysql_transition_rejected');
            }
        }
        $failed = $initial->fail(MysqlFailureCategory::MIGRATION_FAILED);
        self::assertSame(MysqlFailureCategory::MIGRATION_FAILED, $failed->fail(MysqlFailureCategory::INTERRUPTED)->originalFailure);
        $stopping = $failed->transition(MysqlLifecycleState::STOPPING);
        $stopped = $stopping->transition(MysqlLifecycleState::STOPPED);
        $cleanupFailed = $stopping->cleanupFailed();
        self::assertTrue($stopped->hasFailed());
        self::assertTrue($cleanupFailed->hasFailed());
        $data = MysqlSecurityEvidence::forLifecycle($cleanupFailed)->toArray();
        self::assertSame('MIGRATION_FAILED', $data['failure_category']);
        self::assertSame('CLEANUP_FAILED', $data['cleanup_failure_category']);
        self::assertSame('FAILED', $data['cleanup_status']);
        self::assertSame('NOT_CHECKED', MysqlSecurityEvidence::forLifecycle($stopped)->toArray()['cleanup_status']);
        foreach ([$stopped, $cleanupFailed] as $terminal) {
            foreach (MysqlLifecycleState::cases() as $next) {
                $this->rejected(fn () => $terminal->transition($next), 'proof_mysql_transition_rejected');
            }
        }
        $this->rejected(fn () => $initial->cleanupFailed(), 'proof_mysql_transition_rejected');
        $this->rejected(fn () => $initial->fail(MysqlFailureCategory::CLEANUP_FAILED), 'proof_mysql_transition_rejected');
        $this->rejected(fn () => MysqlSecurityEvidence::forLifecycle($cleanupFailed, ['cleanup_status' => 'SUCCEEDED']),
            'proof_mysql_evidence_rejected');
    }

    private function contracts(): array
    {
        return [$this->owner->endpoint, $this->owner, MysqlLifecycle::preflight(),
            MysqlSecurityEvidence::forLifecycle(MysqlLifecycle::preflight()), $this->lease(), new MysqlCredentialPolicy($this->owner)];
    }

    public function test_direct_json_debug_array_and_sanitized_exports_do_not_expose_internal_data(): void
    {
        foreach ($this->contracts() as $contract) {
            self::assertSame([], (array) $contract);
            foreach ([$contract, $contract->toArray(), $contract->__debugInfo(), (array) $contract] as $export) {
                $json = json_encode($export, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                foreach ([$this->run->root, $this->owner->databaseDirectory, $this->owner->configurationDirectory,
                    $this->owner->expectedExecutableSha256, $this->owner->schemaName, self::SESSION, str_repeat('c', 64)] as $private) {
                    self::assertStringNotContainsString($private, $json);
                }
            }
        }
    }

    private function objectPayload(string $class, array $fields = []): string
    {
        $body = '';
        foreach ($fields as $key => $value) { $body .= serialize($key) . serialize($value); }
        return 'O:' . strlen($class) . ':"' . $class . '":' . count($fields) . ':{' . $body . '}';
    }

    public function test_serialization_unserialization_wakeup_cloning_and_uninitialized_hydration_are_blocked(): void
    {
        foreach ($this->contracts() as $contract) {
            $class = $contract::class;
            $this->rejected(fn () => serialize($contract), 'proof_mysql_contract_restoration_rejected');
            $this->rejected(fn () => unserialize($this->objectPayload($class), ['allowed_classes' => [$class]]),
                'proof_mysql_contract_restoration_rejected');
            $this->rejected(fn () => $contract->__unserialize(['password' => 'fixture-secret']), 'proof_mysql_contract_restoration_rejected');
            $this->rejected(fn () => $contract->__wakeup(), 'proof_mysql_contract_restoration_rejected');
            $this->rejected(fn () => clone $contract, 'proof_mysql_contract_restoration_rejected');
            $uninitialized = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->rejected(fn () => $uninitialized->toArray(), 'proof_mysql_contract_uninitialized');
            $this->rejected(fn () => $uninitialized->host, 'proof_mysql_contract_uninitialized');
            $this->rejected(fn () => $uninitialized->host = '203.0.113.1', 'proof_mysql_contract_mutation_rejected');
            self::assertSame([], (array) $uninitialized);
            self::assertStringNotContainsString('fixture-secret', json_encode($uninitialized->__debugInfo()));
        }
    }

    public function test_forged_endpoint_payload_and_reflective_constructor_still_cannot_bypass_validation(): void
    {
        $class = MysqlEndpoint::class;
        $payload = $this->objectPayload($class, ['host' => '203.0.113.1', 'port' => 3306, 'transport' => 'tcp']);
        $this->rejected(fn () => unserialize($payload, ['allowed_classes' => [$class]]), 'proof_mysql_contract_restoration_rejected');
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        $this->rejected(fn () => $reflection->getConstructor()->invoke($object, $this->run,
            ['host' => '203.0.113.1', 'port' => 19001, 'transport' => 'tcp']), 'proof_mysql_endpoint_rejected');
        $this->rejected(fn () => $object->host, 'proof_mysql_contract_uninitialized');
        $life = (new ReflectionClass(MysqlLifecycle::class))->newInstanceWithoutConstructor();
        foreach ([MysqlLifecycleState::INITIALIZING, MysqlLifecycleState::BOOTSTRAPPING_CREDENTIALS,
            MysqlLifecycleState::STARTING, MysqlLifecycleState::READY, MysqlLifecycleState::MIGRATING] as $state) {
            $this->rejected(fn () => (new ReflectionClass(MysqlLifecycle::class))->getConstructor()->invoke($life, $state),
                'proof_mysql_trusted_observer_unavailable');
        }
    }

    public function test_generic_property_mutation_is_blocked_without_relying_on_readonly_keywords(): void
    {
        foreach ($this->contracts() as $contract) {
            $this->rejected(fn () => $contract->host = 'fixture-secret', 'proof_mysql_contract_mutation_rejected');
            $this->rejected(function () use ($contract): void { unset($contract->host); }, 'proof_mysql_contract_mutation_rejected');
        }
        self::assertSame('127.0.0.1', $this->owner->endpoint->host);
    }

    public function test_reflective_lifecycle_constructor_cannot_invent_cleanup_history_or_erase_failure(): void
    {
        $reflection = new ReflectionClass(MysqlLifecycle::class);
        foreach ([MysqlLifecycleState::STOPPING, MysqlLifecycleState::STOPPED, MysqlLifecycleState::CLEANUP_FAILED] as $state) {
            $object = $reflection->newInstanceWithoutConstructor();
            $this->rejected(fn () => $reflection->getConstructor()->invoke($object, $state), 'proof_mysql_transition_rejected');
        }
        $failed = MysqlLifecycle::preflight()->fail(MysqlFailureCategory::MIGRATION_FAILED);
        $object = $reflection->newInstanceWithoutConstructor();
        $this->rejected(fn () => $reflection->getConstructor()->invoke($object, MysqlLifecycleState::STOPPING,
            null, null, $failed), 'proof_mysql_transition_rejected');
        self::assertSame(MysqlFailureCategory::MIGRATION_FAILED, $failed->originalFailure);
    }

    public function test_legacy_custom_serialization_cannot_restore_a_usable_contract(): void
    {
        foreach ($this->contracts() as $contract) {
            $class = $contract::class;
            // PHP may reject C-format immediately or return an unconstructed object.
            // Neither outcome may produce a usable value object or export internal state.
            $payload = 'C:' . strlen($class) . ':"' . $class . '":0:{}';
            try {
                $restored = @unserialize($payload, ['allowed_classes' => [$class]]);
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('fixture-secret', $error->getMessage());
                continue;
            }
            if (is_object($restored) && $restored instanceof $class) {
                $this->rejected(fn () => $restored->toArray(), 'proof_mysql_contract_uninitialized');
            } else {
                self::assertFalse($restored);
            }
        }
    }

    public function test_secret_metadata_has_run_session_and_lifetime_validation_but_never_trusted_issuance(): void
    {
        self::assertTrue((new ReflectionClass(MysqlCredentialBroker::class))->isInterface());
        $lease = $this->lease();
        $lease->assertMetadataMatches($this->owner, 'APPLICATION', self::SESSION, 1000);
        foreach ([999, 1200, 1201] as $now) {
            $this->rejected(fn () => $lease->assertMetadataMatches($this->owner, 'APPLICATION', self::SESSION, $now),
                'proof_mysql_secret_policy_rejected');
        }
        $this->rejected(fn () => $lease->assertMetadataMatches($this->ownership($this->newRun()), 'APPLICATION', self::SESSION, 1001),
            'proof_mysql_secret_policy_rejected');
        $this->rejected(fn () => $lease->assertMetadataMatches($this->owner, 'DATABASE_CONTROL', self::SESSION, 1001),
            'proof_mysql_secret_policy_rejected');
        $this->rejected(fn () => $lease->assertMetadataMatches($this->owner, 'APPLICATION', str_repeat('e', 64), 1001),
            'proof_mysql_secret_policy_rejected');
        foreach ([['fixture-secret', 'APPLICATION', 1000, 1200, self::SESSION],
            [str_repeat('c', 64), 'ROOT_FALLBACK', 1000, 1200, self::SESSION],
            [str_repeat('c', 64), 'APPLICATION', -1, 1200, self::SESSION],
            [str_repeat('c', 64), 'APPLICATION', 1000, 1000, self::SESSION],
            [str_repeat('c', 64), 'APPLICATION', 1000, 1901, self::SESSION],
            [str_repeat('c', 64), 'APPLICATION', 1000, 1200, 'fixture-secret']] as [$ref, $purpose, $issued, $expires, $session]) {
            $this->rejected(fn () => MysqlSecretLease::fromMetadata($this->owner, $ref, $purpose, $issued, $expires, $session),
                'proof_mysql_secret_policy_rejected');
        }
        $this->rejected(fn () => $lease->assertUsable($this->owner, 'APPLICATION', 1001), 'proof_mysql_credential_broker_unavailable');
    }

    public function test_forged_handoff_cleanup_flags_and_replayed_lease_descriptions_are_unauthorized(): void
    {
        $policy = new MysqlCredentialPolicy($this->owner);
        $claims = ['run_id' => $this->run->runId, 'session_id' => self::SESSION, 'recipient' => 'LARAVEL_RUNTIME',
            'transport' => 'AUTHENTICATED_PRIVATE_IPC', 'recipient_authenticated' => true, 'acl_verified' => true,
            'os_containment_verified' => true, 'bootstrap_verified' => true];
        foreach ([$this->lease(), $this->lease(session: str_repeat('e', 64)),
            $this->lease(owner: $this->ownership($this->newRun())), $this->lease('DATABASE_CONTROL')] as $lease) {
            for ($replay = 0; $replay < 2; $replay++) {
                $this->rejected(fn () => $policy->assertHandoff($lease, 1001, $claims), 'proof_mysql_credential_broker_unavailable');
                $this->rejected(fn () => $policy->assertCleanup($lease, $this->run->runId, [
                    'recipients_stopped' => true, 'handoff_closed' => true, 'secret_material_destroyed' => true,
                    'evidence_preserved' => true]), 'proof_mysql_credential_broker_unavailable');
            }
        }
        foreach (['ENVIRONMENT', 'COMMAND_LINE', 'STDOUT', 'FILE', 'TCP', 'FALLBACK'] as $transport) {
            $this->rejected(fn () => $policy->assertHandoff($this->lease(), 1001, array_replace($claims, ['transport' => $transport])),
                'proof_mysql_credential_broker_unavailable');
        }
        $this->rejected(fn () => $policy->assertHandoff($this->lease(), 1200, []), 'proof_mysql_credential_broker_unavailable');
    }

    public function test_duplicate_or_cross_session_metadata_references_are_rejected(): void
    {
        $policy = new MysqlCredentialPolicy($this->owner);
        $control = $this->lease('DATABASE_CONTROL');
        $this->rejected(fn () => $policy->assertDistinctLeases($control, $this->lease(), 1001, self::SESSION),
            'proof_mysql_secret_reuse_rejected');
        $policy->assertDistinctLeases($control, $this->lease(reference: str_repeat('f', 64)), 1001, self::SESSION);
        $this->rejected(fn () => $policy->assertDistinctLeases($control, $this->lease(session: str_repeat('e', 64),
            reference: str_repeat('f', 64)), 1001, self::SESSION), 'proof_mysql_secret_policy_rejected');
    }

    public function test_existing_diagnostics_still_redact_secret_text(): void
    {
        $report = SentinelProofDiagnostics::describe(new \RuntimeException(
            'fixture-secret mysql://private-user@production.example C:/sensitive/path'), 'PREFLIGHT');
        self::assertSame('[REDACTED: unrecognized exception message]', $report['sanitized_exception_message']);
        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['fixture-secret', 'production.example', 'C:/sensitive/path'] as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
    }
}
