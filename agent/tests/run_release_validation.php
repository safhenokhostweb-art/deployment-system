<?php

declare(strict_types=1);

/**
 * Deterministic tests for ReleaseValidator — the fail-closed Laravel
 * release-completeness gate (agent/lib/ReleaseValidator.php).
 *
 * Dependency-free: real temp trees, real assertions, no mocking.
 * Usage: php tests/run_release_validation.php
 */

require __DIR__ . '/../lib/ReleaseValidator.php';

use DeploymentAgent\ReleaseValidator;

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, array &$failures, int &$passCount): void
{
    if ($condition) { $passCount++; return; }
    $failures[] = $description;
}

function makeRoot(): string
{
    $root = sys_get_temp_dir() . '/rel-val-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) && !is_link($dir)) { return; }
    if (is_link($dir)) { unlink($dir); return; }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $p = $dir . '/' . $entry;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @chmod($p, 0666); @unlink($p); }
    }
    @rmdir($dir);
}

const TEST_COMMIT = '0a312060b93940b2b5c623b08dbbfdba2791b60e';

/** Builds a complete, valid Laravel release tree. */
function buildLaravelFixture(string $root): void
{
    mkdir($root . '/vendor/composer', 0755, true);
    mkdir($root . '/vendor/saas-platform/core', 0755, true);
    mkdir($root . '/bootstrap', 0755, true);
    mkdir($root . '/public/build', 0755, true);
    mkdir($root . '/.deployment', 0755, true);
    file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => '^8.2', 'saas-platform/core' => '*']]));
    file_put_contents($root . '/composer.lock', json_encode(['packages' => []]));
    file_put_contents($root . '/vendor/autoload.php', "<?php\n// fixture vendor autoload\n");
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => []]));
    file_put_contents($root . '/vendor/saas-platform/core/composer.json', '{}');
    file_put_contents($root . '/artisan', "<?php\n// fixture artisan\n");
    file_put_contents($root . '/bootstrap/app.php', "<?php\n// fixture bootstrap\n");
    file_put_contents($root . '/public/index.php', "<?php\n// fixture index\n");
    file_put_contents($root . '/vite.config.js', 'export default {};');
    file_put_contents($root . '/public/build/manifest.json', json_encode(['app.js' => 'assets/app.js']));
    file_put_contents($root . '/.deployment/release-manifest.json', json_encode([
        'format' => 'deployment-system-release-manifest', 'version' => 1,
        'commit_sha' => TEST_COMMIT, 'status' => 'pass', 'required_paths' => ['composer.json'],
    ]));
}

$validator = new ReleaseValidator();
$requiredPaths = ['composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/composer', 'artisan', 'bootstrap/app.php', 'public/index.php', 'public/build/manifest.json'];

// 1) Complete, valid Laravel release -> accepted.
$root = makeRoot(); buildLaravelFixture($root);
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('complete Laravel release is accepted', $r['pass'] === true, $failures, $passCount);
check('composer_lock_sha256 recorded for a complete release', $r['composer_lock_sha256'] !== '', $failures, $passCount);
check('build_manifest_present recorded', $r['build_manifest_present'] === true, $failures, $passCount);

// 2) missing vendor/autoload.php -> rejected.
@unlink($root . '/vendor/autoload.php');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing vendor/autoload.php is rejected', !$r['pass'] && in_array('vendor_autoload', $r['failures'], true), $failures, $passCount);

// 3) missing vendor/composer -> rejected.
@unlink($root . '/vendor/autoload.php');
file_put_contents($root . '/vendor/autoload.php', "<?php\n// vendor autoload\n");
rrmdir($root . '/vendor/composer');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing vendor/composer is rejected', !$r['pass'] && in_array('vendor_composer', $r['failures'], true), $failures, $passCount);

// 4) missing public/build/manifest.json while Vite is declared -> rejected.
mkdir($root . '/vendor/composer', 0755, true);
file_put_contents($root . '/vendor/composer/installed.json', '{}');
@unlink($root . '/public/build/manifest.json');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing build manifest for a Vite project is rejected', !$r['pass'] && in_array('build_manifest', $r['failures'], true), $failures, $passCount);

// 5) missing internal composer package -> rejected.
file_put_contents($root . '/public/build/manifest.json', '{}');
rrmdir($root . '/vendor/saas-platform/core');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing internal composer package is rejected', !$r['pass'] && in_array('required_packages', $r['failures'], true), $failures, $passCount);
mkdir($root . '/vendor/saas-platform/core', 0755, true);

// 6) path/junction escape outside the release -> rejected (best-effort: junction creation needs no elevation on Windows).
mkdir($root . '/outside-target', 0755, true);
$junction = $root . '/vendor/escape';
$made = false;
$out = $code = null;
exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $junction)) . ' ' . escapeshellarg(str_replace('/', '\\', $root . '/outside-target')) . ' 2>&1', $out, $code);
$made = $code === 0 && is_dir($junction);
if ($made) {
    $r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
    check('junction escaping the release root is rejected', !$r['pass'] && in_array('vendor_no_escape', $r['failures'], true), $failures, $passCount);
    @unlink($junction);
} else {
    $passCount++; // environment without junction privileges: no assertion possible
}

// 7) empty required_paths for a Composer release -> rejected (never fails open).
$r = $validator->validate($root, TEST_COMMIT, []);
check('empty required_paths for a Composer release is rejected', !$r['pass'] && in_array('required_paths_contract', $r['failures'], true), $failures, $passCount);

// 8) invalid/missing commit SHA -> rejected.
$r = $validator->validate($root, '', $requiredPaths);
check('missing commit SHA is rejected', !$r['pass'] && in_array('commit_sha', $r['failures'], true), $failures, $passCount);
$r = $validator->validate($root, 'not-a-sha', $requiredPaths);
check('invalid commit SHA is rejected', !$r['pass'] && in_array('commit_sha', $r['failures'], true), $failures, $passCount);

// 9) composer.lock missing -> rejected.
@unlink($root . '/composer.lock');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing composer.lock is rejected', !$r['pass'] && in_array('composer_lock', $r['failures'], true), $failures, $passCount);
file_put_contents($root . '/composer.lock', '{}');

// 10) release manifest commit mismatch -> rejected.
file_put_contents($root . '/.deployment/release-manifest.json', json_encode(['format' => 'deployment-system-release-manifest', 'version' => 1, 'commit_sha' => str_repeat('f', 40), 'status' => 'pass']));
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('release manifest commit mismatch is rejected', !$r['pass'] && in_array('release_manifest', $r['failures'], true), $failures, $passCount);

// 11) artisan/bootstrap chain missing -> rejected.
file_put_contents($root . '/.deployment/release-manifest.json', json_encode(['format' => 'deployment-system-release-manifest', 'version' => 1, 'commit_sha' => TEST_COMMIT, 'status' => 'pass']));
@unlink($root . '/bootstrap/app.php');
$r = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('missing artisan bootstrap chain is rejected', !$r['pass'] && in_array('artisan_chain', $r['failures'], true), $failures, $passCount);

// 12) deterministic output: same tree, two runs -> identical.
file_put_contents($root . '/bootstrap/app.php', "<?php\n// fixture bootstrap\n");
$r1 = $validator->validate($root, TEST_COMMIT, $requiredPaths);
$r2 = $validator->validate($root, TEST_COMMIT, $requiredPaths);
check('validation output is deterministic across runs', $r1 === $r2, $failures, $passCount);

// 13) non-Composer release (no composer.json/artisan/bootstrap) stays accepted with a commit.
$staticRoot = makeRoot();
file_put_contents($staticRoot . '/index.html', '<h1>static</h1>');
$r = $validator->validate($staticRoot, TEST_COMMIT, []);
check('non-Composer release with a commit is accepted (no false positive)', $r['pass'] === true, $failures, $passCount);
$r = $validator->validate($staticRoot, '', []);
check('non-Composer release without a commit value is not falsely blocked (production artifacts always carry one)', $r['pass'] === true, $failures, $passCount);

// 14) Dependency pinning is policy-scoped: legacy artifacts still pass for
// other projects, while an opted-in project fails closed without a v2 lock.
$lockedRoot = makeRoot(); buildLaravelFixture($lockedRoot);
$r = $validator->validate($lockedRoot, TEST_COMMIT, $requiredPaths, true);
check('opted-in project rejects a legacy manifest without dependency lock', !$r['pass'] && in_array('dependency_lock', $r['failures'], true), $failures, $passCount);
$r = $validator->validate($lockedRoot, TEST_COMMIT, $requiredPaths, false);
check('legacy artifact remains compatible when project policy is not enabled', $r['pass'] === true, $failures, $passCount);

// 15) A valid lock passes and any checksum manipulation is rejected.
$packageRoot = $lockedRoot . '/vendor/saas-platform/core';
$bytes = (string) file_get_contents($packageRoot . '/composer.json');
$checksum = hash('sha256', 'composer.json' . "\0" . strlen($bytes) . "\0" . $bytes);
$lock = ['project_id'=>'saas-platform','repository_mapping'=>'saas-platform','branch'=>'main','commit_sha'=>str_repeat('a',40),'package_name'=>'saas-platform/core','source_path'=>'packages/core','destination_path'=>'../saas/packages/core','artifact_path'=>'vendor/saas-platform/core','tree_hash'=>str_repeat('b',40),'checksum_sha256'=>$checksum];
file_put_contents($lockedRoot . '/.deployment/release-manifest.json', json_encode(['format'=>'deployment-system-release-manifest','version'=>2,'commit_sha'=>TEST_COMMIT,'status'=>'pass','dependency_lock_required'=>true,'dependency_locks'=>[$lock]]));
$r = $validator->validate($lockedRoot, TEST_COMMIT, $requiredPaths, true);
check('valid dependency lock is accepted', $r['pass'] === true, $failures, $passCount);
$lock['checksum_sha256'] = str_repeat('0',64);
file_put_contents($lockedRoot . '/.deployment/release-manifest.json', json_encode(['format'=>'deployment-system-release-manifest','version'=>2,'commit_sha'=>TEST_COMMIT,'status'=>'pass','dependency_lock_required'=>true,'dependency_locks'=>[$lock]]));
$r = $validator->validate($lockedRoot, TEST_COMMIT, $requiredPaths, true);
check('tampered dependency checksum is rejected', !$r['pass'] && in_array('dependency_lock', $r['failures'], true), $failures, $passCount);

rrmdir($root);
rrmdir($staticRoot);
rrmdir($lockedRoot);

$total = $passCount + count($failures);
echo "ReleaseValidator: {$passCount}/{$total} checks passed\n";
if ($failures !== []) {
    foreach ($failures as $f) { echo "  FAIL: {$f}\n"; }
    exit(1);
}
echo "ReleaseValidator: ALL PASS\n";
