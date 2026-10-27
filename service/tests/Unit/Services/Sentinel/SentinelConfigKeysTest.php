<?php

namespace Tests\Unit\Services\Sentinel;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Regression guard for two dead Sentinel switches: the code read
 * config('sentinel.file_verification_enabled') although the flag is defined
 * as deployment.sentinel.file_verification_enabled (there is no
 * config/sentinel.php), and every reader used
 * deployment.runtime_snapshot_freshness_seconds while the config nested it
 * under deployment.sentinel. Both env variables were silently ignored, and
 * tests that set the misspelled key directly via config([...]) hid it.
 */
class SentinelConfigKeysTest extends TestCase
{
    public function test_every_key_the_sentinel_code_reads_is_defined_in_config(): void
    {
        $this->assertTrue(config()->has('deployment.sentinel.file_verification_enabled'));
        $this->assertTrue(config()->has('deployment.sentinel.file_comparison_scope'));
        $this->assertTrue(config()->has('deployment.runtime_snapshot_freshness_seconds'));
        $this->assertIsInt(config('deployment.runtime_snapshot_freshness_seconds'));
    }

    public function test_no_application_code_reads_an_undefined_top_level_sentinel_config_key(): void
    {
        $this->assertFileDoesNotExist(config_path('sentinel.php'));

        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            // Covers config('sentinel.x') and ->get('sentinel.x') on the config repository.
            if ($file->getExtension() === 'php' && preg_match("/(config|->get)\\(\\s*'sentinel\\./", (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, "These files read config('sentinel.*'), which is not defined; use deployment.sentinel.*.");
    }
}
