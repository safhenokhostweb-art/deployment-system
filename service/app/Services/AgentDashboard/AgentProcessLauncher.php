<?php

namespace App\Services\AgentDashboard;

/**
 * Starts, probes and stops detached agent runtime processes. A seam so the
 * dashboard's feature tests never spawn real processes.
 */
interface AgentProcessLauncher
{
    /**
     * Start $command (argv, never a shell string) detached from the current
     * request, with stdout and stderr appended to $logFile.
     *
     * @param  list<string>  $command
     * @return int the PID of the started process
     */
    public function start(array $command, string $workingDirectory, string $logFile): int;

    public function isRunning(int $pid): bool;

    /** Terminate the process and its children. */
    public function stop(int $pid): bool;
}
