<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Runs one pipeline step as a local shell command in the project's working
 * directory. The agent runs ON the target server, so this never needs SSH.
 *
 * Root-cause fix: stdout/stderr are redirected to temp FILES rather than
 * pipes. The previous pipe-based implementation called
 * `stream_set_blocking($pipe, false)` before polling them in the wait
 * loop — on Windows that call silently fails (returns false, pipe stays
 * blocking), so `stream_get_contents()` on a pipe with nothing written
 * yet blocks the entire wait loop, including the timeout-deadline check,
 * for as long as the child stays silent. Confirmed by direct testing
 * elsewhere in this project against the Local Agent's equivalent
 * GitManager class, which had the identical pattern. A file read never
 * blocks waiting for more data, so the fix applies regardless of which OS
 * this Agent ultimately runs on.
 */
final class Executor
{
    /** Hard cap on accumulated stdout+stderr per step, so an unexpectedly chatty command can't exhaust memory. */
    private const MAX_OUTPUT_BYTES = 5_000_000;

    /**
     * How often (seconds) the wait loop's own polling interval permits
     * $onTick to actually be invoked. $onTick itself decides whether
     * that much real time has elapsed since its own last action (e.g.
     * RecipeStepRunner throttles the Hub heartbeat call further, to
     * config('job_heartbeat_interval_seconds')) — this constant only
     * bounds how often Executor is willing to give it the opportunity.
     */
    private const TICK_INTERVAL_SECONDS = 2.0;

    /**
     * @param callable(): void|null $onTick invoked periodically while the
     *     command is still running — e.g. to renew a Deployment's Hub
     *     lease during a long `npm ci`/Next.js build. A thrown exception
     *     from $onTick is caught and swallowed: a heartbeat hiccup must
     *     never abort or corrupt the command it's only supervising.
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    public function run(string $command, string $workingDirectory, int $timeoutSeconds, ?callable $onTick = null): array
    {
        return $this->execute($command, $workingDirectory, $timeoutSeconds, null, $onTick);
    }

    /**
     * Runs a command as a literal argv array — no shell involved at all
     * (PHP's proc_open() accepts an array command since 7.4 and executes
     * it directly), so there is nothing to shell-escape and no
     * metacharacter/injection surface, unlike run()'s string form. Every
     * typed recipe step (RecipeStepRunner) uses this; only the explicit
     * `shell` step type (kept for parity with the legacy `pipeline`
     * column) uses run().
     *
     * @param array<int, string> $argv
     * @param array<string, string> $env merged on top of the agent
     *     process's own inherited environment (never replaces it — npm/
     *     node/prisma all need PATH, HOME, etc. to function), so a
     *     recipe step's env only ever *adds* variables like
     *     NEXT_PUBLIC_API_URL.
     * @param callable(): void|null $onTick see run().
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    public function runArgv(array $argv, string $workingDirectory, int $timeoutSeconds, array $env = [], ?callable $onTick = null): array
    {
        $effectiveEnv = $env === [] ? null : array_merge((getenv() ?: []), $env);

        return $this->execute(self::resolveArgv0ForWindows($argv), $workingDirectory, $timeoutSeconds, $effectiveEnv, $onTick);
    }

    /**
     * Production only ever runs this agent on Ubuntu, where 'npm' is a
     * real executable found directly on PATH and this is a pure no-op.
     * On Windows, however, PHP's array-form proc_open() calls
     * CreateProcess() directly — bypassing the PATHEXT/.cmd-shim
     * resolution a real shell would do — so `proc_open(['npm', ...])`
     * fails with "CreateProcess failed, error code: 2" even though `npm`
     * works fine from any terminal. Confirmed by direct testing on this
     * project's Windows dev machine. Resolving the .cmd/.bat/.exe
     * manually here is what lets install_dependencies/run_script be
     * genuinely, not just theoretically, exercised in local/CI testing
     * on Windows, with zero effect on the real Ubuntu target.
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
     * @param string|array<int, string> $command
     * @param array<string, string>|null $env
     * @param callable(): void|null $onTick
     * @return array{exitCode: int, output: string, timedOut: bool, durationMs: int}
     */
    private function execute(string|array $command, string $workingDirectory, int $timeoutSeconds, ?array $env, ?callable $onTick): array
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'da-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'da-err-');

        if ($stdoutPath === false || $stderrPath === false) {
            return [
                'exitCode' => 255,
                'output' => 'Could not allocate a temp file for command output.',
                'timedOut' => false,
                'durationMs' => 0,
            ];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];

        $commandLabel = is_array($command) ? implode(' ', $command) : $command;
        $start = microtime(true);
        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $env);

        if (!is_resource($process)) {
            self::cleanup($stdoutPath, $stderrPath);

            return [
                'exitCode' => 255,
                'output' => "Failed to start process for command: {$commandLabel}",
                'timedOut' => false,
                'durationMs' => 0,
            ];
        }

        fclose($pipes[0]);

        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;
        $lastTick = microtime(true);

        while (true) {
            $status = proc_get_status($process);

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

            if ($onTick !== null && (microtime(true) - $lastTick) >= self::TICK_INTERVAL_SECONDS) {
                $lastTick = microtime(true);
                try {
                    $onTick();
                } catch (\Throwable) {
                    // A heartbeat/tick failure must never abort the command
                    // it's only supervising — the lease simply won't be
                    // renewed this cycle, and the next tick (or the step's
                    // own completion) tries again.
                }
            }

            usleep(150000);
        }

        $exitCode = $timedOut ? 124 : proc_close($process);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $output = self::readCapped($stdoutPath) . self::readCapped($stderrPath);
        self::cleanup($stdoutPath, $stderrPath);

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

    /**
     * Reads at most MAX_OUTPUT_BYTES from a file — an unexpectedly huge
     * step output must never be loaded fully into memory just to then
     * get truncated.
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

        return (string) $partial . "\n[agent] output truncated at " . self::MAX_OUTPUT_BYTES . " bytes\n";
    }

    private static function cleanup(string $stdoutPath, string $stderrPath): void
    {
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}
