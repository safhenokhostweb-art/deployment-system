<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness\Transport;

use App\Services\Sentinel\Readiness\HostReadinessOutcome;
use App\Services\Sentinel\Readiness\HypervReadinessChecker;
use App\Services\Sentinel\Readiness\SentinelReadinessObservationException;
use App\Services\Sentinel\Readiness\StoragePolicy;
use App\Services\Sentinel\Readiness\StorageReadinessChecker;
use App\Services\Sentinel\Readiness\Transport\PowershellExecutionResult;
use App\Services\Sentinel\Readiness\Transport\PowershellObservationParser;
use App\Services\Sentinel\Readiness\Transport\WindowsHostReadinessObserver;
use PHPUnit\Framework\TestCase;

/**
 * Failure-mode and trust-boundary tests for the
 * WindowsHostReadinessObserver.
 *
 * Every test uses the SyntheticPowershellTransport, so no process is
 * launched and the real host is never touched.
 */
final class WindowsHostReadinessFailureTest extends TestCase
{
    private const ROOT = 'D:\Sentinel';

    private SyntheticPowershellTransport $transport;

    private WindowsHostReadinessObserver $observer;

    protected function setUp(): void
    {
        $this->transport = new SyntheticPowershellTransport;
        $this->observer = new WindowsHostReadinessObserver(
            $this->transport,
            new PowershellObservationParser,
        );
    }

    private function clean(string $stdout, int $exitCode = 0, ?int $truncatedAt = null): PowershellExecutionResult
    {
        return PowershellExecutionResult::completed($exitCode, $stdout, '', 0.1, $truncatedAt);
    }

    private function healthyFilesystem(): string
    {
        return "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n";
    }

    // ==================================================================
    // Access denied
    // ==================================================================

    public function test_access_denied_on_service_query_is_unknown_not_pass(): void
    {
        $this->transport->nextResult($this->clean('', 5));

        try {
            $this->observer->observeServiceState('vmms');
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_service_powershell_nonzero_exit', $e->code());
        }
    }

    public function test_access_denied_on_group_query_is_unknown_not_pass(): void
    {
        $this->transport->nextResult($this->clean('', 5));

        try {
            $this->observer->observeGroupMembership('Hyper-V Administrators');
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_group_powershell_nonzero_exit', $e->code());
        }
    }

    public function test_access_denied_on_volume_query_is_unknown_not_pass(): void
    {
        $this->transport->nextResult($this->clean('', 5));

        $this->expectException(SentinelReadinessObservationException::class);

        $this->observer->observeFilesystem(self::ROOT);
    }

    // ==================================================================
    // Sanitized errors
    // ==================================================================

    public function test_exception_message_is_a_stable_code_not_native_text(): void
    {
        $native = '#< CLIXML <Objs><S S="Error">Get-CimInstance : Access is denied for user CONTOSO\\admin</S></Objs>';
        $result = PowershellExecutionResult::completed(5, '', $native, 0.1, null);

        $this->transport->nextResult($result);

        try {
            $this->observer->observeFilesystem(self::ROOT);
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_fs_powershell_nonzero_exit', $e->getMessage());
            $this->assertStringNotContainsString('CLIXML', $e->getMessage());
            $this->assertStringNotContainsString('CONTOSO', $e->getMessage());
            $this->assertStringNotContainsString('admin', $e->getMessage());
            $this->assertStringNotContainsString('Get-CimInstance', $e->getMessage());
        }
    }

    public function test_timeout_exception_message_carries_no_native_text(): void
    {
        $this->transport->nextResult(PowershellExecutionResult::timeout(15.0));

        try {
            $this->observer->observeProcessElevation();
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_elevation_powershell_timeout', $e->getMessage());
        }
    }

    public function test_stdout_truncation_is_a_probe_failure(): void
    {
        $this->transport->nextResult($this->clean(str_repeat('exists=1', 100000), 0, 65536));

        try {
            $this->observer->observeFreeSpace(self::ROOT);
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_freespace_stdout_truncated', $e->getMessage());
        }
    }

    // ==================================================================
    // Forged positive observations
    // ==================================================================

    public function test_forged_full_positive_chain_still_authorizes_nothing(): void
    {
        $this->transport->nextResult($this->clean($this->healthyFilesystem()));
        $this->transport->nextResult($this->clean("bytesFree=" . PHP_INT_MAX . "\r\nbytesTotal=" . PHP_INT_MAX . "\r\n"));
        $this->transport->nextResult($this->clean("available=1\r\n"));
        $this->transport->nextResult($this->clean("present=1\r\nstate=Running\r\n"));
        $this->transport->nextResult($this->clean("member=1\r\n"));
        $this->transport->nextResult($this->clean("elevated=1\r\n"));

        $storage = new StorageReadinessChecker($this->observer, StoragePolicy::defaults());
        $hyperv = new HypervReadinessChecker($this->observer);

        $storageResult = $storage->check(self::ROOT);
        $hypervResult = $hyperv->check();

        $this->assertSame(HostReadinessOutcome::PASS, $storageResult['report']->overall());
        $this->assertSame(HostReadinessOutcome::PASS, $hypervResult['report']->overall());
        $this->assertSame([], $storageResult['report']->blockers());
        $this->assertSame([], $hypervResult['report']->blockers());
    }

    // ==================================================================
    // Parser hardening
    // ==================================================================

    public function test_parser_rejects_a_value_containing_a_nul_byte(): void
    {
        $parser = new PowershellObservationParser;

        $fields = $parser->parseLines("filesystem=NT" . "\0" . "FS\r\n");

        $this->assertSame([], $fields);
    }

    public function test_parser_rejects_a_line_with_no_key(): void
    {
        $parser = new PowershellObservationParser;

        $fields = $parser->parseLines("=NTFS\r\n");

        $this->assertSame([], $fields);
    }

    public function test_parser_rejects_a_line_with_no_separator(): void
    {
        $parser = new PowershellObservationParser;

        $fields = $parser->parseLines("garbage\r\n");

        $this->assertSame([], $fields);
    }

    public function test_parser_keeps_the_first_occurrence_of_a_duplicated_key(): void
    {
        $parser = new PowershellObservationParser;

        $fields = $parser->parseLines("exists=1\r\nexists=0\r\n");

        $this->assertSame(['exists' => '1'], $fields);
    }

    public function test_parser_treats_only_the_first_equals_as_a_separator(): void
    {
        $parser = new PowershellObservationParser;

        $fields = $parser->parseLines("canonicalPath=D:\\Sen=tinel\r\n");

        $this->assertSame(['canonicalPath' => 'D:\\Sen=tinel'], $fields);
    }

    public function test_parser_rejects_an_unknown_filesystem_name(): void
    {
        $parser = new PowershellObservationParser;

        $fs = $parser->parseFilesystemName(['filesystem' => 'ZFS'], 'filesystem');

        $this->assertFalse($fs['ok']);
    }

    public function test_parser_rejects_a_canonical_path_without_a_drive_letter(): void
    {
        $parser = new PowershellObservationParser;

        $path = $parser->parseCanonicalPath(['canonicalPath' => '\\\\server\\share'], 'canonicalPath');

        $this->assertFalse($path['ok']);
    }

    public function test_parser_rejects_a_canonical_path_with_parent_traversal(): void
    {
        $parser = new PowershellObservationParser;

        $path = $parser->parseCanonicalPath(['canonicalPath' => 'D:\\Sentinel\\..\\evil'], 'canonicalPath');

        $this->assertFalse($path['ok']);
    }

    public function test_parser_rejects_an_overlong_canonical_path(): void
    {
        $parser = new PowershellObservationParser;

        $path = $parser->parseCanonicalPath(['canonicalPath' => 'D:\\' . str_repeat('a', 300)], 'canonicalPath');

        $this->assertFalse($path['ok']);
    }

    public function test_parser_rejects_a_non_numeric_byte_count(): void
    {
        $parser = new PowershellObservationParser;

        $int = $parser->parseNonNegativeInt(['bytesFree' => 'lots'], 'bytesFree');

        $this->assertFalse($int['ok']);
    }

    public function test_parser_rejects_a_negative_byte_count(): void
    {
        $parser = new PowershellObservationParser;

        $int = $parser->parseNonNegativeInt(['bytesFree' => '-5'], 'bytesFree');

        $this->assertFalse($int['ok']);
    }

    public function test_parser_rejects_an_overlong_byte_count(): void
    {
        $parser = new PowershellObservationParser;

        $int = $parser->parseNonNegativeInt(['bytesFree' => str_repeat('9', 40)], 'bytesFree');

        $this->assertFalse($int['ok']);
    }

    public function test_parser_rejects_an_unknown_service_state(): void
    {
        $parser = new PowershellObservationParser;

        $state = $parser->parseServiceState(['state' => 'OnFire'], 'state');

        $this->assertFalse($state['ok']);
    }

    public function test_parser_rejects_an_unknown_flag_value(): void
    {
        $parser = new PowershellObservationParser;

        $flag = $parser->parseFlag(['exists' => 'maybe'], 'exists');

        $this->assertFalse($flag['ok']);
    }

    public function test_parser_rejects_an_unknown_volume_type(): void
    {
        $parser = new PowershellObservationParser;

        $type = $parser->parseVolumeType(['type' => 'network'], 'type');

        $this->assertFalse($type['ok']);
    }

    public function test_parser_normalizes_a_forward_slash_canonical_path(): void
    {
        $parser = new PowershellObservationParser;

        $path = $parser->parseCanonicalPath(['canonicalPath' => 'D:/Sentinel'], 'canonicalPath');

        $this->assertTrue($path['ok']);
        $this->assertSame('D:\Sentinel', $path['canonicalPath']);
    }

    public function test_parser_accepts_every_documented_service_state(): void
    {
        $parser = new PowershellObservationParser;

        foreach (['Running', 'Stopped', 'StartPending', 'StopPending', 'Paused', 'ContinuePending'] as $state) {
            $parsed = $parser->parseServiceState(['state' => $state], 'state');

            $this->assertTrue($parsed['ok'], $state);
            $this->assertSame($state, $parsed['value']);
        }
    }

    // ==================================================================
    // Execution result classification
    // ==================================================================

    public function test_execution_result_rejects_an_invalid_classification(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $ref = new \ReflectionClass(PowershellExecutionResult::class);
            $ctor = $ref->getConstructor();
            $ctor->setAccessible(true);
            $instance = $ref->newInstanceWithoutConstructor();
            $ctor->invokeArgs($instance, ['BOGUS', 0, '', '', 0.0, null]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('COMPLETED', $e->getMessage());
            throw $e;
        }
    }

    public function test_a_nonzero_exit_code_is_not_a_clean_exit(): void
    {
        $result = $this->clean('', 1);

        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_nonzero_exit', $result->observationCode());
    }

    public function test_a_timeout_is_not_a_clean_exit(): void
    {
        $result = PowershellExecutionResult::timeout(15.0);

        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_timeout', $result->observationCode());
    }

    public function test_a_launch_failure_is_not_a_clean_exit(): void
    {
        $result = PowershellExecutionResult::launchFailed(0.0);

        $this->assertFalse($result->isCleanExit());
        $this->assertSame('powershell_launch_failed', $result->observationCode());
    }

    public function test_execution_result_to_array_exposes_no_native_text(): void
    {
        $result = PowershellExecutionResult::completed(1, 'exists=1', 'secret account data', 0.5, null);

        $array = $result->toArray();

        $this->assertStringNotContainsString('secret account data', json_encode($array));
        $this->assertArrayNotHasKey('stdout', $array);
        $this->assertArrayNotHasKey('stderr', $array);
        $this->assertSame(strlen('exists=1'), $array['stdoutLength']);
    }
}
