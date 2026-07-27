<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 5.3: extracted out of agent.php so it's a pure, directly
 * testable function — the "fetch_logs" control action's own log-reading
 * logic, matching the exact filename Logger.php already writes to.
 */
final class LogTail
{
    /**
     * @return string the last $maxLines lines of today's log file, or a
     *     placeholder if it doesn't exist yet — never throws, since a
     *     missing/unreadable log is not itself a control-job failure.
     */
    public static function tail(string $logDir, int $maxLines): string
    {
        $path = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'local-agent-' . date('Y-m-d') . '.log';

        if (!is_file($path)) {
            return "(no log file yet at {$path})";
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $tail = array_slice($lines, -$maxLines);

        return implode("\n", $tail);
    }
}
