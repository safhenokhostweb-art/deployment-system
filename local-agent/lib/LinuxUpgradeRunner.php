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
    public function __construct(private readonly LinuxUpgradePackageMaterializer $packages)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $projects
     * @param null|callable(array{source_commit:string,package_tree_hash:string,repository_mapping:string}): void $onValidated
     * @return array{old_version:string,new_version:string,source_commit:string,package_tree_hash:string,output:string}
     */
    public function upgrade(
        array $projects,
        string $expectedVersion,
        string $expectedCommit,
        string $expectedBranch,
        string $repositoryMapping,
        string $installDir,
        ?callable $onValidated = null,
    ): array {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('The official Linux Local Agent upgrade action is only supported on Linux.');
        }

        return $this->packages->withPackage(
            $projects,
            $repositoryMapping,
            $expectedBranch,
            $expectedCommit,
            $expectedVersion,
            function (string $packageRoot, string $treeHash, string $sourceCommit) use ($expectedVersion, $repositoryMapping, $installDir, $onValidated): array {
                $script = realpath($packageRoot . '/upgrade-linux.sh');
                if ($script === false || !str_starts_with($script, $packageRoot . DIRECTORY_SEPARATOR)) {
                    throw new \RuntimeException('The exact-commit Linux Local Agent upgrader is missing.');
                }
                $onValidated?->__invoke(['source_commit' => $sourceCommit, 'package_tree_hash' => $treeHash, 'repository_mapping' => $repositoryMapping]);

                $command = ['bash', $script, '--source-dir', $packageRoot . '/agent', '--agent-managed-restart', '--install-dir', $installDir, '--expected-version', $expectedVersion];
                $pipes = [];
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $packageRoot, null, ['bypass_shell' => true]);
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

                return ['old_version' => $matches[1], 'new_version' => $matches[2], 'source_commit' => $sourceCommit, 'package_tree_hash' => $treeHash, 'output' => $output];
            },
        );
    }
}
