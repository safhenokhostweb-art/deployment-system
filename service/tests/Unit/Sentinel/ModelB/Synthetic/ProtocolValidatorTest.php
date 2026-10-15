<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\CanonicalJsonCodec;
use App\Sentinel\ModelB\Synthetic\ProtocolValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ProtocolValidatorTest extends TestCase
{
    private static function observe(): stdClass
    {
        return (object) [
            'protocol_version' => 1, 'message_type' => 'OBSERVE',
            'request_id' => str_repeat('a', 64), 'session_id' => str_repeat('b', 64),
            'sequence' => 1, 'policy_version' => 1, 'policy_digest' => str_repeat('c', 64),
            'operation' => 'OBSERVE_FILESYSTEM', 'target' => (object) ['root_id' => 'root_1'],
            'timeout_ms' => 5000,
        ];
    }

    #[DataProvider('validObservations')]
    public function test_all_observation_operations_and_limits(string $operation, string $targetKey, int $timeout): void
    {
        $message = self::observe();
        $message->operation = $operation;
        $message->target = (object) [$targetKey => 'a'.str_repeat('z', 63)];
        $message->timeout_ms = $timeout;
        $message->sequence = 2147483647;
        $message->policy_version = 2147483647;
        $payload = (new CanonicalJsonCodec)->encode($message);
        $result = (new ProtocolValidator)->validate($payload);
        self::assertEquals($message, $result);
        self::assertFalse(property_exists($result, 'authorized'));
        self::assertFalse(property_exists($result, 'vmStartAuthorized'));
    }

    public static function validObservations(): iterable
    {
        foreach (['OBSERVE_FILESYSTEM' => 'root_id', 'OBSERVE_FREESPACE' => 'root_id',
            'OBSERVE_SERVICE_STATUS' => 'service_id'] as $operation => $key) {
            foreach ([1, 5000] as $timeout) {
                yield $operation.'-'.$timeout => [$operation, $key, $timeout];
            }
        }
    }

    public function test_hello_has_exactly_the_b2_fields(): void
    {
        $hello = (object) ['client_nonce' => str_repeat('0', 64), 'message_type' => 'HELLO', 'protocol_version' => 1];
        self::assertEquals($hello, (new ProtocolValidator)->validate((new CanonicalJsonCodec)->encode($hello)));
    }

    #[DataProvider('invalidMessages')]
    public function test_invalid_schema_is_rejected(mixed $message): void
    {
        $payload = (new CanonicalJsonCodec)->encode($message);
        $this->expectException(InvalidArgumentException::class);
        (new ProtocolValidator)->validate($payload);
    }

    public static function invalidMessages(): iterable
    {
        foreach (array_keys(get_object_vars(self::observe())) as $field) {
            $message = self::observe();
            unset($message->{$field});
            yield 'missing-'.$field => [$message];
            foreach ([null, true, [], new stdClass] as $index => $invalidType) {
                $message = self::observe();
                $message->{$field} = $invalidType;
                yield 'wrong-type-'.$field.'-'.$index => [$message];
            }
        }
        foreach (['path', 'executable_path', 'script', 'command', 'policy_override', 'authorized'] as $field) {
            $message = self::observe();
            $message->{$field} = 'forbidden';
            yield 'extra-'.$field => [$message];
        }
        foreach (['protocol_version' => [0, 2, '1'], 'message_type' => ['observe', '', 1, 'SESSION', 'OBSERVATION_RESPONSE'],
            'sequence' => [0, '1'], 'policy_version' => [0, '1'], 'timeout_ms' => [0, 5001, '5000'],
            'operation' => ['', 1, 'VM_START', 'MYSQL_START', 'MIGRATE', 'POWERSHELL', 'observe_filesystem'],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                $message = self::observe();
                $message->{$field} = $value;
                yield 'invalid-'.$field.'-'.$index => [$message];
            }
        }
        foreach (['request_id', 'session_id', 'policy_digest'] as $field) {
            foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64), 1] as $index => $value) {
                $message = self::observe();
                $message->{$field} = $value;
                yield 'hex-'.$field.'-'.$index => [$message];
            }
        }
        foreach (['', 'A', '1root', str_repeat('a', 65), '/tmp/root', 'C:/root', '../root', 'root.name', ' root', 'root ', 'root;cmd'] as $index => $id) {
            foreach (['OBSERVE_FILESYSTEM' => 'root_id', 'OBSERVE_SERVICE_STATUS' => 'service_id'] as $operation => $key) {
                $message = self::observe();
                $message->operation = $operation;
                $message->target = (object) [$key => $id];
                yield 'resource-'.$operation.'-'.$index => [$message];
            }
        }
        foreach ([new stdClass, (object) ['service_id' => 'service'],
            (object) ['root_id' => 'root', 'service_id' => 'service'],
            (object) ['root_id' => 'root', 'path' => '/tmp/root'],
            (object) ['root_id' => 1], (object) ['root_id' => null],
            (object) ['root_id' => (object) ['root_id' => 'root']], ['root'],
        ] as $index => $target) {
            $message = self::observe();
            $message->target = $target;
            yield 'target-'.$index => [$message];
        }
        $service = self::observe();
        $service->operation = 'OBSERVE_SERVICE_STATUS';
        yield 'service-with-root' => [$service];
        $freespace = self::observe();
        $freespace->operation = 'OBSERVE_FREESPACE';
        $freespace->target = (object) ['service_id' => 'service'];
        yield 'freespace-with-service' => [$freespace];
        foreach ([null, [], true, 1, 'OBSERVE'] as $index => $value) {
            yield 'not-object-'.$index => [$value];
        }
        $hello = (object) ['client_nonce' => str_repeat('a', 64), 'message_type' => 'HELLO', 'protocol_version' => 1];
        foreach (array_keys(get_object_vars($hello)) as $field) {
            $invalid = clone $hello;
            unset($invalid->{$field});
            yield 'hello-missing-'.$field => [$invalid];
        }
        foreach ([null, 1, str_repeat('A', 64), str_repeat('a', 63)] as $index => $nonce) {
            $invalid = clone $hello;
            $invalid->client_nonce = $nonce;
            yield 'hello-nonce-'.$index => [$invalid];
        }
        $hello->session_id = str_repeat('b', 64);
        yield 'hello-extra' => [$hello];
    }

    #[DataProvider('invalidWireMessages')]
    public function test_schema_entry_point_cannot_bypass_canonical_validation(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProtocolValidator)->validate($payload);
    }

    public static function invalidWireMessages(): iterable
    {
        $payload = (new CanonicalJsonCodec)->encode(self::observe());
        yield 'duplicate' => [str_replace('"sequence":1', '"sequence":1,"sequence":1', $payload)];
        yield 'float' => [str_replace('"sequence":1', '"sequence":1.0', $payload)];
        yield 'negative' => [str_replace('"sequence":1', '"sequence":-1', $payload)];
        yield 'exponent' => [str_replace('"sequence":1', '"sequence":1e0', $payload)];
        yield 'overflow' => [str_replace('"sequence":1', '"sequence":2147483648', $payload)];
        yield 'whitespace' => [' '.$payload];
        yield 'unicode-escape' => [str_replace('root_1', '\u0072oot_1', $payload)];
        yield 'windows-path' => [str_replace('root_1', 'C:\\\\root', $payload)];
    }
}
