<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Read-only git inspection — `git status`/`git log` only. Never mutates
 * the working tree (see CommitManager/PushManager for that). Every
 * command is run via the array form of proc_open (no shell string
 * interpolation, no `eval`), so a path or file name can never be
 * interpreted as a shell command.
 */
final class GitManager
{
    /**
     * @return array<int, string> relative file paths, exactly as `git
     *     status --porcelain` reports them (including a rename's
     *     "old -> new" form) — never resolved to absolute paths, never
     *     the file contents.
     */
    public function changedFiles(string $repositoryPath): array
    {
        // --untracked-files=all: without it, git collapses an entirely
        // new, untracked directory into a single "path/" line instead of
        // listing the files inside it — which would both hide the real
        // file list from the operator's review and defeat IgnoreRules'
        // path-based matching (a rule like "storage/logs/" can only match
        // a file path, not a truncated parent-directory line).
        $result = $this->run(['git', 'status', '--porcelain', '--untracked-files=all'], $repositoryPath);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("git status failed in {$repositoryPath}: " . trim($result['output']));
        }

        $files = [];
        foreach (preg_split('/\r?\n/', $result['output']) as $line) {
            if ($line === '') {
                continue;
            }

            // Porcelain format is "XY <path>" (2-status-chars, space,
            // path) — substr(3) strips the status prefix, not the path.
            $files[] = substr($line, 3);
        }

        return $files;
    }

    public function lastCommitHash(string $repositoryPath): string
    {
        $result = $this->run(['git', 'log', '-1', '--format=%H'], $repositoryPath);

        if ($result['exitCode'] !== 0 || trim($result['output']) === '') {
            throw new \RuntimeException("git log failed in {$repositoryPath}: " . trim($result['output']));
        }

        return trim($result['output']);
    }

    /**
     * @param array<int, string> $command
     * @return array{exitCode: int, output: string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds = 30): array
    {
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, null);

        if (!is_resource($process)) {
            return ['exitCode' => 255, 'output' => 'Failed to start process for: ' . implode(' ', $command)];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 15);
                usleep(200000);
                proc_terminate($process, 9);
                $output .= "\n[local-agent] command exceeded {$timeoutSeconds}s timeout and was terminated\n";
                fclose($pipes[1]);
                fclose($pipes[2]);

                return ['exitCode' => 124, 'output' => $output];
            }

            usleep(100000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'output' => $output];
    }
}
