<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;
use stdClass;

/** In-memory canonical JSON only; parsing never normalizes wire input. */
final class CanonicalJsonCodec
{
    public const MAX_PAYLOAD_BYTES = 16384;

    public const MAX_CONTAINER_DEPTH = 6;

    public const MAX_INTEGER = 2147483647;

    public function decode(string $payload): mixed
    {
        $length = strlen($payload);
        if ($length < 1 || $length > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
        }

        $offset = 0;
        $value = $this->parseValue($payload, $offset, 0);
        if ($offset !== $length) {
            throw new InvalidArgumentException('JSON_TRAILING_BYTES');
        }

        return $value;
    }

    /** Objects use stdClass; PHP lists represent JSON arrays. */
    public function encode(mixed $value): string
    {
        $output = '';
        $this->writeValue($value, $output, 0);

        return $output;
    }

    private function parseValue(string $input, int &$offset, int $depth): mixed
    {
        $byte = $input[$offset] ?? '';
        if ($byte === '{' || $byte === '[') {
            if ($depth >= self::MAX_CONTAINER_DEPTH) {
                throw new InvalidArgumentException('JSON_DEPTH_EXCEEDED');
            }

            return $byte === '{'
                ? $this->parseObject($input, $offset, $depth + 1)
                : $this->parseArray($input, $offset, $depth + 1);
        }
        if ($byte === '"') {
            return $this->parseString($input, $offset);
        }
        if ($byte !== '' && $byte >= '0' && $byte <= '9') {
            $start = $offset;
            do {
                $offset++;
                $next = $input[$offset] ?? '';
            } while ($next !== '' && $next >= '0' && $next <= '9');

            $digits = substr($input, $start, $offset - $start);
            if ((strlen($digits) > 1 && $digits[0] === '0')
                || strlen($digits) > 10
                || (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)) {
                throw new InvalidArgumentException('JSON_INTEGER_INVALID');
            }

            return (int) $digits;
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $literal => $value) {
            if (substr($input, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return $value;
            }
        }

        throw new InvalidArgumentException('JSON_TOKEN_INVALID');
    }

    private function parseObject(string $input, int &$offset, int $depth): stdClass
    {
        $offset++;
        $object = new stdClass;
        if (($input[$offset] ?? '') === '}') {
            $offset++;

            return $object;
        }

        $previous = null;
        while (true) {
            if (($input[$offset] ?? '') !== '"') {
                throw new InvalidArgumentException('JSON_OBJECT_KEY_INVALID');
            }
            $key = $this->parseString($input, $offset);
            if (property_exists($object, $key)) {
                throw new InvalidArgumentException('JSON_DUPLICATE_KEY');
            }
            if ($previous !== null && strcmp($previous, $key) >= 0) {
                throw new InvalidArgumentException('JSON_KEY_ORDER_INVALID');
            }
            if (($input[$offset] ?? '') !== ':') {
                throw new InvalidArgumentException('JSON_COLON_REQUIRED');
            }
            $offset++;
            $object->{$key} = $this->parseValue($input, $offset, $depth);
            $previous = $key;
            $delimiter = $input[$offset] ?? '';
            $offset++;
            if ($delimiter === '}') {
                return $object;
            }
            if ($delimiter !== ',') {
                throw new InvalidArgumentException('JSON_OBJECT_DELIMITER_INVALID');
            }
        }
    }

    private function parseArray(string $input, int &$offset, int $depth): array
    {
        $offset++;
        $values = [];
        if (($input[$offset] ?? '') === ']') {
            $offset++;

            return $values;
        }
        while (true) {
            $values[] = $this->parseValue($input, $offset, $depth);
            $delimiter = $input[$offset] ?? '';
            $offset++;
            if ($delimiter === ']') {
                return $values;
            }
            if ($delimiter !== ',') {
                throw new InvalidArgumentException('JSON_ARRAY_DELIMITER_INVALID');
            }
        }
    }

    private function parseString(string $input, int &$offset): string
    {
        $offset++;
        $start = $offset;
        $length = strlen($input);
        while ($offset < $length) {
            $byte = $input[$offset];
            if ($byte === '"') {
                $value = substr($input, $start, $offset - $start);
                $offset++;

                return $value;
            }
            $code = ord($byte);
            if ($byte === '\\' || $code < 32 || $code > 127) {
                throw new InvalidArgumentException('JSON_STRING_INVALID');
            }
            $offset++;
        }

        throw new InvalidArgumentException('JSON_STRING_UNTERMINATED');
    }

    private function writeValue(mixed $value, string &$output, int $depth): void
    {
        if ($value === null || is_bool($value)) {
            $this->append($output, $value === null ? 'null' : ($value ? 'true' : 'false'));

            return;
        }
        if (is_int($value)) {
            if ($value < 0 || $value > self::MAX_INTEGER) {
                throw new InvalidArgumentException('JSON_INTEGER_INVALID');
            }
            $this->append($output, (string) $value);

            return;
        }
        if (is_string($value)) {
            $this->writeString($value, $output);

            return;
        }
        if ($value instanceof stdClass && get_class($value) === stdClass::class) {
            $this->checkDepth($depth);
            $keys = $this->boundedObjectKeys($value, self::MAX_PAYLOAD_BYTES - strlen($output));
            sort($keys, SORT_STRING);
            $this->append($output, '{');
            $first = true;
            foreach ($keys as $key) {
                if (! $first) {
                    $this->append($output, ',');
                }
                $this->writeString($key, $output);
                $this->append($output, ':');
                $this->writeValue($value->{$key}, $output, $depth + 1);
                $first = false;
            }
            $this->append($output, '}');

            return;
        }
        if (is_array($value) && array_is_list($value)) {
            $this->checkDepth($depth);
            $this->append($output, '[');
            foreach ($value as $index => $child) {
                if ($index > 0) {
                    $this->append($output, ',');
                }
                $this->writeValue($child, $output, $depth + 1);
            }
            $this->append($output, ']');

            return;
        }

        throw new InvalidArgumentException('JSON_VALUE_TYPE_INVALID');
    }

    /** Collect only keys whose minimum possible encoding fits the remaining budget. */
    private function boundedObjectKeys(stdClass $object, int $remainingBytes): array
    {
        $keys = [];
        $minimumBytes = 2; // Opening and closing braces.
        if ($minimumBytes > $remainingBytes) {
            throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
        }
        // Direct iteration avoids materializing the object's entire property table.
        foreach ($object as $key => $unused) {
            $key = (string) $key;
            $keyBytes = strlen($key);
            if ($keyBytes > $remainingBytes) {
                throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
            }
            // Two quotes, a colon, and at least one byte for the child value.
            $minimumBytes += $keyBytes + 4 + ($keys === [] ? 0 : 1);
            if ($minimumBytes > $remainingBytes) {
                throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private function writeString(string $value, string &$output): void
    {
        if (strlen($value) > self::MAX_PAYLOAD_BYTES - 2) {
            throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
        }
        if (preg_match('/[\x00-\x1f\x80-\xff"\\\\]/', $value) === 1) {
            throw new InvalidArgumentException('JSON_STRING_INVALID');
        }
        $this->append($output, '"'.$value.'"');
    }

    private function append(string &$output, string $bytes): void
    {
        if (strlen($output) + strlen($bytes) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('PAYLOAD_SIZE_INVALID');
        }
        $output .= $bytes;
    }

    private function checkDepth(int $depth): void
    {
        if ($depth >= self::MAX_CONTAINER_DEPTH) {
            throw new InvalidArgumentException('JSON_DEPTH_EXCEEDED');
        }
    }
}
