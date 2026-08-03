<?php

declare(strict_types=1);

/**
 * Real, non-mocked subprocess tests for agent.php's own
 * composer_command/npm_command/php_binary config validation
 * (resolveCommandArrayConfig()/resolvePhpBinaryConfig()) — these
 * functions call exit(1) on a malformed config, which cannot be
 * exercised safely in-process (it would kill this very test runner), so
 * each scenario runs the REAL agent.php as its own child process against
 * a throwaway copy of the agent tree and a disposable config.php — the
 * real, installed config.php is never read or touched.
 *
 * Usage: php tests/run_agent_config_validation.php
 */

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, array &$failures, int &$passCount): void
{
    if ($condition) {
        $passCount++;

        return;
    }

    $failures[] = $description;
}

function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/local-agent-configval-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);

            continue;
        }
        @chmod($path, 0666);
        @unlink($path);
    }
    @rmdir($dir);
}

function copyAgentTreeToTemp(): string
{
    $dest = makeWorkspace() . '/agent';
    mkdir($dest, 0777, true);
    mkdir("{$dest}/lib", 0777, true);

    copy(__DIR__ . '/../agent.php', "{$dest}/agent.php");
    foreach (glob(__DIR__ . '/../lib/*.php') as $libFile) {
        copy($libFile, "{$dest}/lib/" . basename($libFile));
    }

    return $dest;
}

/**
 * Base config every scenario starts from — a fake, never-real
 * hub/token/secret (this never talks to a real Hub: hub_url points at an
 * unroutable address with a short timeout, and every network call in
 * agent.php's main loop already tolerates a Hub it can't reach). Merged
 * with each scenario's own overrides.
 *
 * @param array<string, mixed> $overrides
 */
function writeScenarioConfig(string $agentDir, array $overrides): void
{
    $base = [
        'hub_url' => 'http://127.0.0.1:1',
        'local_agent_id' => 'test-agent-id',
        'agent_token' => 'not-a-real-token-but-not-change-me-either',
        'signing_secret' => 'not-a-real-secret',
        'poll_interval_seconds' => 15,
        'request_timeout_seconds' => 1,
        'projects' => [],
        'log_dir' => $agentDir . '/storage/logs',
    ];
    $config = array_merge($base, $overrides);

    $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    file_put_contents("{$agentDir}/config.php", $php);
}

/**
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runAgentOnce(string $agentDir): array
{
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, "{$agentDir}/agent.php", '--once'], $descriptorSpec, $pipes, $agentDir);

    if (!is_resource($process)) {
        return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'could not start agent.php subprocess'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

// ============================================================
// Invalid: composer_command is an empty array — must refuse to start,
// never silently fall back and never reach proc_open() with an empty argv.
// ============================================================
$dir1 = copyAgentTreeToTemp();
writeScenarioConfig($dir1, ['composer_command' => []]);
$run1 = runAgentOnce($dir1);
check('agent.php refuses to start when composer_command is an empty array', $run1['exitCode'] === 1, $failures, $passCount);
check('agent.php explains why (non-empty array of strings)', str_contains($run1['stderr'], "'composer_command' must be a non-empty array of strings"), $failures, $passCount);
rrmdir(dirname($dir1));

// ============================================================
// Invalid: composer_command contains a non-string element.
// ============================================================
$dir2 = copyAgentTreeToTemp();
writeScenarioConfig($dir2, ['composer_command' => [123, 'install']]);
$run2 = runAgentOnce($dir2);
check('agent.php refuses to start when composer_command contains a non-string element', $run2['exitCode'] === 1, $failures, $passCount);
check('agent.php explains why (only non-empty strings)', str_contains($run2['stderr'], "'composer_command' must contain only non-empty strings"), $failures, $passCount);
rrmdir(dirname($dir2));

// ============================================================
// Invalid: npm_command is not even an array.
// ============================================================
$dir3 = copyAgentTreeToTemp();
writeScenarioConfig($dir3, ['npm_command' => 'npm']);
$run3 = runAgentOnce($dir3);
check('agent.php refuses to start when npm_command is a string instead of an array', $run3['exitCode'] === 1, $failures, $passCount);
check('agent.php explains why, naming npm_command specifically', str_contains($run3['stderr'], "'npm_command' must be a non-empty array of strings"), $failures, $passCount);
rrmdir(dirname($dir3));

// ============================================================
// Invalid: php_binary present but blank.
// ============================================================
$dir4 = copyAgentTreeToTemp();
writeScenarioConfig($dir4, ['php_binary' => '']);
$run4 = runAgentOnce($dir4);
check('agent.php refuses to start when php_binary is an empty string', $run4['exitCode'] === 1, $failures, $passCount);
check('agent.php explains why, naming php_binary specifically', str_contains($run4['stderr'], "'php_binary'"), $failures, $passCount);
rrmdir(dirname($dir4));

// ============================================================
// Valid: composer_command/npm_command/php_binary all omitted entirely —
// must NOT be rejected; falls back to the previous, PATH-dependent
// behavior and runs a full --once cycle normally (exit 0).
// ============================================================
$dir5 = copyAgentTreeToTemp();
writeScenarioConfig($dir5, []);
$run5 = runAgentOnce($dir5);
check('agent.php starts normally when composer_command/npm_command/php_binary are all simply absent', $run5['exitCode'] === 0, $failures, $passCount);
rrmdir(dirname($dir5));

// ============================================================
// Valid: a well-formed custom composer_command/npm_command/php_binary —
// must be accepted and run a full --once cycle normally (exit 0), proving
// the happy path through agent.php's own real validation code, not just
// LocalBuildRunner's in isolation.
// ============================================================
$dir6 = copyAgentTreeToTemp();
writeScenarioConfig($dir6, [
    'composer_command' => [PHP_BINARY, $dir6 . '/does-not-need-to-exist-for-this-check/composer.phar'],
    'npm_command' => ['C:\\Program Files\\nodejs\\npm.cmd'],
    'php_binary' => PHP_BINARY,
]);
$run6 = runAgentOnce($dir6);
check('agent.php accepts a well-formed custom composer_command/npm_command/php_binary and completes a --once cycle', $run6['exitCode'] === 0, $failures, $passCount);
rrmdir(dirname($dir6));

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
