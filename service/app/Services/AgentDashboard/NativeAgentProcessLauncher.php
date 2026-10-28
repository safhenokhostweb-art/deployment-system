<?php

namespace App\Services\AgentDashboard;

use RuntimeException;

/**
 * Windows: proc_open with an argv array (no cmd.exe), so the reported PID is
 * the agent's own php.exe. It is never proc_close()d, since that waits for
 * the run to finish; freeing the resource does not.
 *
 * POSIX: a short-lived `sh` backgrounds the agent and prints its PID, then
 * exits. The agent is re-parented to init, so it never lingers as a zombie
 * of the PHP worker (a zombie would still answer "running" to kill -0).
 */
class NativeAgentProcessLauncher implements AgentProcessLauncher
{
    public function start(array $command, string $workingDirectory, string $logFile): int
    {
        return PHP_OS_FAMILY === 'Windows'
            ? $this->startWindows($command, $workingDirectory, $logFile)
            : $this->startPosix($command, $workingDirectory, $logFile);
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('tasklist /FI "PID eq '.$pid.'" /FO CSV /NH 2>NUL', $output);

            // A recycled PID belongs to another program; only php is ours.
            return stripos(implode("\n", $output), '"php') !== false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }

    public function stop(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /PID '.$pid.' /T /F 2>NUL', $output, $exitCode);

            return $exitCode === 0;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 15);
        }
        exec('kill -TERM '.$pid.' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    /** @param list<string> $command */
    private function startWindows(array $command, string $workingDirectory, string $logFile): int
    {
        $process = proc_open(
            $command,
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the agent process.');
        }
        $status = proc_get_status($process);

        return (int) $status['pid'];
    }

    /** @param list<string> $command */
    private function startPosix(array $command, string $workingDirectory, string $logFile): int
    {
        $script = '"$@" </dev/null >>'.escapeshellarg($logFile).' 2>&1 & echo $!';
        $process = proc_open(
            array_merge(['/bin/sh', '-c', $script, 'sh'], $command),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $workingDirectory,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the agent process.');
        }
        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);
        if ($pid <= 0) {
            throw new RuntimeException('The agent process did not report a PID.');
        }

        return $pid;
    }
}
