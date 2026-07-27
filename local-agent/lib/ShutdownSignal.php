<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 5.3: the pure logic behind graceful shutdown, pulled out of
 * agent.php's registerGracefulShutdown() so it's directly testable —
 * that function itself just wires this into
 * sapi_windows_set_ctrl_handler()/pcntl_signal(), which can't be
 * exercised outside of a real OS signal.
 */
final class ShutdownSignal
{
    /**
     * @param bool $shuttingDown passed by reference so the caller's own
     *     loop-control variable flips
     */
    public static function handle(Logger $logger, bool &$shuttingDown, string $source, int $code): void
    {
        $logger->info("Shutdown signal received ({$source} {$code}) — finishing the current cycle, then exiting.");
        $shuttingDown = true;
    }
}
