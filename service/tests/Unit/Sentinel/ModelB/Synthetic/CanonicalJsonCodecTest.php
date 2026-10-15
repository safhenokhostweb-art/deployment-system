<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\CanonicalJsonCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CanonicalJsonCodecTest extends TestCase
{
    #[DataProvider('canonicalValues')]
    public function test_canonical_values_round_trip(string $json): void
    {
        $codec = new CanonicalJsonCodec;
        self::assertSame($json, $codec->encode($codec->decode($json)));
    }

    public static function canonicalValues(): iterable
    {
        foreach (['{}', '[]', '0', '2147483647', 'true', 'false', 'null', '""',
            '"a / z"', '{"A":1,"a":2,"b":[true,false,null,{"x":0}]}',
            '{"0":0,"10":10,"2":2}', '{"":null,"a":{}}',
            str_repeat('[', 6).'0'.str_repeat(']', 6),
            str_repeat('{"a":', 6).'0'.str_repeat('}', 6),
            '"'.str_repeat('a', 16382).'"',
        ] as $index => $json) {
            yield 'canonical-'.$index => [$json];
        }
    }

    public function test_encoder_sorts_object_keys_without_conflating_objects_and_lists(): void
    {
        $codec = new CanonicalJsonCodec;
        $value = (object) ['z' => [], 'a' => (object) ['y' => false, 'b' => null]];
        self::assertSame('{"a":{"b":null,"y":false},"z":[]}', $codec->encode($value));
        self::assertInstanceOf(stdClass::class, $codec->decode('{}'));
        self::assertSame([], $codec->decode('[]'));
        self::assertNotSame($codec->encode(new stdClass), $codec->encode([]));
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_without_normalization(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CanonicalJsonCodec)->decode($json);
    }

    public static function invalidPayloads(): iterable
    {
        $vectors = [
            'empty' => '', 'duplicate' => '{"a":1,"a":2}',
            'nested-duplicate' => '{"a":{"x":1,"x":2}}',
            'duplicate-in-array' => '[{"x":1,"x":2}]',
            'out-of-order-duplicate' => '{"a":1,"b":2,"a":3}',
            'invalid-utf8' => '"'."\xc3\x28".'"',
            'unicode-character' => '"'."\xc3\xa9".'"',
            'bom' => "\xef\xbb\xbf{}", 'unicode-escape' => '"\u0061"',
            'escaped-key' => '{"\u0061":1}', 'escaped-slash' => '"\/"',
            'escaped-quote' => '"\""', 'escaped-backslash' => '"\\\\"',
            'escaped-newline' => '"\n"', 'raw-control' => '"'."\n".'"',
            'float' => '1.0', 'negative' => '-1', 'negative-zero' => '-0',
            'exponent' => '1e3', 'positive-exponent' => '1E+3',
            'leading-zero' => '01', 'plus-sign' => '+1',
            'overflow' => '2147483648', 'very-large-integer' => str_repeat('9', 100),
            'array-depth' => str_repeat('[', 7).'0'.str_repeat(']', 7),
            'object-depth' => str_repeat('{"a":', 7).'0'.str_repeat('}', 7),
            'mixed-depth' => '[{"a":[{"a":[{"a":[]}]}]}]',
            'oversize' => '"'.str_repeat('a', 16383).'"',
            'key-order' => '{"b":1,"a":2}', 'numeric-key-order' => '{"2":2,"10":10}',
            'leading-space' => ' {}', 'trailing-space' => '{} ',
            'inner-space' => '{"a": 1}', 'tab' => "[\t0]", 'newline' => "{}\n",
            'truncated-object' => '{"a":', 'truncated-array' => '[0',
            'truncated-string' => '"abc', 'missing-colon' => '{"a"1}',
            'missing-comma' => '{"a":1"b":2}', 'array-trailing-comma' => '[0,]',
            'object-trailing-comma' => '{"a":0,}', 'non-string-key' => '{1:0}',
            'two-values' => '{}{}', 'literal-suffix' => 'truefalse',
            'wrong-literal' => 'True', 'invalid-token' => 'undefined',
            'wrong-delimiter' => '{"a":1]', 'comment' => '/*x*/{}',
            'nul' => "{}\0",
        ];
        foreach ($vectors as $name => $json) {
            yield $name => [$json];
        }
    }

    #[DataProvider('invalidValues')]
    public function test_encoder_rejects_unrepresentable_values(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CanonicalJsonCodec)->encode($value);
    }

    public static function invalidValues(): iterable
    {
        yield 'float' => [1.0];
        yield 'negative' => [-1];
        yield 'overflow' => [2147483648];
        yield 'associative-array' => [['a' => 1]];
        yield 'sparse-array' => [[1 => 'a']];
        yield 'quote' => ['a"b'];
        yield 'backslash' => ['a\\b'];
        yield 'control' => ["a\nb"];
        yield 'unicode' => ["\xc3\xa9"];
        yield 'oversize-string' => [str_repeat('a', 16383)];
        yield 'aggregate-oversize' => [[str_repeat('a', 8192), str_repeat('b', 8192)]];
        yield 'invalid-key' => [(object) ["\n" => 1]];
        $deep = 0;
        for ($index = 0; $index < 7; $index++) {
            $deep = [$deep];
        }
        yield 'depth' => [$deep];
        $cycle = new stdClass;
        $cycle->a = $cycle;
        yield 'cycle' => [$cycle];
        yield 'unsupported-object' => [new class {}];
    }

    public function test_rejected_decode_does_not_poison_stateless_codec(): void
    {
        $codec = new CanonicalJsonCodec;
        try {
            $codec->decode('{"a":1,"a":2}');
            self::fail('Duplicate keys were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('JSON_DUPLICATE_KEY', $exception->getMessage());
        }
        self::assertSame('{"a":1}', $codec->encode($codec->decode('{"a":1}')));
    }

    public function test_very_wide_object_is_rejected_with_bounded_preparation_memory(): void
    {
        $object = new stdClass;
        for ($index = 0; $index < 100000; $index++) {
            $object->{'k'.str_pad((string) $index, 6, '0', STR_PAD_LEFT)} = 0;
        }
        $codec = new CanonicalJsonCodec;
        $baselineBytes = memory_get_usage(false);
        memory_reset_peak_usage();
        try {
            $codec->encode($object);
            self::fail('An oversized object was accepted.');
        } catch (InvalidArgumentException $exception) {
            $extraBytes = memory_get_peak_usage(false) - $baselineBytes;
            self::assertSame('PAYLOAD_SIZE_INVALID', $exception->getMessage());
            // Input allocation is excluded; copying/sorting all 100,000 properties is not.
            self::assertLessThan(2 * 1024 * 1024, $extraBytes);
        }
    }

    public function test_object_budget_is_checked_before_sorting_or_encoding_children(): void
    {
        $object = new stdClass;
        $object->a = 'invalid"child';
        for ($index = 0; $index < 10000; $index++) {
            $object->{'k'.$index} = 0;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYLOAD_SIZE_INVALID');
        // Previously sorting first would encode "a" and fail with JSON_STRING_INVALID.
        (new CanonicalJsonCodec)->encode($object);
    }

    public function test_boundary_size_object_value_preserves_canonical_output(): void
    {
        $text = str_repeat('x', 16376);
        $expected = '{"a":"'.$text.'"}';
        $codec = new CanonicalJsonCodec;
        self::assertSame(16384, strlen($expected));
        self::assertSame($expected, $codec->encode((object) ['a' => $text]));
        self::assertSame($expected, $codec->encode($codec->decode($expected)));
    }

    public function test_boundary_size_object_key_is_not_rejected_by_preparation_budget(): void
    {
        $key = str_repeat('a', 16378);
        $expected = '{"'.$key.'":0}';
        self::assertSame(16384, strlen($expected));
        self::assertSame($expected, (new CanonicalJsonCodec)->encode((object) [$key => 0]));
    }

    public function test_oversized_key_is_rejected_before_child_encoding(): void
    {
        $object = (object) [str_repeat('a', 16379) => 'invalid"child'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYLOAD_SIZE_INVALID');
        (new CanonicalJsonCodec)->encode($object);
    }

    public function test_bounded_key_collection_preserves_nested_ascii_sorting(): void
    {
        $object = (object) ['z' => 0, 'a' => (object) ['2' => 2, '10' => 10, '0' => 0]];
        self::assertSame('{"a":{"0":0,"10":10,"2":2},"z":0}', (new CanonicalJsonCodec)->encode($object));
    }
}
