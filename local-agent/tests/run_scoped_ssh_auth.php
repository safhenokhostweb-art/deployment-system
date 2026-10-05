<?php

declare(strict_types=1);

/**
 * Test for the ScopedSshConfig class — verifies that the scoped SSH identity
 * (derived from the `github-safhe` alias) is correctly activated/deactivated
 * around Artifact Builder git transports, without modifying ~/.ssh/config.
 *
 * Usage: php tests/run_scoped_ssh_auth.php
 *
 * This test uses the real `ssh -G github-safhe` command. On machines where the
 * `github-safhe` alias is not configured, tests that depend on an IdentityFile
 * are skipped (the test exits 0 with a note).
 */

require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/ScopedSshConfig.php';

use LocalAgent\GitManager;
use LocalAgent\Logger;
use LocalAgent\ScopedSshConfig;

$failures = [];
$passed = 0;

$check = static function (string $label, bool $ok) use (&$failures, &$passed): void {
    $ok ? $passed++ : $failures[] = $label;
};

$git = new GitManager();
$logDir = sys_get_temp_dir() . '/scoped-ssh-test-' . bin2hex(random_bytes(6));
$logger = new Logger($logDir);

// --- 0. Restore clean env state and save what was there ---

$previousEnv = getenv('GIT_SSH_COMMAND');
putenv('GIT_SSH_COMMAND'); // ensure clean slate

// --- 1. discoverSshSettings() must find the github-safhe IdentityFile ---

$probe = $git->run(['ssh', '-G', 'github-safhe'], sys_get_temp_dir(), 10);
$hasIdentityFile = $probe['exitCode'] === 0 && preg_match('/^identityfile\s+(\S+)/im', $probe['output'], $m) === 1;

if (!$hasIdentityFile) {
    echo "[skip] 'github-safhe' alias not found or no IdentityFile — running CI-compatible checks.\n";

    // Even without the alias, deactivate() must be safe and idempotent.
    $scoped = new ScopedSshConfig($git, $logger);
    $scoped->activate();
    $check('activate() is safe when no IdentityFile is found', true);
    $check('GIT_SSH_COMMAND is not set when no IdentityFile was found', getenv('GIT_SSH_COMMAND') === false);
    $scoped->deactivate();
    $check('deactivate() is a safe no-op when activate found nothing', true);

    // Simulate a pre-existing GIT_SSH_COMMAND to verify it's restored.
    putenv('GIT_SSH_COMMAND=ssh -o StrictHostKeyChecking=no');
    $scoped2 = new ScopedSshConfig($git, $logger);
    $scoped2->activate();
    $scoped2->deactivate();
    $check('pre-existing GIT_SSH_COMMAND is preserved after no-op activate/deactivate', getenv('GIT_SSH_COMMAND') === 'ssh -o StrictHostKeyChecking=no');
    putenv('GIT_SSH_COMMAND'); // clean up

    // Destructor auto-cleanup
    putenv('GIT_SSH_COMMAND');
    (function () use ($git, $logger): void {
        $inner = new ScopedSshConfig($git, $logger);
        $inner->activate();
        // $inner goes out of scope here → __destruct → deactivate()
    })();
    $check('destructor fires safely even when activate found no IdentityFile', getenv('GIT_SSH_COMMAND') === false);
} else {
    echo "[info] github-safhe IdentityFile discovered — running full verification.\n";

    // --- 2. activate() sets GIT_SSH_COMMAND and creates a temp config ---

    $check('GIT_SSH_COMMAND is unset before activate()', getenv('GIT_SSH_COMMAND') === false);

    $scoped = new ScopedSshConfig($git, $logger);
    $scoped->activate();
    $check('GIT_SSH_COMMAND is set after activate()', getenv('GIT_SSH_COMMAND') !== false);
    $check('GIT_SSH_COMMAND contains -F (temp config flag)', str_contains((string) getenv('GIT_SSH_COMMAND'), '-F'));

    // --- 3. The temp config file contains the github-safhe IdentityFile ---

    $envCmd = (string) getenv('GIT_SSH_COMMAND');
    preg_match('/-F\s+"?([^"]+)"?/', $envCmd, $configMatch);
    $tempConfigPath = $configMatch[1] ?? null;

    $check('temp config path was embedded in GIT_SSH_COMMAND', $tempConfigPath !== null && is_file($tempConfigPath));

    if ($tempConfigPath !== null && is_file($tempConfigPath)) {
        $configContent = (string) file_get_contents($tempConfigPath);
        $check('temp config has Host ssh.github.com', str_contains($configContent, 'Host ssh.github.com'));
        $check('temp config also maps github.com', str_contains($configContent, 'github.com'));
        $check('temp config has IdentitiesOnly yes', str_contains($configContent, 'IdentitiesOnly yes'));
        $check('temp config has an IdentityFile directive', str_contains($configContent, 'IdentityFile'));

        // The IdentityFile in the temp config must match the one from github-safhe
        preg_match('/^identityfile\s+(\S+)/im', $probe['output'], $keyMatch);
        $ghKey = $keyMatch[1] ?? '';
        $check('temp config uses the github-safhe IdentityFile', $ghKey !== '' && str_contains($configContent, $ghKey));
        $check('temp config never contains private key material', !str_contains($configContent, 'BEGIN') && !str_contains($configContent, 'PRIVATE KEY'));
    }

    // --- 4. deactivate() restores the env and deletes the temp config ---

    $scoped->deactivate();
    $check('GIT_SSH_COMMAND is unset after deactivate()', getenv('GIT_SSH_COMMAND') === false);

    if ($tempConfigPath !== null) {
        $check('temp config file is deleted after deactivate()', !is_file($tempConfigPath));
    }

    // --- 5. Destructor auto-cleanup when the object goes out of scope ---

    putenv('GIT_SSH_COMMAND'); // clean slate
    (function () use ($git, $logger): void {
        $inner = new ScopedSshConfig($git, $logger);
        $inner->activate();
        // $inner goes out of scope here → __destruct → deactivate()
    })();
    $check('GIT_SSH_COMMAND is unset after object goes out of scope (destructor cleanup)', getenv('GIT_SSH_COMMAND') === false);
}

// --- 6. WorkspaceSourcePreparer wiring checks (source-level, no execution) ---

$preparer = (string) file_get_contents(__DIR__ . '/../lib/WorkspaceSourcePreparer.php');
$check('WorkspaceSourcePreparer activates ScopedSshConfig for SSH transports', str_contains($preparer, 'new ScopedSshConfig($this->git, $this->logger)'));
$check('WorkspaceSourcePreparer skips ScopedSshConfig for github-safhe URLs', str_contains($preparer, "!str_contains(\$transport['url'], 'github-safhe')"));
$check('WorkspaceSourcePreparer only activates for ssh transport category', str_contains($preparer, "\$transport['category'] === 'ssh'"));

// --- 7. ScopedSshConfig never writes to ~/.ssh/config ---

$scopedSource = (string) file_get_contents(__DIR__ . '/../lib/ScopedSshConfig.php');
$check('ScopedSshConfig writes only to a temp file (file_put_contents on tempConfigPath)', str_contains($scopedSource, 'file_put_contents($this->tempConfigPath'));
$check('ScopedSshConfig never writes to ~/.ssh/config path', !str_contains($scopedSource, 'file_put_contents($sshConfig'));
$check('ScopedSshConfig reads ~/.ssh/config only via Include directive', str_contains($scopedSource, 'Include '));

// Restore original env
if ($previousEnv !== false) {
    putenv('GIT_SSH_COMMAND=' . $previousEnv);
} else {
    putenv('GIT_SSH_COMMAND');
}

// Clean up test log dir
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $path = (string) $f;
    is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
}
@rmdir($logDir);

echo "\n";
if ($failures !== []) {
    fwrite(STDERR, "ScopedSshConfig test failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ScopedSshConfig test: {$passed} passed, 0 failed.\n";
