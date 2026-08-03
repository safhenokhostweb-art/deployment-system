<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Read-only git inspection — `git status`/`git log` only. Never mutates
 * the working tree (see CommitManager/PushManager for that). Every
 * command is run via the array form of proc_open (no shell string
 * interpolation, no `eval`), so a path or file name can never be
 * interpreted as a shell command.
 *
 * Root-cause fix: also owns per-purpose command timeouts (configurable
 * from config.php, see agent.php's construction of this class) and
 * process-tree termination on timeout.
 *
 * Critical fix verified by direct testing on this project's own Windows
 * box: the previous implementation redirected the child's stdout/stderr
 * to PIPES and called `stream_set_blocking($pipe, false)` before polling
 * them in the wait loop. On Windows, that call silently FAILS (returns
 * false) — the pipe stays in blocking mode — so `stream_get_contents()`
 * on a pipe with nothing written yet (e.g. a `git add` that produces no
 * output until it either finishes or hangs) blocks the ENTIRE wait loop,
 * including the timeout-deadline check, for as long as the child stays
 * silent — up to and including forever. This is almost certainly the
 * literal mechanism behind the reported "Agent got stuck on git add" bug:
 * the pre-existing timeout was never actually unreachable in principle,
 * but in practice never fired for a genuinely silent hang. Redirecting
 * stdout/stderr to temp FILES instead sidesteps this entirely — reading a
 * file never blocks waiting for more data, and `proc_get_status()` (used
 * for the running-check and the timeout-deadline check) never touches
 * the pipes at all, so the loop's timing is accurate regardless of
 * whether the child has produced any output.
 */
final class GitManager
{
    private const DEFAULT_TIMEOUTS = [
        'status' => 30,
        'log' => 15,
        'add' => 120,
        'commit' => 60,
        'revert' => 60,
        'push' => 180,
        'pull' => 180,
    ];

    /** Hard cap on accumulated stdout+stderr per command, so an unexpectedly huge `git status` on a giant untracked tree can't exhaust memory. */
    private const MAX_OUTPUT_BYTES = 5_000_000;

    /**
     * @param array<string, int> $timeouts overrides merged over
     *     DEFAULT_TIMEOUTS, keyed the same way config.php's
     *     git_<purpose>_timeout_seconds keys are (status/add/commit/push/pull) —
     *     see agent.php's construction of this class.
     * @param int $jobHeartbeatIntervalSeconds how often run()'s optional
     *     $onTick callback (see run()) is actually invoked while waiting
     *     on a long command — throttled here, not by the caller, so every
     *     call site gets the same cadence without repeating the logic.
     */
    public function __construct(
        private readonly array $timeouts = [],
        private readonly int $jobHeartbeatIntervalSeconds = 25,
    ) {
    }

    /**
     * Exposed (not just used internally) so CommitManager/PushManager can
     * pass the right configured timeout into their own run() calls
     * without duplicating GitManager's own default/override merge logic.
     */
    public function timeoutFor(string $purpose): int
    {
        return $this->timeouts[$purpose] ?? self::DEFAULT_TIMEOUTS[$purpose] ?? 30;
    }

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
        $result = $this->run(['git', 'status', '--porcelain', '--untracked-files=all'], $repositoryPath, $this->timeoutFor('status'));

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
        $result = $this->run(['git', 'log', '-1', '--format=%H'], $repositoryPath, $this->timeoutFor('log'));

        if ($result['exitCode'] !== 0 || trim($result['output']) === '') {
            throw new \RuntimeException("git log failed in {$repositoryPath}: " . trim($result['output']));
        }

        return trim($result['output']);
    }

    /**
     * @param array<int, string> $command
     * @param callable|null $onTick root-cause fix: called periodically
     *     (throttled to jobHeartbeatIntervalSeconds) while this command is
     *     still running — agent.php wires this to a PublishJob heartbeat
     *     request so the job's lease keeps renewing for the full
     *     duration of a long `git add`/`push`, not just between commands.
     *     Any exception it throws is swallowed (a heartbeat failure must
     *     never abort the git command actually in progress).
     * @return array{exitCode: int, output: string, timedOut: bool}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds = 30, ?callable $onTick = null): array
    {
        // See this class's own docblock: files, not pipes — a pipe read
        // can block the whole wait loop on Windows regardless of the
        // (silently-failing) stream_set_blocking() call; a file read
        // never blocks on there being no data yet.
        $stdoutPath = tempnam(sys_get_temp_dir(), 'la-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'la-err-');

        if ($stdoutPath === false || $stderrPath === false) {
            return ['exitCode' => 255, 'output' => 'Could not allocate a temp file for command output.', 'timedOut' => false];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];

        // GIT_TERMINAL_PROMPT=0 / GCM_INTERACTIVE=never: git (and Git
        // Credential Manager on Windows) must never block waiting on an
        // interactive credential/host-key prompt no one is present to
        // answer — the single most common way a `git push` hangs
        // indefinitely rather than failing fast with a real error.
        $env = self::buildEnv();

        $process = proc_open(self::resolveArgv0ForWindows($command), $descriptorSpec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            @unlink($stdoutPath);
            @unlink($stderrPath);

            return ['exitCode' => 255, 'output' => 'Failed to start process for: ' . implode(' ', $command), 'timedOut' => false];
        }

        fclose($pipes[0]); // no stdin needed by any git/status command this class runs

        $deadline = microtime(true) + $timeoutSeconds;
        $lastTickAt = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ($onTick !== null && (microtime(true) - $lastTickAt) >= $this->jobHeartbeatIntervalSeconds) {
                $lastTickAt = microtime(true);

                try {
                    $onTick();
                } catch (\Throwable) {
                    // Never let a heartbeat failure abort the command
                    // actually in progress — the next tick (or the
                    // caller's own report at completion) tries again.
                }
            }

            if (microtime(true) > $deadline) {
                self::terminateTree($process, $status['pid']);
                proc_close($process);

                $output = self::readCapped($stdoutPath) . self::readCapped($stderrPath);
                $output .= "\n[local-agent] command exceeded {$timeoutSeconds}s timeout and was terminated (PID {$status['pid']})\n";
                self::cleanup($stdoutPath, $stderrPath);

                return ['exitCode' => 124, 'output' => $output, 'timedOut' => true];
            }

            usleep(100000);
        }

        $exitCode = proc_close($process);
        $output = self::readCapped($stdoutPath) . self::readCapped($stderrPath);
        self::cleanup($stdoutPath, $stderrPath);

        return ['exitCode' => $exitCode, 'output' => $output, 'timedOut' => false];
    }

    /**
     * Reads at most MAX_OUTPUT_BYTES from a file — an unexpectedly huge
     * `git status` on a giant untracked tree must never be loaded fully
     * into memory just to then get truncated.
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

        return (string) $partial . "\n[local-agent] output truncated at " . self::MAX_OUTPUT_BYTES . " bytes\n";
    }

    private static function cleanup(string $stdoutPath, string $stderrPath): void
    {
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }

    /**
     * Root-cause fix (full local build + artifact pipeline): this class's
     * run() is now also used to invoke `composer`/`npm` (see
     * LocalBuildRunner), not just `git`. On Windows, PHP's array-form
     * proc_open() calls CreateProcess() directly — bypassing the
     * PATHEXT/.cmd-shim resolution a real shell would do — so
     * `proc_open(['npm', ...])` fails with "CreateProcess failed, error
     * code: 2" even though `npm`/`composer` work fine from any terminal
     * (both are .cmd shims on a typical Windows install). Confirmed by
     * direct testing on this project's own Windows dev machine, and
     * already fixed the identical way in the production Server Agent's
     * Executor.php. `git` itself is a real .exe and was never affected —
     * this is a pure no-op for every git command already running through
     * here, and a pure no-op on Linux/macOS (the real production
     * targets) regardless of which executable is invoked.
     *
     * @param array<int, string> $argv
     * @return array<int, string>
     */
    private static function resolveArgv0ForWindows(array $argv): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || $argv === []) {
            return $argv;
        }

        $program = $argv[0];
        if ($program === '' || preg_match('/\.(exe|cmd|bat|com)$/i', $program) === 1 || is_file($program)) {
            return $argv;
        }

        foreach (['.exe', '.cmd', '.bat'] as $extension) {
            $resolved = self::findOnPath($program . $extension);
            if ($resolved !== null) {
                $argv[0] = $resolved;

                return $argv;
            }
        }

        return $argv;
    }

    private static function findOnPath(string $filename): ?string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function buildEnv(): array
    {
        $env = getenv();
        if (!is_array($env) || $env === []) {
            $env = $_SERVER;
        }

        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GCM_INTERACTIVE'] = 'never';

        return $env;
    }

    /**
     * Windows: proc_terminate() only ever signals the one process handle
     * PHP opened — if that process (or, in principle, something it
     * spawned) doesn't die from it, the handle is orphaned and left
     * running. `taskkill /PID <pid> /T /F` targets that exact PID and its
     * full descendant tree, nothing else on the machine — never `/IM` (by
     * image name), which would kill every git.exe/php.exe system-wide.
     * POSIX keeps the previous SIGTERM-then-SIGKILL behavior.
     */
    private static function terminateTree($process, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $killer = @proc_open(['taskkill', '/PID', (string) $pid, '/T', '/F'], $descriptorSpec, $killerPipes);

            if (is_resource($killer)) {
                fclose($killerPipes[0]);
                stream_get_contents($killerPipes[1]);
                stream_get_contents($killerPipes[2]);
                fclose($killerPipes[1]);
                fclose($killerPipes[2]);
                proc_close($killer);
            }

            // Belt-and-suspenders: also signal PHP's own handle in case
            // taskkill couldn't run (missing from PATH, permissions) —
            // harmless no-op if the process is already gone.
            proc_terminate($process, 15);

            return;
        }

        proc_terminate($process, 15);
        usleep(200000);
        proc_terminate($process, 9);
    }
}
