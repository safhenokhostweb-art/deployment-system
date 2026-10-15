<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness\Transport;

use App\Services\Sentinel\Readiness\Transport\PowershellAllowedScripts;
use App\Services\Sentinel\Readiness\Transport\PowershellExecutionResult;
use App\Services\Sentinel\Readiness\Transport\PowershellObservationTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No native processes: only in-memory streams exercise the production read loop. */
final class PowershellObservationTransportTest extends TestCase
{
    public static function rejectedPaths(): array
    {
        return [
            'empty' => [''],
            'relative' => ['powershell.exe'],
            'drive relative' => ['C:powershell.exe'],
            'UNC' => ['\\\\server\\share\\powershell.exe'],
            'device path' => ['\\\\?\\C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'],
            'traversal' => ['C:\\Windows\\System32\\..\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'],
            'unexpected executable' => ['C:\\Windows\\System32\\cmd.exe'],
            'caller directory' => ['C:\\Users\\Public\\powershell.exe'],
            'alternate installation' => ['D:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'],
            'pwsh' => ['C:\\Program Files\\PowerShell\\7\\pwsh.exe'],
            'trailing whitespace' => [PowershellObservationTransport::TRUSTED_EXECUTABLE . ' '],
            'NUL' => [PowershellObservationTransport::TRUSTED_EXECUTABLE . "\0"],
        ];
    }

    #[DataProvider('rejectedPaths')]
    public function test_executable_policy_rejects_untrusted_paths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PowerShell executable location is not allowed');
        new PowershellObservationTransport($path);
    }

    public function test_allowed_executable_and_data_only_command(): void
    {
        $transport = new InMemoryProcessTransport;
        $value = 'vmms"; calc; $(whoami)';
        $result = $transport->execute('service', [PowershellObservationTransport::ENV_SERVICE => $value]);

        $this->assertTrue($result->isCleanExit());
        $this->assertSame([
            PowershellObservationTransport::TRUSTED_EXECUTABLE,
            '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(mb_convert_encoding(
                '$env:PSModulePath = \'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules\'' . "\n" . PowershellAllowedScripts::SERVICE,
                'UTF-16LE', 'UTF-8',
            )),
        ], $transport->command);
        $this->assertStringNotContainsString($value, implode(' ', $transport->command));
        $this->assertSame($value, $transport->environment[PowershellObservationTransport::ENV_SERVICE]);
        $this->assertSame('', $transport->environment[PowershellObservationTransport::ENV_PATH]);
        $this->assertTrue($transport->closed);
        $this->assertFalse($transport->terminated);
    }

    public function test_poisoned_parent_environment_is_not_inherited(): void
    {
        $poison = [
            'PSModulePath', 'PATH', 'SystemRoot', 'windir', 'TEMP', 'TMP',
            'COMSPEC', 'DOTNET_STARTUP_HOOKS', '__PSLockdownPolicy',
            PowershellObservationTransport::ENV_PATH,
            PowershellObservationTransport::ENV_SERVICE,
            PowershellObservationTransport::ENV_MODULE,
            PowershellObservationTransport::ENV_GROUP,
            'sentinel_observe_service', 'psmodulepath',
        ];
        $saved = [];
        try {
            foreach ($poison as $key) {
                $saved[$key] = getenv($key);
                putenv($key . '=untrusted');
            }
            $transport = new InMemoryProcessTransport;
            $transport->execute('service', [PowershellObservationTransport::ENV_SERVICE => 'vmms']);
            $this->assertSame([
                'SystemRoot' => 'C:\\Windows',
                'windir' => 'C:\\Windows',
                'PATH' => 'C:\\Windows\\System32',
                'TEMP' => 'C:\\Windows\\Temp',
                'TMP' => 'C:\\Windows\\Temp',
                'PSModulePath' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules',
                PowershellObservationTransport::ENV_PATH => '',
                PowershellObservationTransport::ENV_SERVICE => 'vmms',
                PowershellObservationTransport::ENV_MODULE => '',
                PowershellObservationTransport::ENV_GROUP => '',
            ], $transport->environment);
        } finally {
            foreach (array_reverse($saved, true) as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    public static function rejectedEnvironment(): array
    {
        return [
            'module poisoning' => [['PSModulePath' => 'C:\\Untrusted']],
            'path poisoning' => [['PATH' => 'C:\\Untrusted']],
            'root poisoning' => [['SystemRoot' => 'D:\\Untrusted']],
            'case alias' => [['sentinel_observe_service' => 'evil']],
            'non string' => [[PowershellObservationTransport::ENV_SERVICE => ['evil']]],
            'NUL' => [[PowershellObservationTransport::ENV_SERVICE => "vmms\0evil"]],
        ];
    }

    #[DataProvider('rejectedEnvironment')]
    public function test_caller_environment_poisoning_is_rejected_before_launch(array $values): void
    {
        $transport = new InMemoryProcessTransport;
        try {
            $transport->execute('service', $values);
            $this->fail('Expected environment rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('PowerShell observation environment is not allowed', $e->getMessage());
            $this->assertSame([], $transport->command);
        }
    }

    public static function outputCases(): array
    {
        return [
            'stderr first read' => ['', str_repeat('secret', 10000), false, true],
            'stderr later read' => ['', str_repeat('secret', 10000), false, true, 16384],
            'stdout first read' => [str_repeat('x', 60000), '', true, false],
            'stdout later read' => [str_repeat('x', 60000), '', true, false, 16384],
            'both' => [str_repeat('x', 60000), str_repeat('secret', 10000), true, true],
        ];
    }

    #[DataProvider('outputCases')]
    public function test_output_overflow_fails_during_reading(
        string $stdout, string $stderr, bool $stdoutOverflow, bool $stderrOverflow, int $cap = 16,
    ): void {
        $transport = new InMemoryProcessTransport(15.0, $cap, $cap);
        $transport->stdout = $stdout;
        $transport->stderr = $stderr;
        $result = $transport->execute('service');

        $this->assertFalse($result->isCleanExit());
        $this->assertSame(PowershellExecutionResult::CLASS_OUTPUT_OVERFLOW, $result->classification());
        $this->assertSame($stdoutOverflow ? $cap : null, $result->truncatedAt());
        $this->assertSame($stderrOverflow ? $cap : null, $result->stderrTruncatedAt());
        $this->assertTrue($transport->terminated);
        $this->assertTrue($transport->closed);
        $this->assertLessThanOrEqual($cap + 1, $transport->readOffsets[1]);
        $this->assertLessThanOrEqual($cap + 1, $transport->readOffsets[2]);
        $this->assertSame('', $result->stderr());
        $this->assertStringNotContainsString('secret', json_encode($result->toArray()));
    }

    public function test_exact_caps_are_allowed_and_final_buffered_output_is_drained(): void
    {
        $transport = new InMemoryProcessTransport(15.0, 16384, 16384);
        $transport->stdout = str_repeat('x', 16384);
        $transport->stderr = str_repeat('e', 16384);
        $result = $transport->execute('service');

        $this->assertTrue($result->isCleanExit());
        $this->assertSame($transport->stdout, $result->stdout());
        $this->assertSame($transport->stderr, $result->stderr());
        $this->assertFalse($transport->terminated);
    }

    public function test_cooperative_timeout_requests_termination_and_fails_closed(): void
    {
        $transport = new InMemoryProcessTransport(0.000001);
        $transport->running = true;
        $result = $transport->execute('service');

        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_timeout', $result->observationCode());
        $this->assertTrue($transport->terminated);
        $this->assertTrue($transport->closed);
    }

    public function test_nonzero_exit_is_sanitized_and_fails_closed(): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->exitCode = 5;
        $transport->stdout = 'state=running';
        $transport->stderr = 'private-account C:\\private\\path';
        $result = $transport->execute('service');

        $this->assertFalse($result->isCleanExit());
        $this->assertSame(5, $result->exitCode());
        $this->assertSame('powershell_nonzero_exit', $result->observationCode());
        $this->assertStringNotContainsString('private', json_encode($result->toArray()));
        $this->assertTrue($transport->closed);
    }

    public static function rejectedTimeouts(): array
    {
        return [
            'NAN' => [NAN], 'INF' => [INF], 'negative INF' => [-INF],
            'zero' => [0.0], 'negative' => [-1.0],
            'below resolution' => [0.0000001],
            'above supported maximum' => [86400.1],
            'finite extreme' => [PHP_FLOAT_MAX],
        ];
    }

    #[DataProvider('rejectedTimeouts')]
    public function test_unsupported_timeouts_are_rejected_before_launch(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryProcessTransport($timeout);
    }

    public function test_supported_timeout_boundaries(): void
    {
        $this->assertInstanceOf(PowershellObservationTransport::class, new InMemoryProcessTransport(0.000001));
        $this->assertInstanceOf(PowershellObservationTransport::class, new InMemoryProcessTransport(86400.0));
    }

    public function test_unrepresentable_deadline_rejected_before_launch(): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->clock = PHP_FLOAT_MAX;
        try {
            $transport->execute('service');
            $this->fail('Expected deadline rejection');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $transport->command);
        }
    }

    public static function streamFailureCases(): array
    {
        return [
            'nonblocking stdout' => ['nonblockingFailure', 1],
            'nonblocking stderr' => ['nonblockingFailure', 2],
            'first fread false' => ['readFailureOn', 1],
            'stderr fread false after partial stdout' => ['readFailureOn', 2],
            'later fread false after partial stdout' => ['readFailureOn', 3],
            'fread exception' => ['readThrowsOn', 2],
            'invalid resource during read' => ['invalidateOnRead', 2],
        ];
    }

    #[DataProvider('streamFailureCases')]
    public function test_stream_errors_discard_partial_output(string $property, int $value): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->stdout = 'state=running';
        $transport->stderr = 'private-account C:\\private\\path';
        $transport->{$property} = $value;
        $result = $transport->execute('service');

        $this->assertFalse($result->isCleanExit());
        // An invalid stream also makes its explicit cleanup fail.
        $this->assertSame($property === 'invalidateOnRead' ? 'powershell_cleanup_failed' : 'powershell_stream_failed', $result->observationCode());
        $this->assertSame('', $result->stdout());
        $this->assertSame('', $result->stderr());
        $this->assertStringNotContainsString('private', json_encode($result->toArray()));
        $this->assertTrue($transport->terminated);
        $this->assertTrue($transport->closed);
    }

    public function test_invalid_stream_at_launch_is_rejected(): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->invalidPipeAtLaunch = true;
        $result = $transport->execute('service');
        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_cleanup_failed', $result->observationCode());
        $this->assertSame(0, $transport->readCalls);
        $this->assertTrue($transport->closed);
    }

    public function test_process_status_returning_exited_after_deadline_is_timeout(): void
    {
        $transport = new InMemoryProcessTransport(1.0);
        $transport->clock = 100.0;
        $transport->statusDelay = 1.1;
        $result = $transport->execute('service');
        $this->assertSame('powershell_timeout', $result->observationCode());
        $this->assertFalse($result->isCleanExit());
        $this->assertTrue($transport->terminated);
    }

    public function test_timeout_during_reading_discards_pending_output(): void
    {
        $transport = new InMemoryProcessTransport(1.0);
        $transport->clock = 100.0;
        $transport->readDelay = 1.0;
        $transport->stdout = 'state=running';
        $transport->stderr = 'private';
        $result = $transport->execute('service');
        $this->assertSame('powershell_timeout', $result->observationCode());
        $this->assertSame('', $result->stdout());
        $this->assertSame(1, $transport->readCalls);
        $this->assertTrue($transport->terminated);
        $this->assertTrue($transport->closed);
    }

    public function test_launch_returning_after_deadline_reads_no_output(): void
    {
        $transport = new InMemoryProcessTransport(1.0);
        $transport->clock = 100.0;
        $transport->launchDelay = 1.1;
        $result = $transport->execute('service');
        $this->assertSame('powershell_timeout', $result->observationCode());
        $this->assertSame(0, $transport->readCalls);
    }

    public function test_termination_failure_is_explicit_even_if_cleanup_returns(): void
    {
        $transport = new InMemoryProcessTransport(1.0);
        $transport->clock = 100.0;
        $transport->launchDelay = 1.1;
        $transport->terminationSucceeds = false;
        $result = $transport->execute('service');
        $this->assertSame('powershell_cleanup_failed', $result->observationCode());
        $this->assertFalse($result->isCleanExit());
        $this->assertTrue($transport->terminated);
        $this->assertTrue($transport->closed);
    }

    public function test_cleanup_delay_can_exceed_deadline_and_is_not_success(): void
    {
        $transport = new InMemoryProcessTransport(1.0);
        $transport->clock = 100.0;
        $transport->cleanupDelay = 5.0;
        $result = $transport->execute('service');
        $this->assertSame('powershell_timeout', $result->observationCode());
        $this->assertSame(5.0, $result->elapsedSeconds());
        $this->assertFalse($result->isCleanExit());
    }

    public static function cleanupFailureCases(): array
    {
        return [
            'close exception' => ['closeThrows', true],
            'invalid close exit code' => ['exitCode', -1],
            'pipe close failure' => ['pipeCloseFails', true],
            'terminate exception' => ['terminateThrows', true],
        ];
    }

    #[DataProvider('cleanupFailureCases')]
    public function test_cleanup_errors_are_sanitized(string $property, bool|int $value): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->{$property} = $value;
        if ($property === 'terminateThrows') {
            $transport->readFailureOn = 1;
        }
        $transport->stdout = 'state=running';
        $result = $transport->execute('service');
        $this->assertSame('powershell_cleanup_failed', $result->observationCode());
        $this->assertFalse($result->isCleanExit());
        $this->assertSame('', $result->stdout());
        $this->assertStringNotContainsString('private', json_encode($result->toArray()));
        $this->assertTrue($transport->closed);
    }

    public static function exactOutputBoundaries(): array
    {
        return [
            'exact stdout' => [16, 0, true, null, null],
            'stdout plus one' => [17, 0, false, 16, null],
            'exact stderr' => [0, 16, true, null, null],
            'stderr plus one' => [0, 17, false, null, 16],
        ];
    }

    #[DataProvider('exactOutputBoundaries')]
    public function test_exact_and_plus_one_output_limits(int $stdout, int $stderr, bool $clean, ?int $stdoutCap, ?int $stderrCap): void
    {
        $transport = new InMemoryProcessTransport(15.0, 16, 16);
        $transport->stdout = str_repeat('x', $stdout);
        $transport->stderr = str_repeat('s', $stderr);
        $result = $transport->execute('service');
        $this->assertSame($clean, $result->isCleanExit());
        $this->assertSame($stdoutCap, $result->truncatedAt());
        $this->assertSame($stderrCap, $result->stderrTruncatedAt());
        $this->assertSame($clean ? $stdout : 0, strlen($result->stdout()));
        $this->assertSame($clean ? $stderr : 0, strlen($result->stderr()));
    }

    public function test_launch_failure_is_sanitized(): void
    {
        $transport = new InMemoryProcessTransport;
        $transport->launchFails = true;
        $result = $transport->execute('service');
        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_launch_failed', $result->observationCode());
        $this->assertSame('', $result->stderr());
    }
}

/** Replaces native process primitives, retaining validation and output collection. */
final class InMemoryProcessTransport extends PowershellObservationTransport
{
    public array $command = [];
    public array $environment = [];
    public array $readOffsets = [];
    public string $stdout = '';
    public string $stderr = '';
    public int $exitCode = 0;
    public bool $running = false;
    public bool $terminated = false;
    public bool $closed = false;
    public bool $launchFails = false;
    public ?float $clock = null;
    public float $launchDelay = 0.0;
    public float $statusDelay = 0.0;
    public float $readDelay = 0.0;
    public float $cleanupDelay = 0.0;
    public int $nonblockingFailure = 0;
    public int $readFailureOn = 0;
    public int $readThrowsOn = 0;
    public int $invalidateOnRead = 0;
    public int $readCalls = 0;
    public bool $invalidPipeAtLaunch = false;
    public bool $terminationSucceeds = true;
    public bool $closeThrows = false;
    public bool $terminateThrows = false;
    public bool $pipeCloseFails = false;
    private array $outputPipes = [];

    public function __construct(float $timeout = 15.0, int $stdoutCap = 65536, int $stderrCap = 8192)
    {
        parent::__construct(self::TRUSTED_EXECUTABLE, $timeout, $stdoutCap, $stderrCap);
    }

    protected function launchProcess(array $command, array &$pipes, array $env)
    {
        $this->command = $command;
        $this->environment = $env;
        if ($this->launchFails) {
            return false;
        }
        foreach (['', $this->stdout, $this->stderr] as $index => $content) {
            $pipes[$index] = fopen('php://memory', 'w+');
            fwrite($pipes[$index], $content);
            rewind($pipes[$index]);
        }
        $this->outputPipes = [1 => $pipes[1], 2 => $pipes[2]];
        if ($this->invalidPipeAtLaunch) {
            fclose($pipes[1]);
        }
        if ($this->clock !== null) {
            $this->clock += $this->launchDelay;
        }

        return fopen('php://memory', 'w+');
    }

    protected function processStatus($proc): array
    {
        if ($this->clock !== null) {
            $this->clock += $this->statusDelay;
        }
        return ['running' => $this->running, 'exitcode' => $this->exitCode];
    }

    protected function terminateProcess($proc): bool
    {
        $this->terminated = true;
        foreach ($this->outputPipes as $index => $pipe) {
            $this->readOffsets[$index] = is_resource($pipe) ? ftell($pipe) : 0;
        }
        if ($this->terminateThrows) {
            throw new \RuntimeException('private termination error');
        }
        return $this->terminationSucceeds;
    }

    protected function closeProcess($proc): int
    {
        $this->closed = true;
        fclose($proc);
        if ($this->clock !== null) {
            $this->clock += $this->cleanupDelay;
        }
        if ($this->closeThrows) {
            throw new \RuntimeException('private cleanup error');
        }

        return $this->exitCode;
    }

    protected function now(): float
    {
        return $this->clock ?? parent::now();
    }

    protected function configureNonblocking($pipe): bool
    {
        // Memory streams have no native pipe blocking semantics to configure.
        return $this->nonblockingFailure === 0 || $pipe !== $this->outputPipes[$this->nonblockingFailure];
    }

    protected function readPipe($pipe, int $length): string|false
    {
        $this->readCalls++;
        if ($this->clock !== null) {
            $this->clock += $this->readDelay;
        }
        if ($this->readCalls === $this->readFailureOn) {
            return false;
        }
        if ($this->readCalls === $this->readThrowsOn) {
            throw new \RuntimeException('private stream error');
        }
        if ($this->readCalls === $this->invalidateOnRead) {
            fclose($pipe);
        }
        return parent::readPipe($pipe, $length);
    }

    protected function closePipe($pipe): bool
    {
        $closed = parent::closePipe($pipe);
        return $closed && ! $this->pipeCloseFails;
    }
}
