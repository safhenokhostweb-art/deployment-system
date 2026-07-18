<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Runs one pipeline step as a local shell command in the project's working
 * directory. The agent runs ON the target server, so this never needs SSH.
 */
final class Executor
{
    /**
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    public function run(string $command, string $workingDirectory, int $timeoutSeconds): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $start = microtime(true);
        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null);

        if (!is_resource($process)) {
            return [
                'exitCode' => 255,
                'output' => "Failed to start process for command: {$command}",
                'timedOut' => false,
                'durationMs' => 0,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);

            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

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

        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $timedOut ? 124 : proc_close($process);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

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
}
