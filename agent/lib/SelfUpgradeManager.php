<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Root-cause fix (Deployment #184 — no channel from the Hub to the
 * Server Agent runtime at all): downloads, verifies, stages, and
 * atomically activates one exact-commit, hash-verified Server Agent
 * package (see service/app/Models/ServerAgentPackage), then restarts
 * through a tightly allowlisted privileged helper — never a raw shell
 * command (see agent/install/deployment-server-agent-restart.sudoers).
 * Mirrors ArtifactManager::activate()'s own atomic-swap contract
 * exactly (old runtime renamed aside, never deleted immediately; a
 * failure before the swap leaves the active runtime completely
 * untouched) applied to this process's own code instead of an
 * application release.
 */
final class SelfUpgradeManager
{
    public function __construct(
        private readonly Client $client,
        private readonly Executor $executor,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @param callable(array{source_commit:string,package_hash:string}): void $onValidated fires once package integrity + version are proven, BEFORE activation
     * @return array{old_version:string,new_version:string,source_commit:string,package_hash:string}
     */
    public function upgrade(array $job, string $installDir, string $phpBinary, callable $onValidated): array
    {
        $packageId = (string) ($job['package_id'] ?? '');
        $expectedHash = (string) ($job['expected_package_hash'] ?? '');
        $expectedCommit = (string) ($job['target_commit_sha'] ?? '');
        $expectedVersion = (string) ($job['target_version'] ?? '');
        if (
            $packageId === ''
            || preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1
            || preg_match('/^[0-9a-f]{40}$/', $expectedCommit) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/', $expectedVersion) !== 1
        ) {
            throw new \RuntimeException('The Hub supplied an incomplete or invalid Server Agent upgrade job.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'server-agent-package-') . '.zip';

        try {
            $download = $this->client->downloadToFile("/api/agent/server-agent-packages/{$packageId}/download", $zipPath);
            if ($download['status'] !== 200) {
                throw new \RuntimeException("Could not download the Server Agent package (HTTP {$download['status']}).");
            }

            $actualHash = hash_file('sha256', $zipPath);
            if ($actualHash === false || !hash_equals($expectedHash, $actualHash)) {
                throw new \RuntimeException('Downloaded Server Agent package checksum does not match the expected, hash-verified package.');
            }

            $installDir = rtrim($installDir, '/');
            $stagingPath = $installDir . '.staging-' . bin2hex(random_bytes(4));

            try {
                if (!mkdir($stagingPath, 0755, true)) {
                    throw new \RuntimeException('Could not create the Server Agent upgrade staging directory.');
                }

                $zip = new \ZipArchive();
                if ($zip->open($zipPath) !== true) {
                    throw new \RuntimeException('Could not open the downloaded Server Agent package.');
                }
                $extracted = $zip->extractTo($stagingPath);
                $zip->close();
                if ($extracted !== true) {
                    throw new \RuntimeException('Could not extract the downloaded Server Agent package.');
                }

                // Required-file + version validation, entirely against the
                // STAGED copy — the live runtime is not touched yet.
                if (!is_file($stagingPath . '/agent.php') || !is_dir($stagingPath . '/lib') || !is_file($stagingPath . '/lib/PipelineExecutor.php')) {
                    throw new \RuntimeException('The downloaded Server Agent package is missing required runtime files.');
                }
                $source = (string) file_get_contents($stagingPath . '/agent.php');
                if (preg_match("/const AGENT_VERSION = '([^']+)';/", $source, $matches) !== 1) {
                    throw new \RuntimeException('Could not read the staged Server Agent package version.');
                }
                if (!hash_equals($expectedVersion, $matches[1])) {
                    throw new \RuntimeException("Staged Server Agent package version {$matches[1]} does not match expected version {$expectedVersion}.");
                }

                // Every shipped PHP file must parse cleanly before it can
                // ever be activated — a single malformed file must fail
                // the whole upgrade, not surface as a crash after restart.
                foreach ($this->phpFilesIn($stagingPath) as $phpFile) {
                    $lint = $this->executor->runArgv([$phpBinary, '-l', $phpFile], $stagingPath, 30);
                    if ($lint['exitCode'] !== 0) {
                        throw new \RuntimeException("Syntax validation failed for {$phpFile}: " . trim($lint['output']));
                    }
                }

                $oldVersion = 'unknown';
                if (is_file($installDir . '/agent.php')) {
                    $oldSource = (string) file_get_contents($installDir . '/agent.php');
                    if (preg_match("/const AGENT_VERSION = '([^']+)';/", $oldSource, $oldMatches) === 1) {
                        $oldVersion = $oldMatches[1];
                    }
                }

                // config.php holds this server's credentials — never
                // shipped in the package (see
                // GitExactCommitSubtreeMaterializer's own required-file
                // list on the Local Agent side, which never includes it),
                // always carried forward from the currently-active runtime.
                if (is_file($installDir . '/config.php')) {
                    if (!copy($installDir . '/config.php', $stagingPath . '/config.php')) {
                        throw new \RuntimeException('Could not preserve the existing Server Agent config.php into the staged runtime.');
                    }
                }

                // Everything above is proof the package is genuine,
                // complete, and syntactically valid. Report BEFORE
                // activation — same ordering LinuxUpgradeRunner already
                // uses for the Local Agent's own self-upgrade — so the
                // Hub has the exact-commit evidence on record the instant
                // activation begins.
                $onValidated(['source_commit' => $expectedCommit, 'package_hash' => $expectedHash]);

                $this->activate($installDir, $stagingPath);
            } finally {
                if (is_dir($stagingPath)) {
                    $this->recursiveDelete($stagingPath);
                }
            }

            // Restart through one fixed, sudoers-constrained helper —
            // never a raw shell command (see
            // agent/install/deployment-server-agent-restart.sudoers). This
            // call very likely never returns: systemd tears down the
            // calling process the instant the service unit restarts. The
            // Hub's post-restart heartbeat verification is the true
            // success signal (see ServerAgentControlJobService::
            // verifyUpgradeHeartbeat()), never this call's own exit code —
            // activation has already succeeded by this point, so a
            // failure merely invoking the helper must not be reported as
            // an upgrade failure.
            try {
                $this->executor->runArgv(['sudo', '-n', '/usr/local/sbin/deployment-server-agent-restart'], $installDir, 30);
            } catch (\Throwable $e) {
                $this->logger->error('Server Agent activated but the restart helper could not be invoked: ' . $e->getMessage());
            }

            return ['old_version' => $oldVersion, 'new_version' => $expectedVersion, 'source_commit' => $expectedCommit, 'package_hash' => $expectedHash];
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * Atomic two-rename swap — identical contract to
     * ArtifactManager::atomicSwap(): the previously-active runtime is
     * renamed aside (never deleted here), so a failure activating the
     * staged runtime can restore it, and the active runtime is never
     * observed in a partially-swapped state.
     */
    private function activate(string $installDir, string $stagingPath): void
    {
        $hadPrevious = is_dir($installDir);
        $backupPath = $installDir . '.backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        if ($hadPrevious) {
            if (!@rename($installDir, $backupPath)) {
                throw new \RuntimeException('Could not move the active Server Agent runtime aside for activation.');
            }
        }

        if (!@rename($stagingPath, $installDir)) {
            if ($hadPrevious && is_dir($backupPath)) {
                @rename($backupPath, $installDir);
            }
            throw new \RuntimeException('Could not activate the staged Server Agent runtime; the previous runtime was restored.');
        }
    }

    /** @return array<int, string> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function recursiveDelete(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
