<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Executes the repository-shipped Linux upgrader without a shell. The
 * currently running process stays alive long enough to report the control
 * job; agent.php exits only after that signed report, letting systemd restart
 * exactly deployment-agent.service with the newly activated runtime.
 */
final class LinuxUpgradeRunner
{
    /**
     * @param array<string, array<string, mixed>> $projects
     * @return array{old_version:string,new_version:string,output:string}
     */
    public function upgrade(array $projects, string $expectedVersion, string $installDir): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('The official Linux Local Agent upgrade action is only supported on Linux.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $expectedVersion) !== 1) {
            throw new \RuntimeException('The Hub supplied an invalid expected Local Agent version.');
        }

        $project = $projects['deployment-system'] ?? null;
        $workspace = is_array($project)
            ? (string) ($project['source_workspace_path'] ?? $project['local_path'] ?? '')
            : '';
        if ($workspace === '' || SafetyChecker::productionSourcePathForbidden($workspace)) {
            throw new \RuntimeException('A safe deployment-system source workspace is required for a Linux Local Agent upgrade.');
        }

        $workspaceReal = realpath($workspace);
        $script = $workspaceReal === false ? false : realpath($workspaceReal . '/deployment-local-agent/upgrade-linux.sh');
        if ($workspaceReal === false || $script === false || !str_starts_with($script, $workspaceReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('The official Linux Local Agent upgrader is missing from the deployment-system source workspace.');
        }

        $command = [
            'bash',
            $script,
            '--agent-managed-restart',
            '--install-dir',
            $installDir,
            '--expected-version',
            $expectedVersion,
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspaceReal, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the official Linux Local Agent upgrader.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = trim((string) $stdout . "\n" . (string) $stderr);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Linux Local Agent upgrade failed: ' . ($output !== '' ? $output : "exit {$exitCode}"));
        }
        if (preg_match('/UPGRADE_OK old_version=([^\s]+) new_version=([^\s]+)/', $output, $matches) !== 1) {
            throw new \RuntimeException('Linux Local Agent upgrader returned no verifiable success record.');
        }
        if (!hash_equals($expectedVersion, $matches[2])) {
            throw new \RuntimeException("Linux Local Agent upgrader activated {$matches[2]}, expected {$expectedVersion}.");
        }

        return ['old_version' => $matches[1], 'new_version' => $matches[2], 'output' => $output];
    }
}
