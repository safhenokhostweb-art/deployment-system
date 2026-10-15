<?php

declare(strict_types=1);

namespace App\Sentinel\ModelB\Synthetic;

use InvalidArgumentException;

/** One frame per instance. Any failure permanently poisons the decoder. */
final class FrameDecoder
{
    private string $buffer = '';

    private ?int $payloadLength = null;

    private bool $failed = false;

    private bool $finished = false;

    private mixed $value = null;

    private bool $complete = false;

    public function __construct(private readonly CanonicalJsonCodec $codec = new CanonicalJsonCodec) {}

    public function encode(string $payload): string
    {
        $this->codec->decode($payload);

        return pack('N', strlen($payload)).$payload;
    }

    public function decode(string $frame): mixed
    {
        $this->push($frame);

        return $this->finish();
    }

    /** Feed chunks without treating a temporarily partial frame as malformed. */
    public function push(string $chunk): void
    {
        try {
            if ($this->failed || $this->finished) {
                throw new InvalidArgumentException('FRAME_DECODER_CLOSED');
            }
            $size = strlen($this->buffer) + strlen($chunk);
            if ($size > CanonicalJsonCodec::MAX_PAYLOAD_BYTES + 4) {
                throw new InvalidArgumentException('FRAME_SIZE_INVALID');
            }
            $this->buffer .= $chunk;
            if ($this->payloadLength === null && $size >= 4) {
                // Check high bytes before unpacking, avoiding platform-dependent overflow.
                if ($this->buffer[0] !== "\0" || $this->buffer[1] !== "\0") {
                    throw new InvalidArgumentException('FRAME_SIZE_INVALID');
                }
                $this->payloadLength = ord($this->buffer[2]) * 256 + ord($this->buffer[3]);
                if ($this->payloadLength < 1
                    || $this->payloadLength > CanonicalJsonCodec::MAX_PAYLOAD_BYTES) {
                    throw new InvalidArgumentException('FRAME_SIZE_INVALID');
                }
            }
            if ($this->payloadLength !== null) {
                if ($size > $this->payloadLength + 4) {
                    throw new InvalidArgumentException('FRAME_TRAILING_BYTES');
                }
                if ($size === $this->payloadLength + 4 && ! $this->complete) {
                    $this->value = $this->codec->decode(substr($this->buffer, 4));
                    $this->complete = true;
                }
            }
        } catch (InvalidArgumentException $exception) {
            $this->failed = true;
            $this->buffer = '';
            $this->value = null;
            throw $exception;
        }
    }

    /** End of input: incomplete headers/payloads fail here, not on a split chunk. */
    public function finish(): mixed
    {
        if ($this->failed || $this->finished || ! $this->complete) {
            $this->failed = true;
            $this->buffer = '';
            $this->value = null;
            throw new InvalidArgumentException('FRAME_INCOMPLETE_OR_CLOSED');
        }
        $this->finished = true;
        $this->buffer = '';
        $value = $this->value;
        $this->value = null;

        return $value;
    }
}
