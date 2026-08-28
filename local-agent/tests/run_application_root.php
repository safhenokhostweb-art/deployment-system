<?php

declare(strict_types=1);

require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/ApplicationRoot.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';

use LocalAgent\ApplicationRoot;
use LocalAgent\ArtifactBuilder;
use LocalAgent\LocalBuildFailedException;

$root = sys_get_temp_dir() . '/application-root-' . bin2hex(random_bytes(5));
mkdir("$root/service/vendor", 0700, true);
mkdir("$root/service/bootstrap", 0700, true);
mkdir("$root/service/public", 0700, true);
file_put_contents("$root/service/composer.json", '{}');
file_put_contents("$root/service/artisan", '<?php');
file_put_contents("$root/service/vendor/autoload.php", '<?php');
file_put_contents("$root/service/bootstrap/app.php", '<?php');
file_put_contents("$root/service/public/index.php", '<?php');

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $value) use (&$passed, &$failed): void {
    if ($value) { $passed++; echo "PASS: $label\n"; return; }
    $failed++; echo "FAIL: $label\n";
};

try {
    $application = ApplicationRoot::resolve($root, 'service');
    $archive = (new ArtifactBuilder())->build($application, $root, 1700000000, ['ranComposer' => true, 'ranNpmBuild' => false]);
    $zip = new ZipArchive();
    $zip->open($archive['path']);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
    $zip->close();
    $check('subdirectory application root is packaged without service wrapper', in_array('artisan', $names, true) && in_array('vendor/autoload.php', $names, true) && !in_array('service/artisan', $names, true));
    $check('Laravel runtime requirements are emitted', $archive['requiredPaths'] === ['composer.json', 'vendor/autoload.php', 'artisan', 'bootstrap/app.php', 'public/index.php', '.deployment/release-manifest.json']);
    @unlink($archive['path']);

    foreach (['../service', '/absolute', 'C:/absolute'] as $invalid) {
        try { ApplicationRoot::resolve($root, $invalid); $check("invalid root $invalid rejected", false); }
        catch (LocalBuildFailedException $e) { $check("invalid root $invalid rejected", $e->failureCode === 'invalid_application_root'); }
    }
    @unlink("$root/service/vendor/autoload.php");
    try { (new ArtifactBuilder())->build($application, $root, 1700000000, ['ranComposer' => true, 'ranNpmBuild' => false]); $check('missing vendor fails locally', false); }
    catch (LocalBuildFailedException $e) { $check('missing vendor fails locally', $e->failureCode === 'artifact_missing_vendor'); }
} finally {
    LocalAgent\SafeFilesystem::recursiveDelete($root);
}

echo "RESULT: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
