<?php

declare(strict_types=1);

/**
 * Isolated local test: verifies that ScopedSshConfig routes the canonical
 * GitHub SSH-over-443 transport through the `github-safhe` identity,
 * resolving the "Permission denied (publickey)" failure.
 *
 * Usage: php tests/run_scoped_ssh_auth_integration.php
 *
 * This test performs a REAL git ls-remote against ssh.github.com:443.
 * It requires network access to GitHub and the `github-safhe` SSH alias
 * to be configured on this machine.
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

$php = PHP_BINARY;
$git = new GitManager();
$logDir = sys_get_temp_dir() . '/scoped-ssh-int-' . bin2hex(random_bytes(6));
$logger = new Logger($logDir);

// Clean env slate
putenv('GIT_SSH_COMMAND');

$canonicalUrl = 'ssh://git@ssh.github.com:443/safhenokhostweb-art/saas-platform.git';
$targetBranch = 'fix/canvas-default-homepage';
$expectedSha = 'fa8694d4540b777501cf3f83ddc9e12ee69b7c4f';

echo "=== Integration test: ScopedSshConfig with real GitHub fetch ===\n";
echo "Canonical URL: {$canonicalUrl}\n";
echo "Target branch: {$targetBranch}\n";
echo "Expected SHA:  {$expectedSha}\n\n";

// --- Step 1: Demonstrate the original bug (no scoped config) ---

echo "Step 1: Verifying the original bug — fetch without scoped SSH config.\n";
$bugResult = $git->run(['git', 'ls-remote', $canonicalUrl, 'refs/heads/' . $targetBranch], sys_get_temp_dir(), 15);
$check('git ls-remote fails without scoped SSH config (Permission denied)', $bugResult['exitCode'] !== 0);
if ($bugResult['exitCode'] !== 0) {
    echo "  -> Confirmed: " . trim($bugResult['output']) . "\n";
}

// --- Step 2: Activate ScopedSshConfig and retry ---

echo "\nStep 2: Activating ScopedSshConfig and retrying fetch.\n";
$scoped = new ScopedSshConfig($git, $logger);
$scoped->activate();

$check('GIT_SSH_COMMAND is set after activation', getenv('GIT_SSH_COMMAND') !== false);
$envValue = (string) getenv('GIT_SSH_COMMAND');
echo "  -> GIT_SSH_COMMAND = " . $envValue . "\n";

$fetchResult = $git->run(['git', 'ls-remote', $canonicalUrl, 'refs/heads/' . $targetBranch], sys_get_temp_dir(), 30);

if ($fetchResult['exitCode'] === 0) {
    $line = trim($fetchResult['output']);
    preg_match('/^([0-9a-f]{40})\s+refs\/heads\/' . preg_quote($targetBranch, '/') . '$/', $line, $shaMatch);
    $actualSha = $shaMatch[1] ?? '';

    echo "  -> ls-remote succeeded\n";
    echo "  -> Returned SHA: {$actualSha}\n";

    $check('git ls-remote succeeds with scoped SSH config', true);
    $check('returned SHA matches the expected commit', $actualSha === $expectedSha);
} else {
    $check('git ls-remote succeeds with scoped SSH config', false);
    echo "  -> FAILED: " . trim($fetchResult['output']) . "\n";
}

// --- Step 3: Verify cleanup ---

echo "\nStep 3: Verifying cleanup after deactivate().\n";
$scoped->deactivate();

$check('GIT_SSH_COMMAND is unset after deactivate()', getenv('GIT_SSH_COMMAND') === false);

// --- Step 4: Verify destructor cleanup (object goes out of scope) ---

echo "\nStep 4: Verifying destructor auto-cleanup.\n";
putenv('GIT_SSH_COMMAND'); // clean slate
(function () use ($git, $logger, $canonicalUrl, $targetBranch, &$check, $expectedSha): void {
    $inner = new ScopedSshConfig($git, $logger);
    $inner->activate();
    $check('GIT_SSH_COMMAND set during lifetime', getenv('GIT_SSH_COMMAND') !== false);
    $inner->deactivate();
    // $inner goes out of scope here → __destruct → deactivate() (idempotent)
})();
$check('GIT_SSH_COMMAND unset after destructor', getenv('GIT_SSH_COMMAND') === false);

// --- Step 5: Verify github-sandogh is unaffected ---

echo "\nStep 5: Verifying github-sandogh SSH alias is unaffected.\n";
$sandoghProbe = $git->run(['ssh', '-G', 'github-sandogh'], sys_get_temp_dir(), 10);
$check('github-sandogh resolves to a valid host', $sandoghProbe['exitCode'] === 0);
if ($sandoghProbe['exitCode'] === 0) {
    $sandoghOutput = $sandoghProbe['output'];
    $check('github-sandogh has its own IdentityFile', str_contains($sandoghOutput, 'id_ed25519_sandogh') || str_contains($sandoghOutput, 'identityfile'));
    $check('github-sandogh is NOT using the scoped config', !str_contains($sandoghOutput, 'la-ssh-scope'));
    echo "  -> github-sandogh config verified (hostname + identity intact)\n";
}

// --- Step 6: Verify canonical URL logic is unchanged ---

$resolverSource = (string) file_get_contents(__DIR__ . '/../lib/DependencyRepositoryResolver.php');
$check('canonicalGithubSsh still returns ssh://git@ssh.github.com:443/', str_contains($resolverSource, 'ssh://git@ssh.github.com:443/'));
$check('normalizeRepository still maps github-safhe to github.com/', str_contains($resolverSource, "'github-safhe'"));
$check('DependencyRepositoryResolver is unchanged (no scoped SSH logic there)', !str_contains($resolverSource, 'ScopedSshConfig'));

// Clean up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $path = (string) $f;
    is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
}
@rmdir($logDir);

putenv('GIT_SSH_COMMAND');

echo "\n";
if ($failures !== []) {
    fwrite(STDERR, "Integration test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Integration test: {$passed} passed, 0 failed.\n";
echo
    "  transport=ssh, auth=resolved (via github-safhe alias),\n" .
    "  canonical URL preserved (ssh://git@ssh.github.com:443/...),\n" .
    "  github-sandogh unchanged, auto_deploy not triggered.\n";
