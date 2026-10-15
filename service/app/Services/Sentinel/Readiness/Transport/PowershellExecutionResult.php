<?php

declare(strict_types=1);

namespace App\Services\Sentinel\Readiness\Transport;

/**
 * PowershellExecutionResult — the immutable, sanitized result of one
 * PowerShell invocation with cooperative deadline checks (not a native bound).
 *
 * Nothing here is ever null: a probe that produced no answer is
 * represented by a non-COMPLETED classification, never by a silently
 * empty payload that could be mistaken for a successful empty
 * measurement.
 *
 * stdout and stderr are bounded during collection by the transport.
 * Overflow results discard both payloads and carry explicit cap markers.
 * stderr is retained only for classification and length checks on completed
 * results; it is never surfaced verbatim in reports,
 * because native error text may contain account, path or machine
 * identifiers.
 */
final class PowershellExecutionResult
{
    public const CLASS_COMPLETED = 'COMPLETED';
    public const CLASS_TIMEOUT = 'TIMEOUT';
    public const CLASS_LAUNCH_FAILED = 'LAUNCH_FAILED';
    public const CLASS_OUTPUT_OVERFLOW = 'OUTPUT_OVERFLOW';
    public const CLASS_STREAM_FAILED = 'STREAM_FAILED';
    public const CLASS_CLEANUP_FAILED = 'CLEANUP_FAILED';

    public const CLASSES = [
        self::CLASS_COMPLETED,
        self::CLASS_TIMEOUT,
        self::CLASS_LAUNCH_FAILED,
        self::CLASS_OUTPUT_OVERFLOW,
        self::CLASS_STREAM_FAILED,
        self::CLASS_CLEANUP_FAILED,
    ];

    /**
     * @param  string      $classification  one of PowershellExecutionResult::CLASS_*
     * @param  int|null    $exitCode        native exit code, or null when the
     *                                      process never produced one
     * @param  string      $stdout          already truncated, never null
     * @param  string      $stderr          already truncated, never null
     * @param  float       $elapsedSeconds  wall clock spent waiting
     * @param  int|null    $truncatedAt     the configured stdout cap, when hit
     */
    private function __construct(
        private readonly string $classification,
        private readonly ?int $exitCode,
        private readonly string $stdout,
        private readonly string $stderr,
        private readonly float $elapsedSeconds,
        private readonly ?int $truncatedAt,
        private readonly ?int $stderrTruncatedAt = null,
    ) {
        if (! in_array($classification, self::CLASSES, true)) {
            throw new \InvalidArgumentException(
                'PowershellExecutionResult classification must be one of: ' . implode(', ', self::CLASSES),
            );
        }
    }

    public static function completed(int $exitCode, string $stdout, string $stderr, float $elapsedSeconds, ?int $truncatedAt): self
    {
        return new self(self::CLASS_COMPLETED, $exitCode, $stdout, $stderr, $elapsedSeconds, $truncatedAt);
    }

    public static function timeout(float $elapsedSeconds): self
    {
        return new self(self::CLASS_TIMEOUT, null, '', '', $elapsedSeconds, null);
    }

    public static function launchFailed(float $elapsedSeconds): self
    {
        return new self(self::CLASS_LAUNCH_FAILED, null, '', '', $elapsedSeconds, null);
    }

    public static function outputOverflow(float $elapsedSeconds, ?int $stdoutCap, ?int $stderrCap): self
    {
        return new self(self::CLASS_OUTPUT_OVERFLOW, null, '', '', $elapsedSeconds, $stdoutCap, $stderrCap);
    }

    public static function streamFailed(float $elapsedSeconds): self
    {
        return new self(self::CLASS_STREAM_FAILED, null, '', '', $elapsedSeconds, null);
    }

    public static function cleanupFailed(float $elapsedSeconds): self
    {
        return new self(self::CLASS_CLEANUP_FAILED, null, '', '', $elapsedSeconds, null);
    }

    public function stderrTruncatedAt(): ?int
    {
        return $this->stderrTruncatedAt;
    }

    public function classification(): string
    {
        return $this->classification;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function elapsedSeconds(): float
    {
        return $this->elapsedSeconds;
    }

    public function truncatedAt(): ?int
    {
        return $this->truncatedAt;
    }

    /**
     * True only when the process ran to completion with a zero exit
     * code. Everything else — timeout, launch failure, nonzero exit —
     * is a probe failure and must be reported as UNKNOWN, never as a
     * measurement.
     */
    public function isCleanExit(): bool
    {
        return $this->classification === self::CLASS_COMPLETED
            && $this->exitCode === 0
            && $this->truncatedAt === null;
    }

    /**
     * A stable observation code for this outcome, suitable for the
     * reasonCode chain without exposing native text.
     */
    public function observationCode(): string
    {
        if ($this->classification === self::CLASS_STREAM_FAILED) {
            return 'powershell_stream_failed';
        }
        if ($this->classification === self::CLASS_CLEANUP_FAILED) {
            return 'powershell_cleanup_failed';
        }
        if ($this->classification === self::CLASS_OUTPUT_OVERFLOW) {
            return $this->stderrTruncatedAt !== null ? 'powershell_stderr_overflow' : 'powershell_stdout_overflow';
        }

        if ($this->classification === self::CLASS_TIMEOUT) {
            return 'powershell_timeout';
        }

        if ($this->classification === self::CLASS_LAUNCH_FAILED) {
            return 'powershell_launch_failed';
        }

        return $this->exitCode === 0
            ? 'powershell_completed'
            : 'powershell_nonzero_exit';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification,
            'exitCode' => $this->exitCode,
            'stdoutLength' => strlen($this->stdout),
            'stderrLength' => strlen($this->stderr),
        ];
    }
}
