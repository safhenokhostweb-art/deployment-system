<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Pure parsing of `tasklist /FI "PID eq <pid>" /FO CSV /NH` output — no
 * process/OS dependency, so it's directly unit-testable against fixture
 * strings. The watchdog uses this to confirm a PID it read from
 * agent.pid still actually belongs to a php.exe process before ever
 * tree-killing it — a stale/reused PID must never let the watchdog kill
 * an unrelated process.
 */
final class TaskListParser
{
    public static function isImageName(string $tasklistCsvOutput, string $expectedImageName): bool
    {
        $trimmed = trim($tasklistCsvOutput);

        if ($trimmed === '' || stripos($trimmed, 'No tasks') !== false) {
            return false;
        }

        $firstLine = strtok($trimmed, "\r\n");
        $firstField = explode(',', $firstLine, 2)[0] ?? '';
        $imageName = trim($firstField, "\"");

        return strcasecmp($imageName, $expectedImageName) === 0;
    }
}
