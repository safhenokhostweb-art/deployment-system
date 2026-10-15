<?php

declare(strict_types=1);

namespace App\Services\Sentinel\Readiness\Transport;

/**
 * PowershellObservationTransport - the ONLY place in the Readiness
 * package allowed to launch a native process.
 *
 * It executes a FIXED, allowlisted PowerShell script selected by token
 * from PowershellAllowedScripts. It never accepts, interpolates or
 * evaluates caller-supplied script source.
 *
 * Caller-controlled values reach the script exclusively through child
 * process environment variables, read via
 * [Environment]::GetEnvironmentVariable(). They are never on the
 * command line and never in the script text, so no quoting or
 * escaping of them can be defeated.
 *
 * Hard execution rules:
 *   - Explicit executable path. No PATH lookup, no bare "powershell",
 *     no shell wrapper (no cmd.exe, no /c, no sh -c).
 *   - -NoProfile: the user profile is NOT loaded, so it cannot inject
 *     aliases, functions or side effects.
 *   - -NonInteractive: no prompt can block waiting for a human.
 *   - -EncodedCommand: the fixed script text is passed base64-encoded
 *     so it survives the Windows command line intact.
 *   - Cooperative deadline checks. Native Windows pipe operations and
 *     proc_close() can outlast the deadline; termination is not guaranteed.
 *     There is no retry and no child-process containment.
 *   - Bounded stdout and stderr during reading; overflow of either
 *     stream requests termination and fails the observation.
 *   - Captured exit code. A nonzero exit code is a probe failure.
 *   - Sanitized failures. Native stderr never reaches a caller; only
 *     a stable observation code propagates.
 *
 * This transport collects information only. It creates nothing,
 * modifies nothing, and authorizes nothing.
 *
 * The class is intentionally NOT final: it is the seam a test uses to
 * substitute canned results for real process launches. The allowlist
 * is enforced by PowershellAllowedScripts::resolve(), which every
 * execution path must call; a subclass that overrides execute() takes
 * responsibility for doing the same.
 */
class PowershellObservationTransport
{
    /**
     * Deployment policy: only this Windows PowerShell system installation
     * is supported. No environment-derived root, PATH lookup, pwsh, SysWOW64,
     * aliases or alternate installations. This is a location policy, not
     * an attestation of the file's identity or filesystem containment.
     */
    public const TRUSTED_EXECUTABLE = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';

    public const DEFAULT_TIMEOUT_SECONDS = 15.0;

    // Supported observation policy: at most one day, with microsecond resolution.
    public const MAX_TIMEOUT_SECONDS = 86400.0;
    public const MIN_TIMEOUT_SECONDS = 0.000001;

    public const DEFAULT_MAX_STDOUT_BYTES = 65536;

    public const DEFAULT_MAX_STDERR_BYTES = 8192;

    public const ENV_PATH = 'SENTINEL_OBSERVE_PATH';

    public const ENV_SERVICE = 'SENTINEL_OBSERVE_SERVICE';

    public const ENV_MODULE = 'SENTINEL_OBSERVE_MODULE';

    public const ENV_GROUP = 'SENTINEL_OBSERVE_GROUP';

    public function __construct(
        private readonly string $powershellPath,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxStdoutBytes = self::DEFAULT_MAX_STDOUT_BYTES,
        private readonly int $maxStderrBytes = self::DEFAULT_MAX_STDERR_BYTES,
    ) {
        if ($powershellPath !== self::TRUSTED_EXECUTABLE) {
            throw new \InvalidArgumentException('PowerShell executable location is not allowed');
        }

        if (! is_finite($timeoutSeconds) || $timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException('timeout must be finite and within supported bounds');
        }

        if ($maxStdoutBytes < 1 || $maxStderrBytes < 1) {
            throw new \InvalidArgumentException('output caps must be positive');
        }
    }

    /**
     * Execute one allowlisted script by token.
     *
     * @param  string                 $scriptToken  key of PowershellAllowedScripts::all()
     * @param  array<string, string>  $values       caller values, passed as env vars
     *
     * @throws \InvalidArgumentException when the token is not allowlisted
     */
    public function execute(string $scriptToken, array $values = []): PowershellExecutionResult
    {
        $script = PowershellAllowedScripts::resolve($scriptToken);

        // Reassert the closed module path before any observation cmdlet:
        // PowerShell startup may reconstruct PSModulePath. This fixed policy
        // text contains no caller/environment interpolation.
        $script = '$env:PSModulePath = \'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules\'' . "\n" . $script;

        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));

        $command = [
            $this->powershellPath,
            '-NoProfile',
            '-NonInteractive',
            '-EncodedCommand',
            $encoded,
        ];

        $env = $this->childEnvironment($values);

        $start = $this->now();
        $deadline = $start + $this->timeoutSeconds;
        if (! is_finite($deadline) || $deadline <= $start) {
            throw new \InvalidArgumentException('timeout deadline is not representable');
        }

        $pipes = [];

        $proc = $this->launchProcess($command, $pipes, $env);

        if (! is_resource($proc)) {
            return PowershellExecutionResult::launchFailed($this->now() - $start);
        }

        $stdout = '';

        $stderr = '';

        $timedOut = false;

        $stdoutOverflow = false;

        $stderrOverflow = false;

        $streamFailed = false;
        $cleanupFailed = false;
        try {
            if (! $this->closePipe($pipes[0] ?? null)) {
                $cleanupFailed = true;
            }
        } catch (\Throwable) {
            $cleanupFailed = true;
        }
        try {
            foreach ([1, 2] as $index) {
                if (! $this->validStream($pipes[$index] ?? null) || ! $this->configureNonblocking($pipes[$index])) {
                    $streamFailed = true;
                }
            }

            while (! $streamFailed && ! $cleanupFailed) {
                if ($this->now() >= $deadline) {
                    $timedOut = true;
                    break;
                }
                $stdoutChunk = $this->drain($pipes[1], $this->maxStdoutBytes - strlen($stdout));

                if ($this->now() >= $deadline) {
                    $timedOut = true;
                    break;
                }

                $stderrChunk = $this->drain($pipes[2], $this->maxStderrBytes - strlen($stderr));

                $stdoutOverflow = strlen($stdoutChunk) > $this->maxStdoutBytes - strlen($stdout);

                $stderrOverflow = strlen($stderrChunk) > $this->maxStderrBytes - strlen($stderr);

                $stdout .= substr($stdoutChunk, 0, $this->maxStdoutBytes - strlen($stdout));

                $stderr .= substr($stderrChunk, 0, $this->maxStderrBytes - strlen($stderr));

                if ($stdoutOverflow || $stderrOverflow) {
                    break;
                }

                $status = $this->processStatus($proc);

                // Check after reads/status, before accepting even an exited process.
                if ($this->now() >= $deadline) {
                    $timedOut = true;
                    break;
                }

                if (! $status['running'] && ($stdoutChunk === '' && $stderrChunk === '')) {
                    break;
                }

                if ($status['running']) {
                    usleep(20000);
                }
            }
        } catch (\Throwable) {
            // Native exception messages may contain private host information.
            $streamFailed = true;
        }

        if ($timedOut || $stdoutOverflow || $stderrOverflow || $streamFailed || $cleanupFailed) {
            try {
                if (! $this->terminateProcess($proc)) {
                    $cleanupFailed = true;
                }
            } catch (\Throwable) {
                $cleanupFailed = true;
            }
        }

        foreach ([1, 2] as $index) {
            try {
                if (! $this->closePipe($pipes[$index] ?? null)) {
                    $cleanupFailed = true;
                }
            } catch (\Throwable) {
                $cleanupFailed = true;
            }
        }

        // Existing native wait retained: on Windows proc_close waits INFINITE.
        // This phase does not provide bounded cleanup or fire-and-forget release.
        try {
            $exitCode = $this->closeProcess($proc);
            $cleanupFailed = $cleanupFailed || $exitCode === -1;
        } catch (\Throwable) {
            $cleanupFailed = true;
            $exitCode = -1;
        }
        $elapsed = $this->now() - $start;
        $timedOut = $timedOut || $elapsed >= $this->timeoutSeconds;

        if ($cleanupFailed) {
            return PowershellExecutionResult::cleanupFailed($elapsed);
        }
        if ($streamFailed) {
            return PowershellExecutionResult::streamFailed($elapsed);
        }

        if ($timedOut) {
            return PowershellExecutionResult::timeout($elapsed);
        }

        if ($stdoutOverflow || $stderrOverflow) {
            return PowershellExecutionResult::outputOverflow(
                $elapsed,
                $stdoutOverflow ? $this->maxStdoutBytes : null,
                $stderrOverflow ? $this->maxStderrBytes : null,
            );
        }

        return PowershellExecutionResult::completed(
            $exitCode,
            $stdout,
            $stderr,
            $elapsed,
            null,
        );
    }

    /**
     * Closed environment policy: do not inherit parent environment values.
     * System paths and the built-in module directory are fixed by deployment
     * policy. User/Program Files module locations are deliberately excluded.
     * Only exact observation keys accept caller data, never script source.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function childEnvironment(array $values): array
    {
        $keys = [self::ENV_PATH, self::ENV_SERVICE, self::ENV_MODULE, self::ENV_GROUP];

        foreach ($values as $key => $value) {
            if (! in_array($key, $keys, true) || ! is_string($value) || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('PowerShell observation environment is not allowed');
            }
        }

        $env = [
            'SystemRoot' => 'C:\\Windows',
            'windir' => 'C:\\Windows',
            'PATH' => 'C:\\Windows\\System32',
            'TEMP' => 'C:\\Windows\\Temp',
            'TMP' => 'C:\\Windows\\Temp',
            'PSModulePath' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules',
        ];

        $env[self::ENV_PATH] = (string) ($values[self::ENV_PATH] ?? '');

        $env[self::ENV_SERVICE] = (string) ($values[self::ENV_SERVICE] ?? '');

        $env[self::ENV_MODULE] = (string) ($values[self::ENV_MODULE] ?? '');

        $env[self::ENV_GROUP] = (string) ($values[self::ENV_GROUP] ?? '');

        return $env;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function descriptors(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    /**
     * At most one bounded chunk plus an overflow sentinel byte is read.
     * Never use an unlimited stream_get_contents() on process output.
     */
    private function drain($pipe, int $remaining): string
    {
        if (! $this->validStream($pipe)) {
            throw new \RuntimeException('PowerShell observation stream failed');
        }

        $chunk = $this->readPipe($pipe, $remaining >= 8192 ? 8192 : $remaining + 1);
        if ($chunk === false) {
            throw new \RuntimeException('PowerShell observation stream failed');
        }

        // feof() also reports errors, so it cannot establish successful reading.
        return $chunk;
    }

    private function validStream($pipe): bool
    {
        return is_resource($pipe) && get_resource_type($pipe) === 'stream';
    }

    protected function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    protected function configureNonblocking($pipe): bool
    {
        return @stream_set_blocking($pipe, false);
    }

    protected function readPipe($pipe, int $length): string|false
    {
        return @fread($pipe, $length);
    }

    protected function closePipe($pipe): bool
    {
        return $this->validStream($pipe) && @fclose($pipe);
    }

    // Narrow native-process seam for synthetic tests of the real read loop.
    protected function launchProcess(array $command, array &$pipes, array $env)
    {
        return @proc_open($command, $this->descriptors(), $pipes, 'C:\\Windows\\System32', $env, ['bypass_shell' => true]);
    }

    protected function processStatus($proc): array
    {
        return proc_get_status($proc);
    }

    protected function terminateProcess($proc): bool
    {
        return @proc_terminate($proc, 9);
    }

    protected function closeProcess($proc): int
    {
        return proc_close($proc);
    }
}
