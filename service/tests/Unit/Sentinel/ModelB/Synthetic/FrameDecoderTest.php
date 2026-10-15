<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel\ModelB\Synthetic;

use App\Sentinel\ModelB\Synthetic\CanonicalJsonCodec;
use App\Sentinel\ModelB\Synthetic\FrameDecoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameDecoderTest extends TestCase
{
    public function test_frame_encoding_is_big_endian(): void
    {
        $decoder = new FrameDecoder;
        self::assertSame("\0\0\0\7".'{"a":1}', $decoder->encode('{"a":1}'));
        self::assertSame(1, $decoder->decode($decoder->encode('{"a":1}'))->a);
    }

    #[DataProvider('splitOffsets')]
    public function test_every_header_and_payload_split_is_accepted(int $offset): void
    {
        $frame = pack('N', 7).'{"a":1}';
        $decoder = new FrameDecoder;
        $decoder->push(substr($frame, 0, $offset));
        $decoder->push(substr($frame, $offset));
        self::assertSame(1, $decoder->finish()->a);
    }

    public static function splitOffsets(): iterable
    {
        for ($offset = 0; $offset <= 11; $offset++) {
            yield 'split-'.$offset => [$offset];
        }
    }

    public function test_byte_by_byte_input_and_null_value(): void
    {
        $decoder = new FrameDecoder;
        foreach (str_split(pack('N', 4).'null') as $byte) {
            $decoder->push($byte);
        }
        self::assertNull($decoder->finish());
    }

    public function test_payload_length_boundaries(): void
    {
        $codec = new FrameDecoder;
        self::assertSame("\0\0\0\1".'0', $codec->encode('0'));
        self::assertSame(0, $codec->decode($codec->encode('0')));
        $payload = '"'.str_repeat('a', 16382).'"';
        $frame = $codec->encode($payload);
        self::assertSame("\0\0\x40\0", substr($frame, 0, 4));
        self::assertSame(16388, strlen($frame));
        // Independent frames require independent decoder instances.
        self::assertSame(str_repeat('a', 16382), (new FrameDecoder)->decode($frame));
    }

    #[DataProvider('invalidFrames')]
    public function test_invalid_complete_or_truncated_frames_are_rejected(string $frame): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FrameDecoder)->decode($frame);
    }

    public static function invalidFrames(): iterable
    {
        $vectors = [
            'empty' => '', 'header-one-byte' => "\0", 'header-two-bytes' => "\0\0",
            'header-three-bytes' => "\0\0\0", 'zero-length' => pack('N', 0),
            'oversized-header' => pack('N', 16385),
            'uint32-max' => "\xff\xff\xff\xff", 'little-endian' => "\1\0\0\0".'0',
            'missing-payload' => pack('N', 1), 'partial-payload' => pack('N', 7).'{"a":',
            'extra-byte' => pack('N', 1).'0x',
            'two-frames' => pack('N', 1).'0'.pack('N', 1).'0',
            'malformed-json' => pack('N', 1).'{',
            'duplicate-json' => pack('N', 13).'{"a":1,"a":2}',
            'whitespace-json' => pack('N', 3).' {}',
            'oversized-buffer' => pack('N', 16384).str_repeat('a', 16385),
        ];
        foreach ($vectors as $name => $frame) {
            yield $name => [$frame];
        }
    }

    public function test_finish_rejects_truncated_incremental_payload(): void
    {
        $decoder = new FrameDecoder;
        $decoder->push("\0\0");
        $decoder->push("\0\7".'{"a":');
        $this->expectException(InvalidArgumentException::class);
        $decoder->finish();
    }

    public function test_trailing_chunk_poisoning_prevents_returning_previous_result(): void
    {
        $decoder = new FrameDecoder;
        $decoder->push(pack('N', 1).'0');
        try {
            $decoder->push('x');
            self::fail('Trailing bytes were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('FRAME_TRAILING_BYTES', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $decoder->finish();
    }

    public function test_decoder_cannot_be_reused_after_finish(): void
    {
        $decoder = new FrameDecoder;
        $decoder->push(pack('N', 1).'0');
        self::assertSame(0, $decoder->finish());
        $this->expectException(InvalidArgumentException::class);
        $decoder->push(pack('N', 1).'0');
    }

    public function test_failed_partial_header_cannot_be_completed_later(): void
    {
        $decoder = new FrameDecoder;
        $decoder->push("\0");
        try {
            $decoder->finish();
            self::fail('Incomplete header was accepted.');
        } catch (InvalidArgumentException) {
            // The failure is terminal, even if the remaining bytes arrive later.
        }
        $this->expectException(InvalidArgumentException::class);
        $decoder->push("\0\0\1".'0');
    }

    #[DataProvider('invalidPayloadsForEncoding')]
    public function test_frame_encoder_checks_canonical_payload(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FrameDecoder)->encode($payload);
    }

    public static function invalidPayloadsForEncoding(): iterable
    {
        yield 'empty' => [''];
        yield 'noncanonical' => [' {}'];
        yield 'duplicate' => ['{"a":1,"a":2}'];
        yield 'oversize' => ['"'.str_repeat('a', CanonicalJsonCodec::MAX_PAYLOAD_BYTES - 1).'"'];
    }

    public function test_decode_cannot_reset_failed_push(): void
    {
        $decoder = new FrameDecoder;
        try {
            $decoder->push(pack('N', 0));
            self::fail('A zero-length frame was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('FRAME_SIZE_INVALID', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAME_DECODER_CLOSED');
        $decoder->decode(pack('N', 1).'0');
    }

    public function test_decode_cannot_reset_successful_finish(): void
    {
        $decoder = new FrameDecoder;
        $decoder->push(pack('N', 1).'0');
        self::assertSame(0, $decoder->finish());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAME_DECODER_CLOSED');
        $decoder->decode(pack('N', 1).'0');
    }

    #[DataProvider('failedDecodeFrames')]
    public function test_decode_cannot_reset_failed_decode(string $frame, string $reason): void
    {
        $decoder = new FrameDecoder;
        try {
            $decoder->decode($frame);
            self::fail('An invalid frame was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAME_DECODER_CLOSED');
        $decoder->decode(pack('N', 1).'0');
    }

    public static function failedDecodeFrames(): iterable
    {
        yield 'incomplete-header' => ["\0", 'FRAME_INCOMPLETE_OR_CLOSED'];
        yield 'invalid-length' => [pack('N', 0), 'FRAME_SIZE_INVALID'];
        yield 'invalid-payload' => [pack('N', 1).'{', 'JSON_OBJECT_KEY_INVALID'];
    }

    public function test_decode_cannot_reset_successful_decode(): void
    {
        $decoder = new FrameDecoder;
        self::assertSame(0, $decoder->decode(pack('N', 1).'0'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAME_DECODER_CLOSED');
        $decoder->decode(pack('N', 1).'0');
    }

    public function test_incremental_push_is_closed_after_successful_decode(): void
    {
        $decoder = new FrameDecoder;
        self::assertSame(0, $decoder->decode(pack('N', 1).'0'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAME_DECODER_CLOSED');
        $decoder->push(pack('N', 1).'0');
    }

    #[DataProvider('reuseMethods')]
    public function test_canonical_payload_failure_in_push_closes_every_input_path(string $method): void
    {
        $decoder = new FrameDecoder;
        $payload = '{"a":1,"a":2}';
        $decoder->push(substr(pack('N', strlen($payload)), 0, 2));
        try {
            $decoder->push(substr(pack('N', strlen($payload)), 2).$payload);
            self::fail('A noncanonical payload was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('JSON_DUPLICATE_KEY', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($method === 'finish' ? 'FRAME_INCOMPLETE_OR_CLOSED' : 'FRAME_DECODER_CLOSED');
        if ($method === 'finish') {
            $decoder->finish();
        } else {
            $decoder->{$method}(pack('N', 1).'0');
        }
    }

    public static function reuseMethods(): iterable
    {
        yield 'push' => ['push'];
        yield 'decode' => ['decode'];
        yield 'finish' => ['finish'];
    }
}
