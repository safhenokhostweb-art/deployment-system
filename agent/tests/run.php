<?php

declare(strict_types=1);

/**
 * Phase 4-H: dependency-free test harness for the Agent's Local Discovery
 * code. The agent/ directory has never had Composer/PHPUnit (see
 * agent/README.md's "no Composer install needed on production" claim) —
 * adding a dev-only test dependency here would be new, unnecessary
 * surface for a directory whose whole identity is "zero dependencies".
 * This script builds real temp-directory fixtures, calls the scanner/
 * sanitizer directly, and asserts on the output.
 *
 * Usage: php tests/run.php
 */

require __DIR__ . '/../lib/RemoteUrlSanitizer.php';
require __DIR__ . '/../lib/WorkspaceScanner.php';
require __DIR__ . '/../lib/OperationRegistry.php';
require __DIR__ . '/../lib/HealthCollector.php';

use DeploymentAgent\HealthCollector;
use DeploymentAgent\OperationRegistry;
use DeploymentAgent\RemoteUrlSanitizer;
use DeploymentAgent\WorkspaceScanner;

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
    $root = sys_get_temp_dir() . '/discovery-agent-test-' . bin2hex(random_bytes(6));
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
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function writeGitFixture(string $projectPath, string $remoteUrl, string $branch, string $commitHash): void
{
    mkdir($projectPath . '/.git/refs/heads', 0777, true);
    file_put_contents($projectPath . '/.git/config', <<<GITCONFIG
        [core]
        \trepositoryformatversion = 0
        [remote "origin"]
        \turl = {$remoteUrl}
        \tfetch = +refs/heads/*:refs/remotes/origin/*
        GITCONFIG
    );
    file_put_contents($projectPath . '/.git/HEAD', "ref: refs/heads/{$branch}\n");
    file_put_contents($projectPath . '/.git/refs/heads/' . $branch, $commitHash . "\n");
}

// --- Fixture: Laravel project with a credential-embedded remote URL ---
$workspace = makeWorkspace();
$laravelPath = $workspace . '/saas-platform';
mkdir($laravelPath . '/app', 0777, true);
mkdir($laravelPath . '/bootstrap', 0777, true);
mkdir($laravelPath . '/config', 0777, true);
file_put_contents($laravelPath . '/artisan', "#!/usr/bin/env php\n");
file_put_contents($laravelPath . '/composer.json', json_encode([
    'require' => ['laravel/framework' => '^11.0', 'php' => '^8.2'],
], JSON_UNESCAPED_SLASHES));
writeGitFixture($laravelPath, 'https://ghp_secrettoken123456789012345:x-oauth-basic@github.com/acme/saas-platform.git', 'main', str_repeat('a', 40));

// --- Fixture: WordPress project ---
$wpPath = $workspace . '/marketing-site';
mkdir($wpPath . '/wp-content', 0777, true);
mkdir($wpPath . '/wp-includes', 0777, true);
file_put_contents($wpPath . '/wp-config.php', "<?php\ndefine('DB_PASSWORD', 'super-secret-should-never-be-read');\n");
file_put_contents($wpPath . '/wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n");
writeGitFixture($wpPath, 'git@github.com:acme/marketing-site.git', 'main', str_repeat('b', 40));

// --- Fixture: Node project ---
$nodePath = $workspace . '/customer-portal';
mkdir($nodePath, 0777, true);
file_put_contents($nodePath . '/package.json', json_encode(['name' => 'customer-portal', 'version' => '2.3.1']));

// --- Fixture: unrecognizable directory ---
$unknownPath = $workspace . '/scratch-notes';
mkdir($unknownPath, 0777, true);
file_put_contents($unknownPath . '/notes.txt', 'not a project');

$scanner = new WorkspaceScanner();
$results = $scanner->scan($workspace);
$byName = [];
foreach ($results as $result) {
    $byName[$result['name']] = $result;
}

check('scan finds all four fixture directories', count($results) === 4, $failures, $passCount);

// Laravel detection + version + git + sanitized URL
check('detects saas-platform as laravel', ($byName['saas-platform']['type'] ?? null) === 'laravel', $failures, $passCount);
check('reports laravel/framework constraint as framework_version', ($byName['saas-platform']['framework_version'] ?? null) === '^11.0', $failures, $passCount);
check('sanitizes the embedded credential out of the remote URL', ($byName['saas-platform']['git']['remote_url'] ?? null) === 'https://github.com/acme/saas-platform.git', $failures, $passCount);
check('remote URL sanitization never leaks the token substring', !str_contains(json_encode($byName['saas-platform']), 'ghp_secrettoken'), $failures, $passCount);
check('resolves provider as github', ($byName['saas-platform']['git']['provider'] ?? null) === 'github', $failures, $passCount);
check('resolves repository as acme/saas-platform', ($byName['saas-platform']['git']['repository'] ?? null) === 'acme/saas-platform', $failures, $passCount);
check('resolves branch as main', ($byName['saas-platform']['git']['branch'] ?? null) === 'main', $failures, $passCount);
check('resolves commit hash from the loose ref file', ($byName['saas-platform']['git']['commit_hash'] ?? null) === str_repeat('a', 40), $failures, $passCount);

// WordPress detection + version + SCP-style remote + secret never read
check('detects marketing-site as wordpress', ($byName['marketing-site']['type'] ?? null) === 'wordpress', $failures, $passCount);
check('reports $wp_version from wp-includes/version.php', ($byName['marketing-site']['framework_version'] ?? null) === '6.5.2', $failures, $passCount);
check('resolves SCP-style remote to owner/repo', ($byName['marketing-site']['git']['repository'] ?? null) === 'acme/marketing-site', $failures, $passCount);
check('never reads wp-config.php (its DB password never appears anywhere in the result)', !str_contains(json_encode($byName['marketing-site']), 'super-secret-should-never-be-read'), $failures, $passCount);

// Node detection + version
check('detects customer-portal as node', ($byName['customer-portal']['type'] ?? null) === 'node', $failures, $passCount);
check('reports package.json version as framework_version', ($byName['customer-portal']['framework_version'] ?? null) === '2.3.1', $failures, $passCount);
check(
    'node project with no .git reports null git info',
    array_key_exists('git', $byName['customer-portal']) && $byName['customer-portal']['git'] === null,
    $failures,
    $passCount,
);

// Unknown/unrecognized directory
check('unrecognizable directory reports type unknown', ($byName['scratch-notes']['type'] ?? null) === 'unknown', $failures, $passCount);

// --- RemoteUrlSanitizer direct unit checks ---
check(
    'sanitizer strips user:pass@ from an https remote URL',
    RemoteUrlSanitizer::sanitize('https://user:password@github.com/owner/repo.git') === 'https://github.com/owner/repo.git',
    $failures,
    $passCount,
);
check(
    'sanitizer leaves a credential-free URL unchanged',
    RemoteUrlSanitizer::sanitize('https://gitlab.com/owner/repo.git') === 'https://gitlab.com/owner/repo.git',
    $failures,
    $passCount,
);
check(
    'sanitizer leaves an SCP-style URL as-is (no userinfo to strip)',
    RemoteUrlSanitizer::sanitize('git@bitbucket.org:owner/repo.git') === 'git@bitbucket.org:owner/repo.git',
    $failures,
    $passCount,
);
check(
    'provider() recognizes gitlab host',
    RemoteUrlSanitizer::provider('https://gitlab.com/owner/repo.git') === 'gitlab',
    $failures,
    $passCount,
);

rrmdir($workspace);

// --- Scanning a nonexistent workspace_root never errors, returns empty ---
check('scanning a nonexistent root returns an empty array, not an error', (new WorkspaceScanner())->scan('/no/such/path/exists') === [], $failures, $passCount);

// --- Phase 4-K: OperationRegistry — the agent's own copy of the allowed
// operations list. Task 4/12: a type not in this list must never resolve
// to a command, and the registry must never be tricked into returning one
// for an arbitrary/malicious string. ---
check('cache_clear is an allowed, Laravel-group operation', OperationRegistry::isAllowed('cache_clear') && OperationRegistry::requiresProject('cache_clear'), $failures, $passCount);
check('disk_check is an allowed, server-group operation needing no project', OperationRegistry::isAllowed('disk_check') && !OperationRegistry::requiresProject('disk_check'), $failures, $passCount);
check('an arbitrary/malicious type string is never allowed', !OperationRegistry::isAllowed('rm -rf /'), $failures, $passCount);
check('an unknown type resolves to no command at all', OperationRegistry::commandFor('rm -rf /') === null, $failures, $passCount);
check('cache_clear resolves to the expected artisan command template', OperationRegistry::commandFor('cache_clear') === '{php_binary} artisan cache:clear', $failures, $passCount);

// --- Phase 4-K: HealthCollector — best-effort, must never throw and must
// always return the expected shape (nullable floats), regardless of
// platform support for any individual metric. ---
$health = (new HealthCollector())->collect();
check('HealthCollector::collect() returns exactly the three expected keys', array_keys($health) === ['cpu_load_percent', 'memory_usage_percent', 'disk_usage_percent'], $failures, $passCount);
check('every HealthCollector value is either null or numeric (never a string/array)', collect_is_null_or_numeric($health), $failures, $passCount);

function collect_is_null_or_numeric(array $health): bool
{
    foreach ($health as $value) {
        if ($value !== null && !is_float($value) && !is_int($value)) {
            return false;
        }
    }

    return true;
}

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
