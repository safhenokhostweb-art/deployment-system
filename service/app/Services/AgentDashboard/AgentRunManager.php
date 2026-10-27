<?php

namespace App\Services\AgentDashboard;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * One background `bin/agent run <task> --target=<agent>` per agent. The PID
 * and log location live in the Hub's own storage; the run's outcome is read
 * back from the runtime's journal, never from the process exit code.
 */
class AgentRunManager
{
    public function __construct(
        private readonly AgentRuntimeRepository $runtime,
        private readonly AgentProcessLauncher $launcher,
        private readonly string $statePath,
        private readonly ?string $phpBinary,
        private readonly int $keptLogs,
    ) {
    }

    /** @return array<string, mixed> the stored run record */
    public function start(string $agentId, string $taskType): array
    {
        if ($this->isRunning($agentId)) {
            throw new RuntimeException("Agent '{$agentId}' is already running.");
        }
        File::ensureDirectoryExists($this->statePath.'/runs');
        File::ensureDirectoryExists($this->statePath.'/logs');

        $startedAt = now();
        $log = $this->statePath.'/logs/'.$agentId.'-'.$startedAt->format('Ymd\THis').'-'.bin2hex(random_bytes(3)).'.log';
        $command = [$this->phpBinary(), $this->runtime->agentBinary(), 'run', $taskType, '--target='.$agentId, '--verbose'];
        file_put_contents($log, '$ '.implode(' ', $command).PHP_EOL);

        $pid = $this->launcher->start($command, $this->runtime->workspaceRoot(), $log);

        $run = [
            'agent' => $agentId,
            'task' => $taskType,
            'pid' => $pid,
            'command' => $command,
            'log' => $log,
            'started_at' => $startedAt->toIso8601String(),
            'stopped_at' => null,
        ];
        $this->write($agentId, $run);
        $this->pruneLogs($agentId);

        return $run;
    }

    /** @return array<string, mixed>|null the stopped run, or null if nothing was running */
    public function stop(string $agentId): ?array
    {
        $run = $this->current($agentId);
        if ($run === null || ! $this->isRunning($agentId)) {
            return null;
        }
        $this->launcher->stop((int) $run['pid']);
        $run['stopped_at'] = now()->toIso8601String();
        $this->write($agentId, $run);
        file_put_contents((string) $run['log'], PHP_EOL.'[dashboard] stopped by operator at '.$run['stopped_at'].PHP_EOL, FILE_APPEND);

        return $run;
    }

    public function isRunning(string $agentId): bool
    {
        $run = $this->current($agentId);

        return $run !== null && $run['stopped_at'] === null && $this->launcher->isRunning((int) $run['pid']);
    }

    /** @return array<string, mixed>|null */
    public function current(string $agentId): ?array
    {
        $file = $this->stateFile($agentId);
        if (! is_file($file)) {
            return null;
        }
        $run = json_decode((string) file_get_contents($file), true);

        return is_array($run) && isset($run['pid']) ? $run : null;
    }

    /** @return array<string, mixed>|null run record plus liveness, for the API */
    public function describe(string $agentId, int $tailBytes = 0): ?array
    {
        $run = $this->current($agentId);
        if ($run === null) {
            return null;
        }
        $described = [
            'task' => $run['task'],
            'pid' => $run['pid'],
            'started_at' => $run['started_at'],
            'stopped_at' => $run['stopped_at'],
            'running' => $this->isRunning($agentId),
            'command' => implode(' ', $run['command']),
        ];
        if ($tailBytes > 0) {
            $described['log_tail'] = $this->tail((string) $run['log'], $tailBytes);
        }

        return $described;
    }

    private function tail(string $file, int $bytes): string
    {
        if (! is_file($file)) {
            return '';
        }
        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - $bytes));
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function phpBinary(): string
    {
        if ($this->phpBinary !== null && $this->phpBinary !== '') {
            return $this->phpBinary;
        }
        // Under php-cgi / php-fpm / mod_php, PHP_BINARY is not a CLI.
        $binary = PHP_BINARY;
        if ($binary === '' || preg_match('/(cgi|fpm|httpd|apache)/i', basename($binary)) === 1) {
            $cli = dirname($binary).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');

            return $binary !== '' && is_file($cli) ? $cli : 'php';
        }

        return $binary;
    }

    private function pruneLogs(string $agentId): void
    {
        $logs = glob($this->statePath.'/logs/'.$agentId.'-*.log') ?: [];
        sort($logs);
        foreach (array_slice($logs, 0, max(0, count($logs) - $this->keptLogs)) as $old) {
            @unlink($old);
        }
    }

    private function write(string $agentId, array $run): void
    {
        file_put_contents($this->stateFile($agentId), json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function stateFile(string $agentId): string
    {
        return $this->statePath.'/runs/'.$agentId.'.json';
    }
}
