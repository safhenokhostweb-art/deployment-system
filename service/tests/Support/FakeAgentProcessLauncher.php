<?php

namespace Tests\Support;

use App\Services\AgentDashboard\AgentProcessLauncher;

/** Records what the dashboard would start/stop; never spawns a process. */
class FakeAgentProcessLauncher implements AgentProcessLauncher
{
    /** @var list<array{command: list<string>, cwd: string, log: string, pid: int}> */
    public array $started = [];

    /** @var list<int> */
    public array $stopped = [];

    /** @var array<int, true> */
    public array $alive = [];

    private int $nextPid = 4200;

    public function start(array $command, string $workingDirectory, string $logFile): int
    {
        $pid = $this->nextPid++;
        $this->started[] = ['command' => $command, 'cwd' => $workingDirectory, 'log' => $logFile, 'pid' => $pid];
        $this->alive[$pid] = true;

        return $pid;
    }

    public function isRunning(int $pid): bool
    {
        return isset($this->alive[$pid]);
    }

    public function stop(int $pid): bool
    {
        $this->stopped[] = $pid;
        unset($this->alive[$pid]);

        return true;
    }

    public function finish(int $pid): void
    {
        unset($this->alive[$pid]);
    }
}
