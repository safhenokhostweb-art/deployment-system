<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;
use stdClass;

/** Syntax/schema only. A validated request grants no operational authorization. */
final class ProtocolValidator
{
    public function __construct(private readonly CanonicalJsonCodec $codec = new CanonicalJsonCodec) {}

    public function validate(string $payload): stdClass
    {
        $message = $this->codec->decode($payload);
        if (! $message instanceof stdClass) {
            throw new InvalidArgumentException('MESSAGE_OBJECT_REQUIRED');
        }
        if (! property_exists($message, 'protocol_version')
            || ! is_int($message->protocol_version)) {
            throw new InvalidArgumentException('PROTOCOL_VERSION_TYPE_INVALID');
        }
        if ($message->protocol_version !== 1) {
            throw new InvalidArgumentException('UNSUPPORTED_PROTOCOL');
        }
        if (! property_exists($message, 'message_type') || ! is_string($message->message_type)) {
            throw new InvalidArgumentException('MESSAGE_TYPE_INVALID');
        }

        if ($message->message_type === 'HELLO') {
            $this->requireFields($message, ['client_nonce', 'message_type', 'protocol_version']);
            $this->requireHex($message->client_nonce);

            return $message;
        }
        if ($message->message_type !== 'OBSERVE') {
            // SESSION and OBSERVATION_RESPONSE are deferred, never partially accepted.
            throw new InvalidArgumentException('MESSAGE_TYPE_UNSUPPORTED');
        }
        $this->requireFields($message, [
            'message_type', 'operation', 'policy_digest', 'policy_version',
            'protocol_version', 'request_id', 'sequence', 'session_id', 'target', 'timeout_ms',
        ]);
        $this->requireHex($message->request_id);
        $this->requireHex($message->session_id);
        $this->requireHex($message->policy_digest);
        foreach ([$message->sequence, $message->policy_version] as $integer) {
            if (! is_int($integer) || $integer < 1 || $integer > CanonicalJsonCodec::MAX_INTEGER) {
                throw new InvalidArgumentException('POSITIVE_INTEGER_REQUIRED');
            }
        }
        if (! is_int($message->timeout_ms) || $message->timeout_ms < 1 || $message->timeout_ms > 5000) {
            throw new InvalidArgumentException('TIMEOUT_INVALID');
        }
        if (! is_string($message->operation)) {
            throw new InvalidArgumentException('OPERATION_INVALID');
        }
        $targetKey = match ($message->operation) {
            'OBSERVE_FILESYSTEM', 'OBSERVE_FREESPACE' => 'root_id',
            'OBSERVE_SERVICE_STATUS' => 'service_id',
            default => throw new InvalidArgumentException('OPERATION_INVALID'),
        };
        if (! $message->target instanceof stdClass) {
            throw new InvalidArgumentException('TARGET_OBJECT_REQUIRED');
        }
        $this->requireFields($message->target, [$targetKey]);
        $resourceId = $message->target->{$targetKey};
        if (! is_string($resourceId) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $resourceId) !== 1) {
            throw new InvalidArgumentException('RESOURCE_IDENTIFIER_INVALID');
        }

        return $message;
    }

    private function requireFields(stdClass $object, array $expected): void
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('SCHEMA_FIELDS_INVALID');
        }
    }

    private function requireHex(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('HEX_IDENTIFIER_INVALID');
        }
    }
}
