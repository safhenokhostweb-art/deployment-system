<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness\Transport;

use App\Services\Sentinel\Readiness\HostReadinessOutcome;
use App\Services\Sentinel\Readiness\HypervReadinessChecker;
use App\Services\Sentinel\Readiness\SentinelReadinessObservationException;
use App\Services\Sentinel\Readiness\StoragePolicy;
use App\Services\Sentinel\Readiness\StorageReadinessChecker;
use App\Services\Sentinel\Readiness\Transport\PowershellAllowedScripts;
use App\Services\Sentinel\Readiness\Transport\PowershellExecutionResult;
use App\Services\Sentinel\Readiness\Transport\PowershellObservationParser;
use App\Services\Sentinel\Readiness\Transport\PowershellObservationTransport;
use App\Services\Sentinel\Readiness\Transport\WindowsHostReadinessObserver;
use PHPUnit\Framework\TestCase;

/**
 * WindowsHostReadinessObserver tests.
 *
 * Every test uses the SyntheticPowershellTransport, so no process is
 * ever launched and the real host is never touched. No test creates a
 * file, starts a service, requests elevation or modifies an ACL.
 */
final class WindowsHostReadinessObserverTest extends TestCase
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

    // ==================================================================
    // PHASE 2 - storage observations
    // ==================================================================

    public function test_ntfs_fixed_volume_observation_parses(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n",
        ));

        $fs = $this->observer->observeFilesystem(self::ROOT);

        $this->assertTrue($fs['exists']);
        $this->assertSame('fixed', $fs['type']);
        $this->assertSame('NTFS', $fs['filesystem']);
        $this->assertSame('D:\Sentinel', $fs['canonicalPath']);
        $this->assertFalse($fs['reparsePointAbove']);
        $this->assertTrue($fs['supportedFilesystem']);
        $this->assertNotEmpty($fs['observedAt']);
    }

    public function test_unsupported_filesystem_is_reported_not_failed(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=exFAT\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n",
        ));

        $fs = $this->observer->observeFilesystem(self::ROOT);

        $this->assertTrue($fs['exists']);
        $this->assertSame('EXFAT', $fs['filesystem']);
        $this->assertFalse($fs['supportedFilesystem']);
    }

    public function test_missing_storage_root_reports_exists_false(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=0\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n",
        ));

        $fs = $this->observer->observeFilesystem(self::ROOT);

        $this->assertFalse($fs['exists']);
        $this->assertSame('D:\Sentinel', $fs['canonicalPath']);
    }

    public function test_reparse_point_detection_is_reported(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=1\r\n",
        ));

        $fs = $this->observer->observeFilesystem(self::ROOT);

        $this->assertTrue($fs['reparsePointAbove']);
    }

    public function test_unknown_canonical_path_yields_null_not_pass(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=\r\nreparsePointAbove=0\r\n",
        ));

        $fs = $this->observer->observeFilesystem(self::ROOT);

        $this->assertTrue($fs['exists']);
        $this->assertNull($fs['canonicalPath']);
    }

    public function test_free_space_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("bytesFree=1099511627776\r\nbytesTotal=2199023255552\r\n"));

        $space = $this->observer->observeFreeSpace(self::ROOT);

        $this->assertSame(1099511627776, $space['bytesFree']);
        $this->assertSame(2199023255552, $space['bytesTotal']);
    }

    public function test_insufficient_free_space_is_reported_by_the_checker_not_the_observer(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n",
        ));

        $this->transport->nextResult($this->clean("bytesFree=100\r\nbytesTotal=200\r\n"));

        $checker = new StorageReadinessChecker($this->observer, StoragePolicy::defaults());

        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::BLOCKED, $result['report']->overall());
        $this->assertContains('storage_capacity_below_effective_floor', $result['report']->blockers());
    }

    // ==================================================================
    // PHASE 4 - PowerShell safety
    // ==================================================================

    public function test_invalid_command_output_fails_closed(): void
    {
        $this->transport->nextResult($this->clean("this is not structured output at all"));

        $this->expectException(SentinelReadinessObservationException::class);

        $this->observer->observeFilesystem(self::ROOT);
    }

    public function test_powershell_timeout_yields_unknown_not_pass(): void
    {
        $this->transport->nextResult(PowershellExecutionResult::timeout(15.0));

        try {
            $this->observer->observeFilesystem(self::ROOT);
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_fs_powershell_timeout', $e->code());
            $this->assertStringNotContainsString('powershell.exe', $e->getMessage());
        }
    }

    public function test_powershell_nonzero_exit_yields_unknown_not_pass(): void
    {
        $this->transport->nextResult($this->clean('', 5));

        try {
            $this->observer->observeFreeSpace(self::ROOT);
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_freespace_powershell_nonzero_exit', $e->code());
        }
    }

    public function test_launch_failure_yields_unknown_not_pass(): void
    {
        $this->transport->nextResult(PowershellExecutionResult::launchFailed(0.0));

        try {
            $this->observer->observeServiceState('vmms');
            $this->fail('expected SentinelReadinessObservationException');
        } catch (SentinelReadinessObservationException $e) {
            $this->assertSame('windows_service_powershell_launch_failed', $e->code());
        }
    }

    public function test_excessive_stdout_is_a_probe_failure(): void
    {
        $this->transport->nextResult($this->clean(str_repeat('exists=1', 100000), 0, 65536));

        $this->expectException(SentinelReadinessObservationException::class);

        $this->observer->observeFilesystem(self::ROOT);
    }

    public function test_excessive_stderr_is_a_probe_failure(): void
    {
        $result = PowershellExecutionResult::outputOverflow(0.1, null, 8192);

        $this->transport->nextResult($result);

        $this->expectException(SentinelReadinessObservationException::class);
        $this->expectExceptionMessage('windows_fs_powershell_stderr_overflow');

        $this->observer->observeFilesystem(self::ROOT);
    }

    public function test_transport_refuses_an_unallowlisted_script_token(): void
    {
        try {
            $this->transport->execute('Invoke-Expression "whoami"');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('whoami', $e->getMessage());
        }

        $this->assertNotContains('Invoke-Expression "whoami"', PowershellAllowedScripts::all());
        $this->assertSame([], $this->transport->completedTokens());
    }

    public function test_an_unallowlisted_token_produces_no_script_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PowershellAllowedScripts::resolve('Start-VM -Name evil');
    }

    public function test_only_allowlisted_script_tokens_exist(): void
    {
        $tokens = array_keys(PowershellAllowedScripts::all());

        $this->assertSame(['filesystem', 'freespace', 'service', 'module', 'elevation', 'group'], $tokens);
    }

    public function test_no_allowlisted_script_contains_invoke_expression(): void
    {
        foreach (PowershellAllowedScripts::all() as $token => $script) {
            $this->assertStringNotContainsStringIgnoringCase('Invoke-Expression', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Invoke-WebRequest', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Invoke-RestMethod', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Start-VM', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('New-VM', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Stop-VM', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Remove-VM', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('New-VHD', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Set-VM', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Enable-WindowsOptionalFeature', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Start-Service', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Set-ExecutionPolicy', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Enter-PSSession', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('New-Item', $script, $token);
            $this->assertStringNotContainsStringIgnoringCase('Set-Acl', $script, $token);
        }
    }

    public function test_caller_values_travel_through_environment_not_the_command_line(): void
    {
        $evil = 'vmms"; calc; $(whoami)';

        $this->transport->nextResult($this->clean("present=1\r\nstate=Running\r\n"));

        $this->observer->observeServiceState($evil);

        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('service', $this->transport->calls[0]['token']);
        $this->assertSame($evil, $this->transport->calls[0]['values']['SENTINEL_OBSERVE_SERVICE']);
    }

    // ==================================================================
    // PHASE 5 - trust boundary
    // ==================================================================

    public function test_forged_positive_observations_still_carry_no_authorization(): void
    {
        $this->transport->nextResult($this->clean(
            "exists=1\r\ntype=fixed\r\nfilesystem=NTFS\r\ncanonicalPath=D:\\Sentinel\r\nreparsePointAbove=0\r\n",
        ));

        $this->transport->nextResult($this->clean("bytesFree=" . PHP_INT_MAX . "\r\nbytesTotal=" . PHP_INT_MAX . "\r\n"));

        $checker = new StorageReadinessChecker($this->observer, StoragePolicy::defaults());

        $result = $checker->check(self::ROOT);

        $this->assertSame(HostReadinessOutcome::PASS, $result['report']->overall());
        $this->assertTrue($result['report']->rootExists());
    }

    // ==================================================================
    // PHASE 3 - Hyper-V observations
    // ==================================================================

    public function test_hyperv_module_available_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("available=1\r\n"));

        $module = $this->observer->observeModuleAvailable('Hyper-V');

        $this->assertTrue($module['available']);
    }

    public function test_hyperv_module_unavailable_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("available=0\r\n"));

        $module = $this->observer->observeModuleAvailable('Hyper-V');

        $this->assertFalse($module['available']);
    }

    public function test_vmms_running_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("present=1\r\nstate=Running\r\n"));

        $service = $this->observer->observeServiceState('vmms');

        $this->assertTrue($service['present']);
        $this->assertSame('Running', $service['state']);
    }

    public function test_vmms_stopped_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("present=1\r\nstate=Stopped\r\n"));

        $service = $this->observer->observeServiceState('vmms');

        $this->assertTrue($service['present']);
        $this->assertSame('Stopped', $service['state']);
    }

    public function test_vmms_missing_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("present=0\r\nstate=\r\n"));

        $service = $this->observer->observeServiceState('vmms');

        $this->assertFalse($service['present']);
        $this->assertNull($service['state']);
    }

    public function test_elevated_process_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("elevated=1\r\n"));

        $elevation = $this->observer->observeProcessElevation();

        $this->assertTrue($elevation['elevated']);
    }

    public function test_non_elevated_process_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("elevated=0\r\n"));

        $elevation = $this->observer->observeProcessElevation();

        $this->assertFalse($elevation['elevated']);
    }

    public function test_group_member_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("member=1\r\n"));

        $group = $this->observer->observeGroupMembership('Hyper-V Administrators');

        $this->assertTrue($group['member']);
    }

    public function test_missing_group_membership_observation_parses(): void
    {
        $this->transport->nextResult($this->clean("member=0\r\n"));

        $group = $this->observer->observeGroupMembership('Hyper-V Administrators');

        $this->assertFalse($group['member']);
    }

    public function test_management_api_denied_is_not_inferred_from_module_presence(): void
    {
        // The module is importable and vmms is running, but the process
        // is not elevated and the user is not in the group. The checker
        // must not promote module presence to a management
        // authorization claim.
        $checker = new HypervReadinessChecker($this->observer);

        $this->transport->nextResult($this->clean("available=1\r\n"));
        $this->transport->nextResult($this->clean("present=1\r\nstate=Running\r\n"));
        $this->transport->nextResult($this->clean("member=0\r\n"));
        $this->transport->nextResult($this->clean("elevated=0\r\n"));

        $result = $checker->check();

        $this->assertSame(HostReadinessOutcome::BLOCKED, $result['report']->overall());
        $this->assertContains('hyperv_group_not_member', $result['report']->blockers());
        $this->assertContains('hyperv_process_not_elevated', $result['report']->blockers());
    }
}
