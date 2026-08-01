<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Runs one pipeline step as a local shell command in the project's working
 * directory. The agent runs ON the target server, so this never needs SSH.
 *
 * Root-cause fix: stdout/stderr are redirected to temp FILES rather than
 * pipes. The previous pipe-based implementation called
 * `stream_set_blocking($pipe, false)` before polling them in the wait
 * loop — on Windows that call silently fails (returns false, pipe stays
 * blocking), so `stream_get_contents()` on a pipe with nothing written
 * yet blocks the entire wait loop, including the timeout-deadline check,
 * for as long as the child stays silent. Confirmed by direct testing
 * elsewhere in this project against the Local Agent's equivalent
 * GitManager class, which had the identical pattern. A file read never
 * blocks waiting for more data, so the fix applies regardless of which OS
 * this Agent ultimately runs on.
 */
final class Executor
{
    /** Hard cap on accumulated stdout+stderr per step, so an unexpectedly chatty command can't exhaust memory. */
    private const MAX_OUTPUT_BYTES = 5_000_000;

    /**
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    public function run(string $command, string $workingDirectory, int $timeoutSeconds): array
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'da-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'da-err-');

        if ($stdoutPath === false || $stderrPath === false) {
            return [
                'exitCode' => 255,
                'output' => 'Could not allocate a temp file for command output.',
                'timedOut' => false,
                'durationMs' => 0,
            ];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];

        $start = microtime(true);
        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null);

        if (!is_resource($process)) {
            self::cleanup($stdoutPath, $stderrPath);

            return [
                'exitCode' => 255,
                'output' => "Failed to start process for command: {$command}",
                'timedOut' => false,
                'durationMs' => 0,
            ];
        }

        fclose($pipes[0]);

        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(200000);
                proc_terminate($process, 9);
                break;
            }

            usleep(150000);
        }

        $exitCode = $timedOut ? 124 : proc_close($process);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $output = self::readCapped($stdoutPath) . self::readCapped($stderrPath);
        self::cleanup($stdoutPath, $stderrPath);

        if ($timedOut) {
            $output .= "\n[agent] step exceeded {$timeoutSeconds}s timeout and was terminated\n";
        }

        return [
            'exitCode' => $exitCode,
            'output' => $output,
            'timedOut' => $timedOut,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Reads at most MAX_OUTPUT_BYTES from a file — an unexpectedly huge
     * step output must never be loaded fully into memory just to then
     * get truncated.
     */
    private static function readCapped(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        if ($size <= self::MAX_OUTPUT_BYTES) {
            return (string) file_get_contents($path);
        }

        $partial = file_get_contents($path, false, null, 0, self::MAX_OUTPUT_BYTES);

        return (string) $partial . "\n[agent] output truncated at " . self::MAX_OUTPUT_BYTES . " bytes\n";
    }

    private static function cleanup(string $stdoutPath, string $stderrPath): void
    {
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}
