<?php
declare(strict_types=1);
namespace LocalAgent;

/** Marker for a bounded, explicitly-classified outbound retry. */
class RetryableOperationException extends \RuntimeException {}
final class RetryableHubException extends RetryableOperationException {}

/** Shared, bounded retry policy for outbound Hub calls. */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 3;
    /** @var \Closure(int):void|null */
    private readonly ?\Closure $sleeper;
    /** @param callable(int):void|null $sleeper */
    public function __construct(?callable $sleeper = null) { $this->sleeper = $sleeper === null ? null : \Closure::fromCallable($sleeper); }
    public static function isRetryableStatus(int $status): bool { return $status === 429 || in_array($status, [500, 502, 503, 504], true); }
    public function run(callable $operation): mixed
    {
        $last = null;
        foreach ([1, 2, 3] as $attempt) {
            try { return $operation($attempt); } catch (RetryableOperationException $e) { $last = $e; if ($attempt === self::MAX_ATTEMPTS) break; $delay = $attempt === 1 ? 2 : 5; if ($this->sleeper !== null) ($this->sleeper)($delay); else sleep($delay); }
        }
        throw new \RuntimeException('Hub request failed after ' . self::MAX_ATTEMPTS . ' transient attempt(s): ' . ($last?->getMessage() ?? 'unknown failure'), 0, $last);
    }
}
